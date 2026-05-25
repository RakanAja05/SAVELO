<?php

namespace App\Services\Gemini;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.key');
        $this->model = (string) config('services.gemini.model');
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
    }

    public function generate(string $prompt): ?string
    {
        if ($this->apiKey === '' || $this->model === '') {
            Log::warning('Gemini API not configured.', [
                'key_set' => $this->apiKey !== '',
                'model_set' => $this->model !== '',
            ]);

            return null;
        }

        try {
            $response = Http::withQueryParameters([
                'key' => $this->apiKey,
            ])
                ->timeout(120)
                ->connectTimeout(10)
                ->retry(1, 200, throw: true)
                ->post($this->baseUrl, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 65536,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            Log::info('Gemini response debug', [
                'model' => $this->model,
                'response_length' => strlen($response->json('candidates.0.content.parts.0.text') ?? ''),
                'finish_reason' => $response->json('candidates.0.finishReason'),
                'token_count' => $response->json('usageMetadata'),
                'full_response'   => $response->json(),
            ]);

            return $response->json('candidates.0.content.parts.0.text');
        } catch (RequestException $e) {
            $response = $e->response;

            Log::error('Gemini API error', [
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            return null;
        }
    }
}
