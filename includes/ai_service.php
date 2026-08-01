<?php
// ============================================================
// includes/ai_service.php
// NoteNest AI Platform — Core AI Service Layer
// Powered by Groq AI (OpenAI-compatible, Llama 3.3-70B)
// ============================================================

/**
 * Core cURL function to call Groq chat completions endpoint.
 *
 * @param  array  $messages     OpenAI-style messages [['role'=>'user|assistant|system','content'=>'...']]
 * @param  string $model        GROK_MODEL | GROK_MODEL_PRO
 * @param  float  $temperature  Creativity (0.0 = precise, 1.0 = creative)
 * @param  int    $maxTokens    Max output tokens
 * @return array  ['success'=>bool, 'text'=>string, 'tokens'=>int, 'error'=>string]
 */
function grokRequest(array $messages, string $model = '', float $temperature = 0.7, int $maxTokens = 0): array {
    $model     = $model     ?: GROQ_MODEL;
    $maxTokens = $maxTokens ?: AI_MAX_TOKENS;

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
    ];

    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
    ]);


    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => 'cURL error: ' . $curlError];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? ('Unknown API error (HTTP ' . $httpCode . '). Response: ' . substr($response, 0, 300));
        return ['success' => false, 'text' => '', 'tokens' => 0, 'error' => $errMsg];
    }

    $text   = $data['choices'][0]['message']['content'] ?? '';
    $tokens = $data['usage']['total_tokens'] ?? 0;

    return ['success' => true, 'text' => trim($text), 'tokens' => $tokens, 'error' => ''];
}

/**
 * Backward-compatible wrapper: accepts legacy message format
 * (['role'=>'user|model', 'text'=>'...']) and converts to Groq/OpenAI format.
 * Used by pages that call geminiRequest() directly.
 */
function geminiRequest(array $messages, string $systemPrompt = '', float $temperature = 0.7): array {
    $openAiMessages = [];

    // Add system prompt first
    if (!empty($systemPrompt)) {
        $openAiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
    }

    // Convert legacy role names to OpenAI/Groq style
    foreach ($messages as $msg) {
        $role    = ($msg['role'] === 'model') ? 'assistant' : ($msg['role'] ?? 'user');
        $content = $msg['text'] ?? $msg['content'] ?? '';
        $openAiMessages[] = ['role' => $role, 'content' => $content];
    }

    return grokRequest($openAiMessages, GROQ_MODEL, $temperature);
}


// ============================================================
// PUBLIC FUNCTIONS  (all signatures unchanged)
// ============================================================

// ── 1. AI TUTOR CHAT ─────────────────────────────────────────
/**
 * Sends a student question to the AI Tutor and gets an academic answer.
 *
 * @param  string $userMessage   The student's current question
 * @param  array  $history       Previous messages [['role'=>'user|assistant','text'=>'...']]
 * @param  string $courseContext Optional course name for contextual answers
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiChat(string $userMessage, array $history = [], string $courseContext = ''): array {
    $systemContent = "You are an expert academic tutor assistant for university students powered by Groq AI (Llama 3.3).
Your role is to:
- Explain concepts clearly with examples
- Break down complex topics step by step
- Encourage critical thinking by asking follow-up questions
- Provide study tips and mnemonics when helpful
- Be supportive, patient, and encouraging
- Use markdown formatting (bold, bullet points, code blocks) for clarity
- Keep responses focused and academic in nature";

    if ($courseContext) {
        $systemContent .= "\n\nThe student is currently studying: **{$courseContext}**. Tailor your explanations to this subject when relevant.";
    }

    $messages = [['role' => 'system', 'content' => $systemContent]];

    // Add conversation history
    foreach ($history as $msg) {
        $role     = ($msg['role'] === 'model') ? 'assistant' : ($msg['role'] ?? 'user');
        $content  = $msg['text'] ?? $msg['content'] ?? '';
        $messages[] = ['role' => $role, 'content' => $content];
    }

    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return grokRequest($messages, GROQ_MODEL, 0.6);
}


// ── 2. AI QUESTION GENERATOR ─────────────────────────────────
/**
 * Generates exam questions from a given study material/context.
 *
 * @param  string $studyContent   The text/topic to generate questions from
 * @param  string $questionTypes  E.g. "5 MCQ, 3 short answer, 2 essay"
 * @param  string $difficulty     "easy" | "medium" | "hard"
 * @return array  ['success', 'text', 'questions_json', 'tokens', 'error']
 */
