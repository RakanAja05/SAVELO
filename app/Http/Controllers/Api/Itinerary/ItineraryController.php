<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Itinerary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Itinerary\GenerateItineraryRequest;
use App\Http\Requests\Itinerary\UpdateItineraryItemRequest;
use App\Models\Itinerary;
use App\Models\User;
use App\Services\ItineraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItineraryController extends Controller
{
    public function __construct(private ItineraryService $service) {}

    /**
     * POST /api/itineraries/generate
     */
    public function generate(GenerateItineraryRequest $request): JsonResponse
    {
        $result = $this->service->generate($request->user(), $request->validated());

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Itinerary berhasil dibuat.', $result['data']);
    }

    /**
     * GET /api/itineraries/requests/{requestId}
     */
    public function requestHistory(int $requestId): JsonResponse
    {
        $result = $this->service->getRequestHistory($this->getUser(), $requestId);

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 404
            );
        }

        return ApiResponse::success('Riwayat itinerary.', $result['data']);
    }

    /**
     * GET /api/itineraries/{itineraryId}
     */
    public function show(int $itineraryId): JsonResponse
    {
        $result = $this->service->getItineraryDetail($this->getUser(), $itineraryId);

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 404
            );
        }

        return ApiResponse::success('Detail itinerary.', $result['data']);
    }

    /**
     * GET /api/itineraries/{itineraryId}/days/{dayNumber}
     */
    public function dayPlan(int $itineraryId, int $dayNumber): JsonResponse
    {
        $result = $this->service->getItineraryDay($this->getUser(), $itineraryId, $dayNumber);

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 404
            );
        }

        return ApiResponse::success('Rencana itinerary per hari.', $result['data']);
    }

    /**
     * PATCH /api/itineraries/{itineraryId}/{itemId}
     */
    public function updateItem(UpdateItineraryItemRequest $request, int $itineraryId, int $itemId): JsonResponse
    {
        $result = $this->service->updateItem(
            $this->getUser(),
            $itineraryId,
            $itemId,
            $request->validated()
        );

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Item itinerary berhasil diperbarui.', $result['data']);
    }

    /**
     * POST /api/itineraries/{itineraryId}/items/{itemId}/check-location
     */
    public function checkLocation(Request $request, int $itineraryId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'user_lat' => ['required', 'numeric'],
            'user_lng' => ['required', 'numeric'],
        ]);

        $result = $this->service->checkLocation(
            $this->getUser(),
            $itineraryId,
            $itemId,
            (float) $validated['user_lat'],
            (float) $validated['user_lng']
        );

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Cek lokasi berhasil.', $result['data']);
    }

    /**
     * GET /api/itineraries/{itineraryId}/items/{itemId}/checkin-preview
     */
    public function checkinPreview(int $itineraryId, int $itemId): JsonResponse
    {
        $result = $this->service->checkinPreview($this->getUser(), $itineraryId, $itemId);

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Preview check-in.', $result['data']);
    }

    /**
     * PATCH /api/itineraries/{itineraryId}/items/{itemId}/checkin
     */
    public function checkin(Request $request, int $itineraryId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'transport_mode_id' => ['required', 'integer', 'exists:transport_modes,id'],
        ]);

        $result = $this->service->checkin(
            $this->getUser(),
            $itineraryId,
            $itemId,
            (int) $validated['transport_mode_id']
        );

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Check-in berhasil.', $result['data']);
    }

    /**
     * POST /api/itineraries/{itineraryId}/smart-swaps
     */
    public function generateSmartSwaps(Request $request, int $itineraryId): JsonResponse
    {
        $validated = $request->validate([
            'current_day_number' => ['required', 'integer'],
        ]);

        $itinerary = Itinerary::query()
            ->with('request')
            ->whereHas('request', fn ($query) => $query->where('user_id', $this->getUser()->id))
            ->find($itineraryId);

        if (! $itinerary) {
            return ApiResponse::error('Itinerary tidak ditemukan.', null, 404);
        }

        $result = $this->service->generateSmartSwaps(
            $itinerary,
            (int) $validated['current_day_number']
        );

        if (! empty($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['details'] ?? null,
                $result['code'] ?? 400
            );
        }

        return ApiResponse::success('Saran penghematan berhasil dibuat.', $result['data'], 200);
    }

    private function getUser(): User
    {
        return request()->user();
    }
}
