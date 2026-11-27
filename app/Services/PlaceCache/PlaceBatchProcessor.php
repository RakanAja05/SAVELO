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
     * This consolidates the duplicate logic from fetchCityDiverseDestinations and fetchPlaceDetail.
     */
    public function processBatchClassification(array $places): void
    {
        if (empty($places)) {
            return;
        }

        // Step 1: Batch classify UMKM via AI
        $umkmResults = $this->umkmClassifier->batchClassifyUmkm($places, $this->categoryClassifier);

        // Step 2: Resolve categories (UMKM takes precedence)
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

        // Step 3: Batch infer price tiers via AI
        $priceResults = $this->priceTierResolver->batchInferPriceTier($places, $resolvedCategories);

        // Step 4: Update all destinations with enriched data
        foreach ($places as $p) {
            $placeId = $p['id'];
            $category = $resolvedCategories[$placeId];
            $priceTier = $priceResults[$placeId]
                ?? $this->priceTierResolver->extractPriceTier($p['priceLevel'] ?? null);

            if ($priceTier === 'unknown') {
                $priceTier = $this->priceTierResolver->categoryFallbackTier($category);
            }

            Destination::where('place_id', $placeId)->update([
                'category' => $category,
                'price_tier' => $priceTier,
                'map_category' => $this->categoryClassifier->resolveMapCategory(
                    $category,
                    $p['displayName']['text'] ?? '',
                    (float) ($p['rating'] ?? 0),
                    (int) ($p['userRatingCount'] ?? 0)
                ),
                'culture_points' => $this->categoryClassifier->culturePointsFor(
                    $category,
                    $this->categoryClassifier->resolveMapCategory(
                        $category,
                        $p['displayName']['text'] ?? '',
                        (float) ($p['rating'] ?? 0),
                        (int) ($p['userRatingCount'] ?? 0)
                    )
                ),
            ]);
        }
    }
}
