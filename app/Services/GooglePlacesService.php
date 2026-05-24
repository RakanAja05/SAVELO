<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.google.api_key');
        $this->baseUrl = config('services.google.base_url');
    }

    public function searchByText(string $query, array $options = []): array
    {
        $response = Http::timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => $options['fields'] ?? 'places.id,places.displayName,places.formattedAddress,places.location,places.rating,places.photos,places.types,places.priceLevel',
        ])->post("{$this->baseUrl}/places:searchText", [
            'textQuery' => $query,
            'maxResultCount' => $options['limit'] ?? 20,
            'languageCode' => $options['language'] ?? 'id',
        ]);

        if ($response->failed()) {
            Log::error('GooglePlacesService: searchByText failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    public function searchNearby(float $lat, float $lng, array $options = []): array
    {
        $response = Http::timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => $options['fields'] ?? 'places.id,places.displayName,places.formattedAddress,places.location,places.rating,places.photos,places.types,places.priceLevel',
        ])->post("{$this->baseUrl}/places:searchNearby", [
            'maxResultCount' => $options['limit'] ?? 10,
            'languageCode' => $options['language'] ?? 'id',
            'includedTypes' => $options['types'] ?? ['tourist_attraction'],
            'locationRestriction' => [
                'circle' => [
                    'center' => [
                        'latitude' => $lat,
                        'longitude' => $lng,
                    ],
                    'radius' => (float) ($options['radius'] ?? 3000),
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('GooglePlacesService: searchNearby failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    public function getDetail(string $placeId, array $fields = []): array
    {
        $fieldMask = empty($fields)
            ? 'id,displayName,formattedAddress,addressComponents,location,rating,photos,regularOpeningHours,internationalPhoneNumber,websiteUri,priceLevel'
            : implode(',', $fields);

        $response = Http::timeout(10)->withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => $fieldMask,
        ])->get("{$this->baseUrl}/places/{$placeId}");

        if ($response->failed()) {
            Log::error('GooglePlacesService: getDetail failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }
}
