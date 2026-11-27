<?php

declare(strict_types=1);

namespace App\Services\Gemini;

class GeminiBatchParser
{
    /**
     * Parse pipe-delimited batch responses from Gemini and filter predictions below the confidence threshold.
     *
     * @return array<string, bool|string|null>
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
            $confidenceScore = (float) trim($confidence) * 100;
            $value = trim($value);

            if ($confidenceScore < 70) {
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
}