function aiGenerateQuestions(string $studyContent, string $questionTypes = '5 MCQ and 5 short answer', string $difficulty = 'medium'): array {
    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are NoteNest AI Tutor. You are a strict academic exam setter. Generate exam questions strictly and ONLY from the provided study material content. Do NOT use your own pre-trained knowledge, general knowledge, or guess. Do NOT generate questions about any topic not explicitly detailed in the study material. Return ONLY raw JSON, no markdown fences, no explanation.',
        ],
        [
            'role'    => 'user',
            'content' => "Based on the following study material, generate exactly {$questionTypes} questions at {$difficulty} difficulty level.
            
Ensure you support requested question types from this list: MCQ, Short Question, Essay, True/False, Fill in the Blank, Coding Question, Scenario Question.

STUDY MATERIAL:
{$studyContent}

Return ONLY a valid JSON array. Format elements depending on type:
For type 'mcq':
{
  \"type\": \"mcq\",
  \"question\": \"Question text here?\",
  \"options\": [\"A. Option 1\", \"B. Option 2\", \"C. Option 3\", \"D. Option 4\"],
  \"correct_answer\": \"A\",
  \"explanation\": \"Why this is correct\"
}
For type 'short_answer' (Short Question):
{
  \"type\": \"short_answer\",
  \"question\": \"Question text here?\",
  \"expected_keywords\": [\"keyword1\", \"keyword2\"],
  \"model_answer\": \"The ideal answer\"
}
For type 'essay':
{
  \"type\": \"essay\",
  \"question\": \"Question text here?\",
  \"expected_keywords\": [\"keyword1\", \"keyword2\"],
  \"model_answer\": \"Detailed outline/model response text\"
}
For type 'true_false':
{
  \"type\": \"true_false\",
  \"question\": \"Question text here?\",
  \"options\": [\"True\", \"False\"],
  \"correct_answer\": \"True\",
  \"explanation\": \"Why this is correct\"
}
For type 'fill_in_the_blank':
{
  \"type\": \"fill_in_the_blank\",
  \"question\": \"Question text here with a [blank]?\",
  \"correct_answer\": \"answer\",
  \"explanation\": \"Why this is correct\"
}
For type 'coding':
{
  \"type\": \"coding\",
  \"question\": \"Coding question prompt?\",
  \"expected_keywords\": [\"keyword1\", \"keyword2\"],
  \"model_answer\": \"Reference code solution\"
}
For type 'scenario':
{
  \"type\": \"scenario\",
  \"question\": \"Scenario prompt?\",
  \"expected_keywords\": [\"keyword1\", \"keyword2\"],
  \"model_answer\": \"Reference analysis response\"
}",
        ],
    ];

    $result = grokRequest($messages, GROQ_MODEL, 0.3, 4000);

    if ($result['success']) {
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result['questions_json'] = $jsonText;
        } else {
            $result['success']       = false;
            $result['error']         = 'AI returned invalid JSON. Raw: ' . substr($result['text'], 0, 200);
            $result['questions_json'] = '[]';
        }
    }

    return $result;
}


// ── 3. AI ANSWER EVALUATOR ───────────────────────────────────
/**
 * Evaluates student answers against AI-generated questions.
 *
 * @param  string $questionsJson  JSON string of generated questions
 * @param  array  $studentAnswers ['q_index' => 'student answer text']
 * @return array  ['success', 'text', 'score', 'feedback_json', 'weak_areas', 'grade', 'tokens', 'error']
 */
