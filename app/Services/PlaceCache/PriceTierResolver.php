<?php

namespace App\Services\PlaceCache;

use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Log;

class PriceTierResolver
{
    public function __construct(
        private GeminiService $gemini,
    ) {}

    /**
     * Extract price tier from Google Places priceLevel field.
     */
    public function extractPriceTier(?string $priceLevel): string
    {
        return match ($priceLevel) {
            'PRICE_LEVEL_FREE' => 'free',
            'PRICE_LEVEL_INEXPENSIVE' => 'budget',
            'PRICE_LEVEL_MODERATE' => 'mid',
            'PRICE_LEVEL_EXPENSIVE' => 'premium',
            'PRICE_LEVEL_VERY_EXPENSIVE' => 'luxury',
            default => 'unknown',
        };
    }

    /**
     * Get fallback price tier based on category.
     */
    public function categoryFallbackTier(string $category): string
    {
        return match ($category) {
            'umkm', 'taman' => 'budget',
            'wisata', 'museum' => 'mid',
            'restoran', 'cafe' => 'mid',
            'belanja' => 'mid',
            'hotel' => 'premium',
            default => 'unknown',
        };
    }

    /**
     * Batch infer price tier using Gemini for places without Google priceLevel.
     */
    public function batchInferPriceTier(array $places, array $resolvedCategories): array
    {
        // Only classify places where Google did not provide priceLevel
        $toClassify = array_filter($places, fn ($p) => empty($p['priceLevel']));

        if (empty($toClassify)) {
            return [];
        }

        $lines = [];
        foreach ($toClassify as $p) {
            $placeId = $p['id'];
            $name = $p['displayName']['text'] ?? '';
            $address = $p['formattedAddress'] ?? '';
            $category = $resolvedCategories[$placeId] ?? 'lainnya';
            $lines[] = "{$placeId} | {$name} | {$address} | {$category}";
        }

        $list = implode("\n", $lines);
        $prompt = <<<PROMPT
Kamu adalah sistem klasifikasi harga tempat di Indonesia.

Tier yang tersedia:
- free: gratis, taman umum, ruang publik
- budget: Rp0-50.000
- mid: Rp50.000-150.000
- premium: Rp150.000-500.000
- luxury: Rp500.000+

Daftar tempat:
{$list}

Jawab HANYA dalam format berikut, satu baris per tempat, tanpa teks lain:
place_id|tier|confidence_score
PROMPT;

        $response = $this->gemini->generate($prompt);

        // Logging raw Gemini response
        Log::info('Gemini batchInferPriceTier raw response', [
            'response' => $response,
            'places_count' => count($toClassify),
            'prompt_length' => strlen($prompt),
        ]);

        if (! $response) {
            Log::warning('batchInferPriceTier: Gemini returned null', [
                'places_count' => count($toClassify),
            ]);

            return [];
        }

        return $this->parseBatchResponse($response, 'price');
    }

    /**
     * Parse batch Gemini response (for price tier or UMKM classification).
     */
    public function parseBatchResponse(string $response, string $type): array
    {
        $results = [];
        $lines = array_filter(explode("\n", trim($response)));

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 3) {
                continue;
            }

            [$placeId, $value, $confidence] = $parts;
            $placeId = trim($placeId);
            $confidence = (float) trim($confidence) * 100;
            $value = trim($value);

            if ($confidence < 70) {
                $results[$placeId] = null;

                continue;
            }

            if ($type === 'umkm') {
                $results[$placeId] = strtoupper($value) === 'Y';
            } else {
                $validTiers = ['free', 'budget', 'mid', 'premium', 'luxury'];
                $results[$placeId] = in_array($value, $validTiers, true) ? $value : null;
            }
        }

        return $results;
    }

    /**
     * Get price range for a given tier.
     */
    public static function priceTierRange(string $tier): array
    {
        return match ($tier) {
            'free' => ['min' => 0, 'max' => 0, 'label' => 'Gratis'],
            'budget' => ['min' => 10000, 'max' => 50000, 'label' => 'Rp10.000 - Rp50.000'],
            'mid' => ['min' => 50000, 'max' => 150000, 'label' => 'Rp50.000 - Rp150.000'],
            'premium' => ['min' => 150000, 'max' => 500000, 'label' => 'Rp150.000 - Rp500.000'],
            'luxury' => ['min' => 500000, 'max' => null, 'label' => 'Rp500.000+'],
            default => ['min' => null, 'max' => null, 'label' => 'Tidak diketahui'],
        };
    }
}
