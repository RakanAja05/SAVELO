<?php

namespace App\Services\PlaceCache;

class AddressParser
{
    /**
     * Extract country code from Google Places addressComponents array.
     */
    public function extractCountryCode(array $addressComponents): ?string
    {
        foreach ($addressComponents as $comp) {
            if (in_array('country', $comp['types'] ?? [])) {
                return $comp['shortText'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract city name from Google Places addressComponents array.
     */
    public function extractCity(array $addressComponents): ?string
    {
        foreach ($addressComponents as $comp) {
            if (in_array('locality', $comp['types'] ?? [])) {
                return $comp['longText'] ?? null;
            }
        }

        return null;
    }
}
