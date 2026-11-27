<?php

declare(strict_types=1);

namespace App\Services\PlaceCache;

use App\Models\Destination;

class PlaceBatchProcessor
{
    public function __construct(
        private PlaceCategoryClassifier $categoryClassifier,
        private PriceTierResolver $priceTierResolver,
        private UmkmClassifier $umkmClassifier,
    ) {}

    /**
     * Process batch classification (UMKM + price tier) and update destinations.
     *
     * @param  array<int, array<string, mixed>>  $places
     */
    public function processBatchClassification(array $places): void
    {
        if (empty($places)) {
            return;
        }

        $umkmResults = $this->umkmClassifier->batchClassifyUmkm($places);

        $resolvedCategories = [];
        foreach ($places as $p) {
            $placeId = $p['id'];
            $name = $p['displayName']['text'] ?? '';
            $aiResult = $umkmResults[$placeId] ?? null;
            $isUmkm = $aiResult !== null ? $aiResult : $this->categoryClassifier->classifyUmkmByRules($name);
            $resolvedCategories[$placeId] = $isUmkm
                ? 'umkm'
                : $this->categoryClassifier->extractBaseCategory($p['types'] ?? []);
        }

        $priceResults = $this->priceTierResolver->batchInferPriceTier($places, $resolvedCategories);

        foreach ($places as $p) {
            $placeId = $p['id'];
            $category = $resolvedCategories[$placeId] ?? $this->categoryClassifier->extractBaseCategory($p['types'] ?? []);
            $priceTier = $priceResults[$placeId]
                ?? $this->priceTierResolver->extractPriceTier($p['priceLevel'] ?? null);

            if ($priceTier === 'unknown') {
                $priceTier = $this->priceTierResolver->categoryFallbackTier($category);
            }

            $mapCategory = $this->categoryClassifier->resolveMapCategory(
                $category,
                $p['displayName']['text'] ?? '',
                (float) ($p['rating'] ?? 0),
                (int) ($p['userRatingCount'] ?? 0)
            );

            Destination::where('place_id', $placeId)->update([
                'category' => $category,
                'price_tier' => $priceTier,
                'map_category' => $mapCategory,
                'culture_points' => $this->categoryClassifier->culturePointsFor($category, $mapCategory),
            ]);
        }
    }
}
