<?php

namespace App\Http\Controllers\Api\Favorite;

use App\Http\Controllers\Controller;
use App\Http\Requests\Favorite\StoreFavoriteRequest;
use App\Models\Destination;
use App\Services\PlaceCacheService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct(private PlaceCacheService $cache) {}

    /**
     * GET /api/favorites
     */
    public function index(Request $request): JsonResponse
    {
        $favorites = $request->user()
            ->favorites()
            ->get();

        return ApiResponse::success('Destinasi favorit.', [
            'total' => $favorites->count(),
            'destinations' => $favorites->map(fn (Destination $destination) => $this->formatDestination($destination)),
        ]);
    }

    /**
     * POST /api/favorites
     */
    public function store(StoreFavoriteRequest $request): JsonResponse
    {
        $destinationId = (int) $request->validated('destination_id');
        $request->user()->favorites()->syncWithoutDetaching([$destinationId]);

        $destination = Destination::find($destinationId);

        return ApiResponse::success('Destinasi ditambahkan ke favorit.', [
            'destination' => $destination ? $this->formatDestination($destination) : null,
        ]);
    }

    /**
     * DELETE /api/favorites/{destinationId}
     */
    public function destroy(Request $request, int $destinationId): JsonResponse
    {
        $request->user()->favorites()->detach($destinationId);

        return ApiResponse::success('Destinasi dihapus dari favorit.');
    }

    private function formatDestination(Destination $destination): array
    {
        return [
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
    }
}