function aiEvaluateAnswers(string $questionsJson, array $studentAnswers): array {
    $answersFormatted = json_encode($studentAnswers, JSON_PRETTY_PRINT);

    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are a strict but fair academic evaluator. Evaluate student answers objectively and provide constructive feedback. Always respond with ONLY valid JSON, no markdown.',
        ],
        [
            'role'    => 'user',
            'content' => "Evaluate the following student answers against the given questions.

QUESTIONS JSON:
{$questionsJson}

STUDENT ANSWERS (indexed by question number, 0-based):
{$answersFormatted}

Return ONLY a valid JSON object:
{
  \"total_score\": 85,
  \"max_score\": 100,
  \"grade\": \"B+\",
  \"overall_feedback\": \"General performance summary\",
  \"weak_areas\": [\"Topic 1\", \"Topic 2\"],
  \"study_suggestions\": [\"Suggestion 1\", \"Suggestion 2\"],
  \"question_results\": [
    {
      \"question_no\": 1,
      \"student_answer\": \"What student wrote\",
      \"is_correct\": true,
      \"marks_earned\": 10,
      \"marks_possible\": 10,
      \"feedback\": \"Detailed feedback\"
    }
  ]
}",
        ],
    ];

    // Use the more powerful model for evaluation
    $result = grokRequest($messages, GROQ_MODEL_PRO, 0.3, 4000);

    if ($result['success']) {
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        $jsonText = trim($jsonText);
        $decoded  = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result['score']         = $decoded['total_score'] ?? 0;
            $result['feedback_json'] = $jsonText;
            $result['weak_areas']    = implode(', ', $decoded['weak_areas'] ?? []);
            $result['grade']         = $decoded['grade'] ?? 'N/A';
        } else {
            $result['success'] = false;
            $result['error']   = 'AI returned invalid evaluation JSON.';
        }
    }

    return $result;
}


// ── 4. AI CONTENT SUMMARIZER ─────────────────────────────────
/**
 * Summarizes long text into concise study notes.
 *
 * @param  string $content  Raw text to summarize
 * @param  string $style    "bullet_points" | "paragraph" | "flashcards"
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiSummarize(string $content, string $style = 'bullet_points'): array {
    $styleInstructions = [
        'bullet_points' => 'Use clear bullet points with key terms bolded. Group by topic.',
        'paragraph'     => 'Write a concise paragraph summary covering all key ideas.',
        'flashcards'    => 'Create flashcard-style Q&A pairs. Format: "Q: ... | A: ..."',
    ];
    $styleInstruction = $styleInstructions[$style] ?? $styleInstructions['bullet_points'];

    $messages = [
        [
            'role'    => 'user',
            'content' => "Summarize the following academic content for a student studying for exams.
Style: {$styleInstruction}

CONTENT:
{$content}

Provide a clear, structured summary that covers all important concepts.",
        ],
    ];

    return grokRequest($messages, GROQ_MODEL, 0.5);
}


// ── 5. AI STUDY RECOMMENDATIONS ──────────────────────────────
/**
 * Generates a personalized study plan based on user's exam history and weak areas.
 *
 * @param  array  $profile   User learning profile data
 * @return array  ['success', 'text', 'tokens', 'error']
 */
function aiStudyRecommendations(array $profile): array {
    $profileText = json_encode($profile, JSON_PRETTY_PRINT);

    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are an expert academic advisor and study coach. Provide personalized, actionable study recommendations based on the student\'s performance data.',
        ],
        [
            'role'    => 'user',
            'content' => "Based on this student's learning profile, generate a personalized study plan:

{$profileText}

Provide:
1. Top 3 priority topics to focus on (with specific study tips for each)
2. A 7-day study schedule
3. Recommended study techniques for their weak areas
4. A motivational message

Format your response clearly with sections and bullet points.",
        ],
    ];

    return grokRequest($messages, GROQ_MODEL, 0.7);
}


// ── 6. SAVE AI INTERACTION TO DB ─────────────────────────────
/**
 * Logs an AI interaction to the ai_chat_history table.
 */
