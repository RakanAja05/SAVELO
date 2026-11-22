<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

class ItineraryParser
{
    public function parseGeminiResponse(string $response): ?array
    {
        $clean = trim($response);

        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\\s*|\\s*```$/m', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $firstBrace = strpos($clean, '{');
        $lastBrace = strrpos($clean, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $clean = substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function normalizeVariants(array $parsed): array
    {
        if (isset($parsed['variants']) && is_array($parsed['variants'])) {
            return $parsed['variants'];
        }

        $variants = [];
        $keys = ['hemat', 'seimbang', 'experience'];

        foreach ($keys as $key) {
            $variant = $parsed[$key] ?? null;

            if (! is_array($variant)) {
                continue;
            }

            $variant['variant'] = $key;
            $variants[] = $variant;
        }

        return $variants;
    }

    public function finalizeRecommendation(array $variants): array
    {
        $maxScore = null;
        $recommendedIndex = null;

        foreach ($variants as $index => $variant) {
            $score = is_numeric($variant['score'] ?? null)
                ? (float) $variant['score']
                : null;

            if ($score === null) {
                continue;
            }

            if ($maxScore === null || $score > $maxScore) {
                $maxScore = $score;
                $recommendedIndex = $index;
            }
        }

        if ($recommendedIndex === null) {
            $recommendedIndex = 0;
        }

        foreach ($variants as $index => $variant) {
            $variants[$index]['is_recommended'] = $index === $recommendedIndex;
        }

        return $variants;
    }
}
