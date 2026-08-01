<?php
// ============================================================
// services/IndexerService.php — NoteNest AI Platform
// File Content Indexing Service (Jina + Qdrant)
// ============================================================

namespace Services;

use Embeddings\JinaEmbeddingsClient;
use Vector\QdrantClient;
use Services\ExtractorService;
use mysqli;

class IndexerService {
    private mysqli $conn;
    private JinaEmbeddingsClient $jinaClient;
    private QdrantClient $qdrantClient;
    private ExtractorService $extractor;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->jinaClient = new JinaEmbeddingsClient();
        $this->qdrantClient = new QdrantClient();
        $this->extractor = new ExtractorService();
    }

    /**
     * Indexes a single file: extracts text, chunks it, generates embeddings,
     * stores inside Qdrant vector DB and logs metadata inside MySQL.
     */
    public function indexFile(int $fileId): bool {
        // Track/Initialize job status
        $this->updateJobStatus($fileId, 'processing');

        // 1. Fetch file details
        $stmt = $this->conn->prepare("SELECT owner_id, course_id, folder_id, name, file_path FROM files WHERE id = ?");
        if (!$stmt) {
            $this->logJobFailure($fileId, "MySQL statement prepare failed: " . $this->conn->error);
            return false;
        }
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $stmt->bind_result($userId, $courseId, $folderId, $name, $filePath);
        if (!$stmt->fetch()) {
            $stmt->close();
            $this->logJobFailure($fileId, "File with ID #{$fileId} not found in database.");
            return false;
        }
        $stmt->close();

        // Resolve course_id from folders if directly null
        if (empty($courseId) && !empty($folderId)) {
            $fstmt = $this->conn->prepare("SELECT course_id FROM folders WHERE id = ?");
            if ($fstmt) {
                $fstmt->bind_param('i', $folderId);
                $fstmt->execute();
                $fstmt->bind_result($fCourseId);
                if ($fstmt->fetch()) {
                    $courseId = $fCourseId;
                }
                $fstmt->close();
            }
        }

        // Resolve absolute path
        $absPath = $filePath;
        if (!file_exists($absPath)) {
            $absPath = __DIR__ . '/../' . $filePath;
        }
        if (!file_exists($absPath)) {
            $this->logJobFailure($fileId, "Physical file not found on disk at: {$filePath}");
            return false;
        }

        // 2. Extract text
        $text = $this->extractor->extractText($absPath);
        mb_substitute_character('none');
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $cleanText = trim(preg_replace('/\s+/', ' ', $text));
        
        if (empty($cleanText)) {
            $this->logJobFailure($fileId, "Extracted text is empty or file format is unsupported.");
            return false;
        }

        // 3. Chunk text (800 characters with 150 overlap)
        $chunkSize = 800;
        $overlap = 150;
        $chunks = [];
        $textLen = mb_strlen($cleanText);
        
        $start = 0;
        while ($start < $textLen) {
            $content = mb_substr($cleanText, $start, $chunkSize);
            if (trim($content)) {
                $chunks[] = trim($content);
            }
            $start += ($chunkSize - $overlap);
            if ($chunkSize - $overlap <= 0) break; // Avoid infinite loop
        }

        if (empty($chunks)) {
            $this->logJobFailure($fileId, "Failed to segment text into chunks.");
            return false;
        }

        // 4. Clear existing indexes from Qdrant and MySQL
        $this->qdrantClient->deletePointsByFile($fileId);
        
        $del = $this->conn->prepare("DELETE FROM document_chunks WHERE file_id = ?");
        $del->bind_param('i', $fileId);
        $del->execute();
        $del->close();

        $delIdx = $this->conn->prepare("DELETE FROM vector_index WHERE file_id = ?");
        $delIdx->bind_param('i', $fileId);
        $delIdx->execute();
        $delIdx->close();

        // 5. Fetch embeddings from Jina AI in batches (batch size 16 to avoid limits)
        $batchSize = 16;
        $embeddings = [];
        $chunkBatches = array_chunk($chunks, $batchSize);
        foreach ($chunkBatches as $batch) {
            try {
                $vectors = $this->jinaClient->getEmbeddings($batch);
                if (!empty($vectors)) {
                    $embeddings = array_merge($embeddings, $vectors);
                }
            } catch (\Throwable $e) {
                error_log("Jina AI Embeddings warning: " . $e->getMessage());
            }
        }


        // Get optional topic_id
        $topicId = null;
        $tq = $this->conn->prepare("SELECT topic_id FROM file_course_tags WHERE file_id = ? LIMIT 1");
        if ($tq) {
            $tq->bind_param('i', $fileId); // ← Fix: bind $fileId before execute
            $tq->execute();
            $tq->bind_result($tId);
            if ($tq->fetch()) {
                $topicId = $tId;
            }
            $tq->close();
        }


        // 6. Insert points into Qdrant and metadata into MySQL
        $qdrantPoints = [];
        $mysqlChunks = [];

        $insChunk = $this->conn->prepare("
            INSERT INTO document_chunks (user_id, course_id, folder_id, topic_id, file_id, chunk_index, content, page_number, embedding_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$insChunk) {
            $this->logJobFailure($fileId, "Failed to prepare MySQL document_chunks query: " . $this->conn->error);
            return false;
        }

        $insIdx = $this->conn->prepare("
            INSERT INTO vector_index (embedding_id, file_id, chunk_id, user_id, course_id, folder_id, topic_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $chunkIndex = 0;
        foreach ($chunks as $idx => $content) {
            $pageNumber = floor(($chunkIndex * ($chunkSize - $overlap)) / 2000) + 1;
            $embeddingId = $this->generateUuid("chunk_{$fileId}_{$chunkIndex}");
            
            // Execute MySQL Insert document_chunks
            $insChunk->bind_param('iiiiiisis', $userId, $courseId, $folderId, $topicId, $fileId, $chunkIndex, $content, $pageNumber, $embeddingId);
            $insChunk->execute();
            $insertedChunkId = $insChunk->insert_id;

            // Execute MySQL Insert vector_index
            if ($insIdx) {
                $insIdx->bind_param('siiiiii', $embeddingId, $fileId, $insertedChunkId, $userId, $courseId, $folderId, $topicId);
                $insIdx->execute();
            }

            // Build Qdrant Point (only if vector generated)
            if (!empty($embeddings[$idx])) {
                $qdrantPoints[] = [
                    'id' => $embeddingId,
                    'vector' => $embeddings[$idx],
                    'payload' => [
                        'user_id' => $userId,
                        'file_id' => $fileId,
                        'course_id' => $courseId ?: 0,
                        'folder_id' => $folderId ?: 0,
                        'topic_id' => $topicId ?: 0,
                        'page_number' => $pageNumber,
                        'chunk_number' => $chunkIndex,
                        'content' => $content
                    ]
                ];
            }

            $chunkIndex++;
        }
        $insChunk->close();
        if ($insIdx) {
            $insIdx->close();
        }

        // Upsert to Qdrant Cloud
        $ok = $this->qdrantClient->upsertPoints($qdrantPoints);
        if (!$ok) {
            $this->logJobFailure($fileId, "Failed to upload vector points to Qdrant Cloud.");
            return false;
        }

        $this->updateJobStatus($fileId, 'completed');
        return true;
    }

    private function generateUuid(string $data): string {
        $hash = md5($data);
        return sprintf('%08s-%04s-%04s-%04s-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    private function updateJobStatus(int $fileId, string $status): void {
        $stmt = $this->conn->prepare("INSERT INTO embedding_jobs (file_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = ?");
        if ($stmt) {
            $stmt->bind_param('iss', $fileId, $status, $status);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function logJobFailure(int $fileId, string $errorMessage): void {
        $status = 'failed';
        $stmt = $this->conn->prepare("
            INSERT INTO embedding_jobs (file_id, status, error_message, attempts) 
            VALUES (?, ?, ?, 1) 
            ON DUPLICATE KEY UPDATE status = ?, error_message = ?, attempts = attempts + 1
        ");
        if ($stmt) {
            $stmt->bind_param('issss', $fileId, $status, $errorMessage, $status, $errorMessage);
            $stmt->execute();
            $stmt->close();
        }
        error_log("IndexerService Job Failure (File #{$fileId}): " . $errorMessage);
    }
}