function saveAiChat(mysqli $conn, int $userId, string $sessionId, string $role, string $message, string $type = 'tutor', int $courseId = 0, int $tokens = 0): void {
    $courseIdVal = $courseId > 0 ? $courseId : null;
    $stmt = $conn->prepare(
        "INSERT INTO ai_chat_history (user_id, session_id, role, message, interaction_type, course_id, tokens_used)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issssii', $userId, $sessionId, $role, $message, $type, $courseIdVal, $tokens);
    $stmt->execute();
    $stmt->close();
}


// ── 7. LOG PROGRESS EVENT ────────────────────────────────────
/**
 * Records a user activity event in user_progress for analytics.
 */
function logProgress(mysqli $conn, int $userId, string $eventType, string $detail = '', int $courseId = 0, float $score = 0.0): void {
    $courseIdVal = $courseId > 0 ? $courseId : null;
    $scoreVal    = $score   > 0 ? $score    : null;
    $stmt = $conn->prepare(
        "INSERT INTO user_progress (user_id, course_id, event_type, event_detail, score_value)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissd', $userId, $courseIdVal, $eventType, $detail, $scoreVal);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// PRIVATE KNOWLEDGE RAG HELPER FUNCTIONS
// ============================================================

/**
 * Extract text from a PDF file using a pure PHP stream parser.
 */
function extract_text_from_pdf(string $filename): string {
    if (!file_exists($filename)) return '';
    $infile = @file_get_contents($filename);
    if (empty($infile)) return '';
    
    $texts = [];
    preg_match_all("/stream(.*?)endstream/is", $infile, $matches);
    
    foreach ($matches[1] as $match) {
        $data = @gzuncompress(trim($match));
        if (!$data) {
            $data = @gzinflate(substr(trim($match), 2));
        }
        if ($data) {
            preg_match_all('/(?:\((.*?)\)\s*(?:Tj|TJ))|\[(.*?)\]\s*TJ/is', $data, $textMatches);
            foreach ($textMatches[1] as $tm) {
                if ($tm !== '') {
                    $texts[] = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $tm);
                }
            }
            foreach ($textMatches[2] as $tm) {
                if ($tm !== '') {
                    preg_match_all('/\((.*?)\)/is', $tm, $subMatches);
                    $wordGroup = '';
                    foreach ($subMatches[1] as $sm) {
                        $wordGroup .= str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $sm);
                    }
                    if ($wordGroup !== '') {
                        $texts[] = $wordGroup;
                    }
                }
            }
        }
    }
    
    if (empty($texts)) {
        preg_match_all('/\((.*?)\)\s*T[jJ]/is', $infile, $matches);
        foreach ($matches[1] as $tm) {
            $texts[] = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $tm);
        }
    }
    
    $resultText = implode(' ', $texts);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $resultText);
}

/**
 * Extract text from a DOCX file using pure PHP ZipArchive.
 */
function extract_text_from_docx(string $filename): string {
    if (!file_exists($filename)) return '';
    $zip = new ZipArchive();
    if ($zip->open($filename) === TRUE) {
        if (($xml = $zip->getFromName('word/document.xml')) !== false) {
            $zip->close();
            // Match all <w:t> tags
            preg_match_all('/<w:t.*?>(.*?)<\/w:t>/is', $xml, $matches);
            return html_entity_decode(implode(' ', $matches[1]));
        }
        $zip->close();
    }
    return '';
}

/**
 * Extract text from a PPTX presentation file using pure PHP ZipArchive.
 */
function extract_text_from_pptx(string $filename): string {
    if (!file_exists($filename)) return '';
    $zip = new ZipArchive();
    if ($zip->open($filename) === TRUE) {
        $texts = [];
        // Loop through slides in order
        for ($i = 1; $i <= 150; $i++) {
            $slidePath = "ppt/slides/slide{$i}.xml";
            $xml = $zip->getFromName($slidePath);
            if ($xml !== false) {
                // Match all text nodes inside <a:t>
                preg_match_all('/<a:t.*?>(.*?)<\/a:t>/is', $xml, $matches);
                if (!empty($matches[1])) {
                    $texts[] = implode(' ', $matches[1]);
                }
            } else {
                break;
            }
        }
        $zip->close();
        return strip_tags(html_entity_decode(implode(' ', $texts)));
    }
    return '';
}

/**
 * Helper to make HTTP requests to the local Python ChromaDB service.
 */
