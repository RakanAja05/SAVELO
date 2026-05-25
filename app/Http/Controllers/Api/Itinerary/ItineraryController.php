<?php

namespace App\Http\Controllers\Api\Itinerary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Itinerary\GenerateItineraryRequest;
use App\Http\Requests\Itinerary\UpdateItineraryItemRequest;
use App\Models\User;
use App\Services\ItineraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

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

    private function getUser(): User
    {
        return request()->user();
    }
}
