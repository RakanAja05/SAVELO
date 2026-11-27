<?php

declare(strict_types=1);

namespace App\Services\PlaceCache;

class AddressParser
{
    /**
     * Extract country code from Google Places addressComponents array.
     *
     * @param  array<int, array<string, mixed>>  $addressComponents
     */
    public function extractCountryCode(array $addressComponents): string
    {
        foreach ($addressComponents as $comp) {
            if (in_array('country', $comp['types'] ?? [], true)) {
                return $comp['shortText'] ?? 'ID';
            }
        }

        return 'ID';
    }

    /**
     * Extract city name from Google Places addressComponents array.
     *
     * @param  array<int, array<string, mixed>>  $addressComponents
     */
    public function extractCity(array $addressComponents): string
    {
        foreach ($addressComponents as $comp) {
            if (in_array('locality', $comp['types'] ?? [], true)) {
                return $comp['longText'] ?? '';
            }
        }

        return '';
    }
}
