<?php

namespace App\Services\PlaceCache;

class PlaceCategoryClassifier
{
    const CATEGORY_MAP = [
        'tourist_attraction' => 'wisata',
        'restaurant' => 'restoran',
        'cafe' => 'cafe',
        'lodging' => 'hotel',
        'museum' => 'museum',
        'park' => 'taman',
        'shopping_mall' => 'belanja',
        'bus_station' => 'transit',
        'train_station' => 'transit',
        'transit_station' => 'transit',
        'subway_station' => 'transit',
        'airport' => 'transit',
    ];

    const FRANCHISE_BLACKLIST = [
        'mcdonald', 'starbucks', 'kfc', 'burger king', 'pizza hut',
        'domino', 'subway', 'dunkin', 'baskin robbins', 'haagen dazs',
        'uniqlo', 'zara', 'h&m', 'indomaret', 'alfamart', 'lawson',
        'circle k', 'family mart', 'chatime', 'koi', 'gong cha', 'mixue',
        'hypermart', 'transmart', 'carrefour', 'giant', 'superindo',
        'j.co', 'breadtalk',
    ];

    const UMKM_WHITELIST = [
        'warung', 'kedai', 'toko', 'oleh-oleh', 'oleh oleh',
        'kerajinan', 'angkringan', 'batik', 'koperasi',
        'tenun', 'anyaman', 'ukiran', 'gerabah', 'tembikar',
        'jamu', 'pecel', 'gudeg', 'bakpia', 'getuk', 'lumpia',
        'rumah makan', 'depot', 'lapak', 'lesehan', 'soto', 'sate',
    ];

    /**
     * Extract base category from Google Places types array.
     */
    public function extractBaseCategory(array $types): string
    {
        foreach ($types as $type) {
            if (isset(self::CATEGORY_MAP[$type])) {
                return self::CATEGORY_MAP[$type];
            }
        }

        return 'lainnya';
    }

    /**
     * Extract category with UMKM classification fallback.
     */
    public function extractCategory(array $types, string $placeName = '', string $address = ''): string
    {
        $base = $this->extractBaseCategory($types);

        if ($placeName === '') {
            return $base;
        }

        $umkmCandidates = ['restoran', 'cafe', 'belanja', 'lainnya'];
        if (in_array($base, $umkmCandidates, true)) {
            $isUmkm = $this->classifyUmkmByRules($placeName);
            if ($isUmkm) {
                return 'umkm';
            }
        }

        return $base;
    }

    /**
     * Resolve map category with heritage/iconic/hidden_gem classification.
     */
    public function resolveMapCategory(
        string $category,
        string $placeName,
        float $rating,
        int $reviewCount
    ): string {
        if ($category === 'hotel') {
            return 'hotel';
        }

        if ($category === 'umkm') {
            return 'umkm';
        }

        if ($category === 'transit') {
            return 'transit';
        }

        $heritageTags = [
            'candi', 'keraton', 'kraton', 'pura', 'masjid agung',
            'benteng', 'makam', 'monumen', 'heritage', 'kolonial',
            'kota lama', 'peninggalan', 'situs',
        ];
        $nameLower = strtolower($placeName);

        foreach ($heritageTags as $tag) {
            if (str_contains($nameLower, $tag)) {
                return 'heritage';
            }
        }

        if ($category === 'museum') {
            return 'heritage';
        }

        if (in_array($category, ['wisata', 'taman'], true)) {
            if ($rating >= 4.5 && $reviewCount >= 1000) {
                return 'iconic';
            }

            if ($rating >= 4.6 && $reviewCount < 200) {
                return 'hidden_gem';
            }

            if ($reviewCount >= 5000) {
                return 'iconic';
            }
        }

        return $category;
    }

    /**
     * Classify as UMKM using rule-based heuristics.
     */
    public function classifyUmkmByRules(string $placeName): bool
    {
        $nameLower = strtolower($placeName);

        foreach (self::FRANCHISE_BLACKLIST as $franchise) {
            if (str_contains($nameLower, $franchise)) {
                return false;
            }
        }

        foreach (self::UMKM_WHITELIST as $keyword) {
            if (str_contains($nameLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate culture points for a destination.
     */
    public function culturePointsFor(string $category, ?string $mapCategory = null): int
    {
        $mapCategory = $mapCategory ?? '';

        if ($mapCategory === 'heritage') {
            return 35;
        }

        if ($mapCategory === 'iconic') {
            return 30;
        }

        if ($category === 'museum') {
            return 20;
        }

        if ($category === 'wisata') {
            return 5;
        }

        return 0;
    }
}
