<?php
// ============================================================
// embeddings/JinaEmbeddingsClient.php — NoteNest AI Platform
// Jina AI Embeddings Client
// ============================================================

namespace Embeddings;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class JinaEmbeddingsClient {
    private string $apiKey;
    private Client $httpClient;
    private string $model = 'jina-embeddings-v2-base-en';

    public function __construct() {
        $this->apiKey = defined('JINA_API_KEY') ? JINA_API_KEY : (getenv('JINA_API_KEY') ?: '');
        $this->httpClient = new Client([
            'base_uri' => 'https://api.jina.ai/v1/',
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json'
            ],
            'timeout'  => 30.0
        ]);
    }

    /**
     * Generate 768-dimensional embeddings for an array of text chunks.
     */
    public function getEmbeddings(array $texts): array {
        if (empty($texts)) return [];
        if (empty($this->apiKey)) {
            error_log("JinaEmbeddingsClient: JINA_API_KEY is empty.");
            return array_fill(0, count($texts), array_fill(0, 768, 0.0));
        }

        try {
            $response = $this->httpClient->post('embeddings', [
                'json' => [
                    'model' => $this->model,
                    'input' => $texts
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $embeddings = [];
            if (isset($data['data'])) {
                foreach ($data['data'] as $item) {
                    $embeddings[] = $item['embedding'];
                }
            }
            return $embeddings;
        } catch (GuzzleException $e) {
            error_log("JinaEmbeddingsClient Guzzle error: " . $e->getMessage());
            return array_fill(0, count($texts), array_fill(0, 768, 0.0));
        }
    }
}
