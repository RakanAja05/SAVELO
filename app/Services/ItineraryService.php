<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FetchItineraryLegsJob;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\ItineraryRequest;
use App\Models\TransportMode;
use App\Models\User;
use App\Services\Gemini\GeminiService;
use App\Services\Itinerary\BudgetCalculator;
use App\Services\Itinerary\CheckinManager;
use App\Services\Itinerary\ItineraryFormatter;
use App\Services\Itinerary\ItineraryParser;
use App\Services\Itinerary\ItineraryPersister;
use App\Services\Itinerary\PromptBuilder;

class ItineraryService
{
    public function __construct(
        private PlaceCacheService $places,
        private GeminiService $gemini,
        private PromptBuilder $promptBuilder,
        private ItineraryParser $parser,
        private ItineraryPersister $persister,
        private ItineraryFormatter $formatter,
        private BudgetCalculator $budgetCalculator,
        private CheckinManager $checkinManager,
    ) {}

    /**
     * Generate 3 itinerary variants (hemat, seimbang, experience) via Gemini and dispatch leg distance calculation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(User $user, array $payload): array
    {
        $request = ItineraryRequest::create([
            'user_id' => $user->id,
            'origin' => $payload['origin'],
            'destination_label' => $payload['destination_label'],
            'duration_days' => $payload['duration_days'],
            'num_people' => $payload['num_people'],
            'budget' => $payload['budget'],
            'status' => 'pending',
        ]);

        $placesResult = $this->places->getCityPlaces($payload['destination_label']);

        if (! empty($placesResult['error'])) {
            $request->update(['status' => 'failed']);

            return ['error' => $placesResult['error'], 'code' => 404];
        }

        $candidates = $this->promptBuilder->selectCandidates($placesResult['places']);

        if ($candidates->isEmpty()) {
            $request->update(['status' => 'failed']);

            return ['error' => 'Destinasi belum tersedia untuk kota ini.', 'code' => 404];
        }

        $prompt = $this->promptBuilder->buildPrompt($payload, $candidates);
        $rawResponse = $this->gemini->generate($prompt);

        if ($rawResponse === null) {
            $request->update(['status' => 'failed']);

            return ['error' => 'Gagal membuat itinerary, coba lagi nanti.', 'code' => 500];
        }

        $parsed = $this->parser->parseGeminiResponse($rawResponse);

        if ($parsed === null) {
            $request->update(['status' => 'failed', 'gemini_raw_response' => ['raw' => $rawResponse]]);

            return ['error' => 'Format itinerary tidak valid.', 'code' => 422];
        }

        $variants = $this->parser->normalizeVariants($parsed);

        if ($variants === []) {
            $request->update(['status' => 'failed', 'gemini_raw_response' => ['raw' => $rawResponse]]);

            return ['error' => 'Format itinerary tidak valid.', 'code' => 422];
        }

        $variants = $this->parser->finalizeRecommendation($variants);
        $parsed = ['variants' => $variants];

        $request->update([
            'status' => 'generated',
            'gemini_raw_response' => [
                'raw' => $rawResponse,
                'parsed' => $parsed,
            ],
        ]);

        $itineraries = $this->persister->persistItineraries($request, $parsed, $candidates);

        if ($itineraries === null) {
            $request->update(['status' => 'failed']);

            return ['error' => 'Gagal menyimpan itinerary.', 'code' => 500];
        }

        $request->update(['status' => 'completed']);

        FetchItineraryLegsJob::dispatch($itineraries);

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($request->fresh()),
                'itineraries' => $this->formatter->formatItineraries($itineraries, $request),
            ],
        ];
    }

    /**
     * Get generated itinerary variants for a user's request.
     *
     * @return array<string, mixed>
     */
    public function getRequestHistory(User $user, int $requestId): array
    {
        $request = ItineraryRequest::with(['itineraries.days.items.destination'])
            ->where('user_id', $user->id)
            ->find($requestId);

        if (! $request) {
            return ['error' => 'Itinerary tidak ditemukan.', 'code' => 404];
        }

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($request),
                'itineraries' => $this->formatter->formatItineraries($request->itineraries, $request),
            ],
        ];
    }

    /**
     * Get full detail for a specific itinerary owned by the user.
     *
     * @return array<string, mixed>
     */
    public function getItineraryDetail(User $user, int $itineraryId): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting', 'request'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->find($itineraryId);

        if (! $itinerary) {
            return ['error' => 'Itinerary tidak ditemukan.', 'code' => 404];
        }

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($itinerary->request),
                'itinerary' => $this->formatter->formatItinerary($itinerary, $itinerary->request, true),
            ],
        ];
    }

    /**
     * Get a single day plan for an itinerary owned by the user.
     *
     * @return array<string, mixed>
     */
    public function getItineraryDay(User $user, int $itineraryId, int $dayNumber): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting', 'request'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->find($itineraryId);

        if (! $itinerary) {
            return ['error' => 'Itinerary tidak ditemukan.', 'code' => 404];
        }

        $day = $itinerary->days->firstWhere('day_number', $dayNumber);

        if (! $day) {
            return ['error' => 'Rencana hari tidak ditemukan.', 'code' => 404];
        }

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($itinerary->request),
                'itinerary' => $this->formatter->formatItinerarySummary($itinerary, $itinerary->request),
                'day' => $this->formatter->formatDay($day),
            ],
        ];
    }

    /**
     * Update an itinerary item's destination and/or status and refresh affected travel legs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateItem(User $user, int $itineraryId, int $itemId, array $payload): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $destination = null;
        $placeId = $payload['place_id'] ?? null;

        if ($placeId !== null) {
            $destination = Destination::where('place_id', $placeId)->first();

            if (! $destination) {
                return [
                    'error' => 'Destinasi tidak ditemukan.',
                    'details' => ['place_id' => $placeId],
                    'code' => 422,
                ];
            }
        }

        $statusProvided = array_key_exists('status', $payload);
        $newStatus = $statusProvided ? (string) $payload['status'] : (string) $resolved->status;

        $itinerary = $this->persister->applyItemUpdate($resolved, $destination, $statusProvided, $newStatus);

        FetchItineraryLegsJob::dispatch(collect([$itinerary]));

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($itinerary->request),
                'itinerary' => $this->formatter->formatItinerary($itinerary, $itinerary->request, true),
            ],
        ];
    }

    /**
     * Verify whether user coordinates are within the check-in radius of an itinerary item.
     *
     * @return array<string, mixed>
     */
    public function checkLocation(User $user, int $itineraryId, int $itemId, float $userLat, float $userLng): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return ['error' => 'Destinasi tidak ditemukan.', 'code' => 422];
        }

        return ['data' => $this->checkinManager->checkLocation($userLat, $userLng, $destination)];
    }

    /**
     * Retrieve check-in preview data for an itinerary item.
     *
     * @return array<string, mixed>
     */
    public function checkinPreview(User $user, int $itineraryId, int $itemId): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return ['error' => 'Destinasi tidak ditemukan.', 'code' => 422];
        }

        return ['data' => $this->checkinManager->getCheckinPreview($resolved, $destination)];
    }

    /**
     * Complete check-in for an itinerary item and award PathPoints.
     *
     * @return array<string, mixed>
     */
    public function checkin(User $user, int $itineraryId, int $itemId, int $transportModeId): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        if ($resolved->status === 'completed') {
            return ['error' => 'Item itinerary sudah selesai.', 'code' => 422];
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return ['error' => 'Destinasi tidak ditemukan.', 'code' => 422];
        }

        $transportMode = TransportMode::find($transportModeId);

        if (! $transportMode) {
            return ['error' => 'Transport mode tidak ditemukan.', 'code' => 404];
        }

        $points = $this->checkinManager->processCheckin($resolved, $transportMode, $user);

        $user->refresh();
        $resolved->loadMissing(['destination', 'day.itinerary.request']);
        $itinerary = $resolved->day?->itinerary?->fresh();

        return [
            'data' => [
                'item' => $this->formatter->formatItem($resolved),
                'points_earned' => $points,
                'user_total_path_points' => (int) $user->path_points,
                'budget_snapshot' => $itinerary ? $this->budgetCalculator->buildBudgetSnapshot($itinerary) : null,
            ],
        ];
    }

    /**
     * Return clean 501 Not Implemented response for the unimplemented smart swaps prototype endpoint.
     *
     * @return array{error: string, code: int}
     */
    public function generateSmartSwaps(Itinerary $itinerary, int $currentDayNumber): array
    {
        return [
            'error' => 'Smart swaps belum diimplementasikan pada prototipe ini.',
            'code' => 501,
        ];
    }

    /**
     * @return ItineraryItem|array{error: string, code: int}
     */
    private function resolveItem(User $user, int $itineraryId, int $itemId): ItineraryItem|array
    {
        $item = ItineraryItem::with(['destination', 'day.itinerary.request'])
            ->where('id', $itemId)
            ->whereHas('day.itinerary', fn ($query) => $query->where('id', $itineraryId))
            ->whereHas('day.itinerary.request', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (! $item) {
            return ['error' => 'Item itinerary tidak ditemukan.', 'code' => 404];
        }

        return $item;
    }
}
