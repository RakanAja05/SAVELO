<?php

namespace App\Http\Controllers\Api\Destination;

use App\Http\Controllers\Controller;
use App\Services\PlaceCacheService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function __construct(private PlaceCacheService $cache) {}

    /**
     * GET /api/destinations?city=Yogyakarta
     *
     * Layer 1 — Ambil destinasi trending suatu kota.
     * Cek cache dulu, fetch Google kalau belum ada / expired.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'category' => ['nullable', 'string', 'in:wisata,restoran,cafe,hotel,museum,taman,belanja,umkm,transit,lainnya'],
        ]);

        $result = $this->cache->getCityPlaces($request->city);

        if (! empty($result['error'])) {
            return ApiResponse::error($result['error'], null, 404);
        }

        $places = $result['places'];
        $area = $result['area'] ?? null;

        // Filter by category kalau ada
        if ($request->filled('category')) {
            $places = $places->where('category', $request->category)->values();
        }

        return ApiResponse::success("Destinasi di {$request->city}.", [
            'from_cache' => $result['from_cache'],
            'stale' => $result['stale'] ?? false,
            'area' => $area ? [
                'id' => $area->id,
                'grid_lat' => $area->grid_lat,
                'grid_lng' => $area->grid_lng,
            ] : null,
            'total' => $places->count(),
            'destinations' => $places->map(fn ($p) => $this->formatDestination($p)),
        ]);
    }

    /**
     * GET /api/destinations/{place_id}
     *
     * Layer 2 — Ambil detail destinasi + nearby 3km.
     * Fetch Google kalau detail_fetched_at null atau > 30 hari.
     */
    public function show(string $placeId): JsonResponse
    {
        $result = $this->cache->getPlaceDetail($placeId);

        if (! empty($result['error'])) {
            return ApiResponse::error($result['error'], null, $result['code'] ?? 404);
        }

        $destination = $result['destination'];

        return ApiResponse::success('Detail destinasi.', [
            'from_cache' => $result['from_cache'],
            'stale' => $result['stale'] ?? false,
            'destination' => $this->formatDestination($destination, detail: true),
        ]);
    }

    /**
     * GET /api/destinations/map?city=Yogyakarta
     * GET /api/destinations/map?city=Yogyakarta&category=iconic
     *
     * Discovery Map — lightweight pin data only.
     * Only returns destinations with a valid Discovery Map category.
     * Returns place_id, map_category, pin_color, lat, lng.
     */
    public function map(Request $request): JsonResponse
    {
        $request->validate([
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'category' => ['nullable', 'string', 'in:umkm,iconic,heritage,hidden_gem,hotel,transit'],
        ]);

        $result = $this->cache->getCityPlaces($request->city);

        if (! empty($result['error'])) {
            return ApiResponse::error($result['error'], null, 404);
        }

        $mapCategories = ['umkm', 'iconic', 'heritage', 'hidden_gem', 'hotel', 'transit'];

        $places = $result['places']
            ->whereIn('map_category', $mapCategories)
            ->values();

        if ($request->filled('category')) {
            $places = $places->where('map_category', $request->category)->values();
        }

        return ApiResponse::success('Map pins.', [
            'from_cache' => $result['from_cache'],
            'total' => $places->count(),
            'destinations' => $places->map(fn ($d) => [
                'place_id' => $d->place_id,
                'map_category' => $d->map_category,
                'pin_color' => $this->getPinColor($d->map_category),
                'lat' => (float) $d->lat,
                'lng' => (float) $d->lng,
            ]),
        ]);
    }

    private function getPinColor(string $mapCategory): string
    {
        return match ($mapCategory) {
            'umkm' => '#4CAF50',
            'iconic' => '#2196F3',
            'heritage' => '#795548',
            'hidden_gem' => '#9C27B0',
            'hotel' => '#FF9800',
            'transit' => '#607D8B',
            default => '#9E9E9E',
        };
    }

    /**
     * Format destination untuk response.
     */
    private function formatDestination($destination, bool $detail = false): array
    {
        $data = [
            'id' => $destination->id,
            'place_id' => $destination->place_id,
            'name' => $destination->name,
            'slug' => $destination->slug,
            'category' => $destination->category,
            'city' => $destination->city,
            'address' => $destination->address ?? null,
            'lat' => $destination->lat,
            'lng' => $destination->lng,
            'rating' => $destination->rating,
            'user_rating_count' => $destination->user_rating_count,
            'price_tier' => $destination->price_tier,
            'price_range' => PlaceCacheService::priceTierRange($destination->price_tier),
            'photos' => $destination->photos ?? [],
        ];

        // Tambah info lengkap saat show detail
        if ($detail) {
            $data['description'] = $destination->description;
            $data['opening_hours'] = $destination->opening_hours;
            $data['phone'] = $destination->phone;
            $data['whatsapp'] = $destination->whatsapp;
            $data['official_url'] = $destination->official_url;
            $data['ai_microstory'] = $destination->ai_microstory;
            $data['cached_at'] = $destination->cached_at;
            $data['detail_fetched_at'] = $destination->detail_fetched_at;
        }

        return $data;
    }
}