function chroma_request(string $action, array $data): ?array {
    $baseUrl = defined('CHROMA_API_URL') ? CHROMA_API_URL : 'http://127.0.0.1:8000';
    $url = rtrim($baseUrl, '/') . '/' . ltrim($action, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) return null;
    return json_decode($response, true);
}

/**
 * Indexes a single file by extracting text, chunking, and storing in DB.
 */
function index_file_content(mysqli $conn, int $fileId): bool {
    try {
        $indexer = new \Services\IndexerService($conn);
        return $indexer->indexFile($fileId);
    } catch (\Exception $e) {
        error_log("index_file_content exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Vector similarity search (TF-IDF Cosine Similarity) on candidate chunks.
 * Enforces ownership validation (user_id is strictly matched).
 */
function search_document_chunks(mysqli $conn, int $userId, string $query, ?int $courseId = null, ?int $folderId = null, ?int $topicId = null, ?array $fileIds = null, int $limit = 5): array {
    // 1. Try Jina + Qdrant first
    try {
        $jina = new \Embeddings\JinaEmbeddingsClient();
        $qdrant = new \Vector\QdrantClient();
        
        $queryVectors = $jina->getEmbeddings([$query]);
        if (!empty($queryVectors) && isset($queryVectors[0])) {
            $vector = $queryVectors[0];
            
            $filters = ['user_id' => $userId];
            if ($courseId !== null && $courseId > 0) {
                $filters['course_id'] = $courseId;
            }
            if ($folderId !== null && $folderId > 0) {
                $filters['folder_id'] = $folderId;
            }
            if ($topicId !== null && $topicId > 0) {
                $filters['topic_id'] = $topicId;
            }
            if (!empty($fileIds)) {
                $filters['file_ids'] = $fileIds;
            }
            
            $points = $qdrant->searchPoints($vector, $filters, $limit);
            if (!empty($points)) {
                $results = [];
                foreach ($points as $p) {
                    $payload = $p['payload'] ?? [];
                    $fileId = $payload['file_id'] ?? 0;
                    if (!$fileId) continue;
                    
                    // Fetch additional metadata details from MySQL for presentation
                    $mq = $conn->prepare("
                        SELECT f.name AS file_name, c.name AS course_name, c.code AS course_code, fo.name AS folder_name, ct.title AS topic_name 
                        FROM files f
                        LEFT JOIN courses c ON f.course_id = c.id
                        LEFT JOIN folders fo ON f.folder_id = fo.id
                        LEFT JOIN file_course_tags fct ON fct.file_id = f.id
                        LEFT JOIN course_topics ct ON fct.topic_id = ct.id
                        WHERE f.id = ? LIMIT 1
                    ");
                    if ($mq) {
                        $mq->bind_param('i', $fileId);
                        $mq->execute();
                        $mq->bind_result($fileName, $courseName, $courseCode, $folderName, $topicName);
                        if ($mq->fetch()) {
                            // Cosine score mapped to similarity confidence percentage
                            $confidence = max(0, min(100, intval(($p['score'] ?? 0.0) * 100)));
                            $results[] = [
                                'content' => $payload['content'] ?? '',
                                'page_number' => $payload['page_number'] ?? 1,
                                'file_id' => $fileId,
                                'file_name' => $fileName,
                                'course_name' => $courseName,
                                'course_code' => $courseCode,
                                'folder_name' => $folderName,
                                'topic_name' => $topicName,
                                'confidence_score' => $confidence
                            ];
                        }
                        $mq->close();
                    }
                }
                if (!empty($results)) {
                    // Log query stats to MySQL
                    $logStmt = $conn->prepare("INSERT INTO ai_query_logs (user_id, query, response, course_id, folder_id, topic_id) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($logStmt) {
                        $firstReply = $results[0]['content'];
                        $logStmt->bind_param('issiii', $userId, $query, $firstReply, $courseId, $folderId, $topicId);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                    return $results;
                }
            }
        }
    } catch (\Exception $e) {
        error_log("search_document_chunks Qdrant exception: " . $e->getMessage());
    }

    // 2. Fallback to SQL-based Lexical Similarity Search
    $sql = "SELECT dc.content, dc.page_number, f.id AS file_id, f.name AS file_name, c.name AS course_name, c.code AS course_code, fo.name AS folder_name, ct.title AS topic_name 
            FROM document_chunks dc
            JOIN files f ON dc.file_id = f.id
            LEFT JOIN courses c ON dc.course_id = c.id
            LEFT JOIN folders fo ON dc.folder_id = fo.id
            LEFT JOIN course_topics ct ON dc.topic_id = ct.id
            WHERE dc.user_id = ?";
            
    $params = [$userId];
    $types = 'i';
    
    if ($courseId !== null && $courseId > 0) {
        $sql .= " AND dc.course_id = ?";
        $params[] = $courseId;
        $types .= 'i';
    }
    if ($folderId !== null && $folderId > 0) {
        $sql .= " AND dc.folder_id = ?";
        $params[] = $folderId;
        $types .= 'i';
    }
    if ($topicId !== null && $topicId > 0) {
        $sql .= " AND dc.topic_id = ?";
        $params[] = $topicId;
        $types .= 'i';
    }
    if (!empty($fileIds)) {
        $cleanIds = array_filter(array_map('intval', $fileIds));
        if (!empty($cleanIds)) {
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $sql .= " AND dc.file_id IN ($placeholders)";
            foreach ($cleanIds as $fId) {
                $params[] = $fId;
                $types .= 'i';
            }
        }
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $candidates = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($candidates)) return [];
    
    // Tokenize query
    $queryTerms = tokenize_text($query);
    if (empty($queryTerms)) {
        return array_slice($candidates, 0, $limit);
    }
    
    $queryVector = array_count_values($queryTerms);
    $queryMag = 0;
    foreach ($queryVector as $count) {
        $queryMag += $count * $count;
    }
    $queryMag = sqrt($queryMag);
    
    $scores = [];
    foreach ($candidates as $cand) {
        $candTerms = tokenize_text($cand['content']);
        if (empty($candTerms)) continue;
        
        $candVector = array_count_values($candTerms);
        
        $dotProduct = 0;
        foreach ($queryVector as $term => $qCount) {
            if (isset($candVector[$term])) {
                $dotProduct += $qCount * $candVector[$term];
            }
        }
        
        $candMag = 0;
        foreach ($candVector as $count) {
            $candMag += $count * $count;
        }
        $candMag = sqrt($candMag);
        
        $similarity = 0;
        if ($queryMag > 0 && $candMag > 0) {
            $similarity = $dotProduct / ($queryMag * $candMag);
        }
        
        if ($similarity > 0) {
            $scores[] = [
                'candidate' => $cand,
                'score' => $similarity
            ];
        }
    }
    
    if (empty($scores)) {
        // Fallback: substring matching
        foreach ($candidates as $cand) {
            $matched = 0;
            foreach ($queryTerms as $term) {
                if (mb_strpos(mb_strtolower($cand['content']), $term) !== false) {
                    $matched++;
                }
            }
            if ($matched > 0) {
                $scores[] = [
                    'candidate' => $cand,
                    'score' => $matched / count($queryTerms) * 0.1 // small weight
                ];
            }
        }
    }
    
    usort($scores, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    $results = [];
    foreach (array_slice($scores, 0, $limit) as $item) {
        $cand = $item['candidate'];
        $cand['confidence_score'] = min(100, max(5, round($item['score'] * 100)));
        $results[] = $cand;
    }
    
    return $results;
}

function tokenize_text(string $text): array {
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\w\s]/u', ' ', $text);
    return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * Auto-indexes all files belonging to a user that are not yet indexed in document_chunks.
 */
function index_all_unindexed_user_files(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("
        SELECT f.id FROM files f
        LEFT JOIN document_chunks dc ON f.id = dc.file_id
        WHERE f.owner_id = ? AND dc.id IS NULL
        LIMIT 10
    ");
    if (!$stmt) return;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $unindexedIds = [];
    while ($row = $res->fetch_assoc()) {
        $unindexedIds[] = (int)$row['id'];
    }
    $stmt->close();

    foreach ($unindexedIds as $fId) {
        index_file_content($conn, $fId);
    }
}

