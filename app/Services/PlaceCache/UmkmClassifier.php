<?php

namespace App\Services\PlaceCache;

use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Log;

class UmkmClassifier
{
    public function __construct(
        private GeminiService $gemini,
        private PriceTierResolver $priceTierResolver,
    ) {}

    /**
     * Batch classify places as UMKM using Gemini.
     */
    public function batchClassifyUmkm(array $places, PlaceCategoryClassifier $categoryClassifier): array
    {
        $lines = [];
        foreach ($places as $p) {
            $placeId = $p['id'];
            $name = $p['displayName']['text'] ?? '';
            $address = $p['formattedAddress'] ?? '';
            $category = $categoryClassifier->extractBaseCategory($p['types'] ?? []);
            $lines[] = "{$placeId} | {$name} | {$address} | {$category}";
        }

        $list = implode("\n", $lines);
        $prompt = <<<PROMPT
Kamu adalah sistem klasifikasi bisnis lokal Indonesia.

Tugasmu: tentukan apakah setiap tempat berikut adalah UMKM lokal (bukan franchise atau jaringan nasional/internasional besar).

UMKM lokal: warung, kedai, toko kelontong, usaha kerajinan, rumah makan lokal, toko oleh-oleh, dan sejenisnya yang dimiliki/dioperasikan secara independen.
Bukan UMKM: franchise internasional, jaringan minimarket nasional, brand F&B besar, mall, hotel bintang 3+.

Daftar tempat:
{$list}

Jawab HANYA dalam format berikut, satu baris per tempat, tanpa teks lain:
place_id|Y/N|confidence_score
PROMPT;

        $response = $this->gemini->generate($prompt);

        // Logging raw Gemini response
        Log::info('Gemini batchClassifyUmkm raw response', [
            'response' => $response,
            'places_count' => count($places),
            'prompt_length' => strlen($prompt),
        ]);

        if (! $response) {
            Log::warning('batchClassifyUmkm: Gemini returned null', [
                'places_count' => count($places),
            ]);

            return [];
        }

        return $this->priceTierResolver->parseBatchResponse($response, 'umkm');
    }
}
