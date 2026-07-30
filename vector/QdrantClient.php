<?php
// ============================================================
// vector/QdrantClient.php — NoteNest AI Platform
// Qdrant Vector Database Client
// ============================================================

namespace Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class QdrantClient {
    private string $apiUrl;
    private string $apiKey;
    private Client $httpClient;
    private string $collection = 'notenest_collection';

    public function __construct() {
        $this->apiUrl = defined('QDRANT_API_URL') ? QDRANT_API_URL : (getenv('QDRANT_API_URL') ?: 'http://localhost:6333');
        $this->apiKey = defined('QDRANT_API_KEY') ? QDRANT_API_KEY : (getenv('QDRANT_API_KEY') ?: '');
        
        $headers = [
            'Content-Type' => 'application/json'
        ];
        if (!empty($this->apiKey)) {
            $headers['api-key'] = $this->apiKey;
        }

        $this->httpClient = new Client([
            'base_uri' => rtrim($this->apiUrl, '/') . '/',
            'headers'  => $headers,
            'timeout'  => 30.0
        ]);
        
        $this->ensureCollectionExists();
    }

    /**
     * Creates the collection if it doesn't already exist.
     */
    private function ensureCollectionExists(): void {
        try {
            $this->httpClient->get("collections/{$this->collection}");
        } catch (GuzzleException $e) {
            try {
                $this->httpClient->put("collections/{$this->collection}", [
                    'json' => [
                        'vectors' => [
                            'size' => 768,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]);
            } catch (GuzzleException $ex) {
                error_log("QdrantClient failed to create collection: " . $ex->getMessage());
            }
        }
    }

    /**
     * Upsert points into Qdrant.
     */
    public function upsertPoints(array $points): bool {
        if (empty($points)) return true;
        try {
            $response = $this->httpClient->put("collections/{$this->collection}/points?wait=true", [
                'json' => [
                    'points' => $points
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            return isset($body['status']) && $body['status'] === 'ok';
        } catch (GuzzleException $e) {
            error_log("QdrantClient upsert error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search points with vector similarity and filters.
     */
    public function searchPoints(array $vector, array $filters, int $limit = 5): array {
        try {
            $payload = [
                'vector' => $vector,
                'limit'  => $limit,
                'with_payload' => true
            ];
            
            if (!empty($filters)) {
                $must = [];
                foreach ($filters as $key => $val) {
                    if ($val === null) continue;
                    if ($key === 'file_ids') {
                        if (!empty($val)) {
                            $must[] = [
                                'key' => 'file_id',
                                'match' => [
                                    'any' => array_map('intval', $val)
                                ]
                            ];
                        }
                    } else {
                        $must[] = [
                            'key' => $key,
                            'match' => [
                                'value' => (int)$val
                            ]
                        ];
                    }
                }
                if (!empty($must)) {
                    $payload['filter'] = [
                        'must' => $must
                    ];
                }
            }

            $response = $this->httpClient->post("collections/{$this->collection}/points/search", [
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return $body['result'] ?? [];
        } catch (GuzzleException $e) {
            error_log("QdrantClient search error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete points associated with a specific file.
     */
    public function deletePointsByFile(int $fileId): bool {
        try {
            $response = $this->httpClient->post("collections/{$this->collection}/points/delete", [
                'json' => [
                    'filter' => [
                        'must' => [
                            [
                                'key' => 'file_id',
                                'match' => [
                                    'value' => $fileId
                                ]
                            ]
                        ]
                    ]
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            return isset($body['status']) && $body['status'] === 'ok';
        } catch (GuzzleException $e) {
            error_log("QdrantClient delete error: " . $e->getMessage());
            return false;
        }
    }
}
