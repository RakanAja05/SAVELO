<?php

declare(strict_types=1);

namespace App\Services;

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
use Illuminate\Support\Facades\DB;

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

            return ['error' => 'Tidak ada varian itinerary yang valid.', 'code' => 422];
        }

        $finalized = $this->parser->finalizeRecommendation($variants);
        $parsed['variants'] = $finalized;

        $itineraries = $this->persister->persistItineraries($request, $parsed, $candidates);

        if ($itineraries === null) {
            $request->update(['status' => 'failed', 'gemini_raw_response' => ['raw' => $rawResponse, 'parsed' => $parsed]]);

            return ['error' => 'Gagal menyimpan itinerary.', 'code' => 500];
        }

        $request->update(['status' => 'completed', 'gemini_raw_response' => ['raw' => $rawResponse, 'parsed' => $parsed]]);

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($request),
                'itineraries' => $this->formatter->formatItineraries($itineraries, $request),
            ],
        ];
    }

    public function getRequestHistory(User $user, int $requestId): array
    {
        $request = ItineraryRequest::with(['itineraries.days.items.destination'])
            ->where('id', $requestId)
            ->where('user_id', $user->id)
            ->first();

        if (! $request) {
            return ['error' => 'Request tidak ditemukan.', 'code' => 404];
        }

        return [
            'data' => [
                'request' => $this->formatter->formatRequest($request),
                'itineraries' => $this->formatter->formatItineraries($request->itineraries, $request),
            ],
        ];
    }

    public function getItineraryDetail(User $user, int $itineraryId): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting', 'request'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->where('id', $itineraryId)
            ->first();

        if (! $itinerary) {
            return ['error' => 'Itinerary tidak ditemukan.', 'code' => 404];
        }

        $request = $itinerary->request;
        $variantMeta = $this->formatter->extractVariantMeta($request);
        $isRecommended = (bool) ($variantMeta[$itinerary->variant]['is_recommended'] ?? false);

        return ['data' => $this->formatter->formatItinerary($itinerary, $request, $isRecommended)];
    }

    public function getItineraryDay(User $user, int $itineraryId, int $dayNumber): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->where('id', $itineraryId)
            ->first();

        if (! $itinerary) {
            return ['error' => 'Itinerary tidak ditemukan.', 'code' => 404];
        }

        $day = $itinerary->days->firstWhere('day_number', $dayNumber);

        if (! $day) {
            return ['error' => 'Day tidak ditemukan.', 'code' => 404];
        }

        return ['data' => $this->formatter->formatDay($day)];
    }

    public function updateItem(User $user, int $itineraryId, int $itemId, array $payload): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $newPlaceId = $payload['place_id'] ?? null;

        if (! $newPlaceId) {
            return ['error' => 'place_id diperlukan.', 'code' => 422];
        }

        $newDestination = Destination::where('place_id', $newPlaceId)->first();

        if (! $newDestination) {
            return ['error' => 'Destinasi tidak ditemukan.', 'code' => 404];
        }

        $itinerary = $resolved->day?->itinerary;
        $day = $resolved->day;
        $oldCost = (float) $resolved->cost_estimate;

        DB::transaction(function () use ($resolved, $newDestination, $itinerary, $day, $oldCost) {
            $numPeople = $itinerary?->request?->num_people ?? 1;
            $newCost = $this->budgetCalculator->estimateCost($newDestination, (int) $numPeople);

            $resolved->update(['destination_id' => $newDestination->id, 'cost_estimate' => $newCost]);

            if ($day) {
                $day->update(['estimated_cost' => max(0, (float) $day->estimated_cost - $oldCost + $newCost)]);
            }

            if ($itinerary) {
                $totalEstimate = $itinerary->days->sum('estimated_cost');
                $itinerary->update(['total_cost' => $totalEstimate]);
            }
        });

        $resolved->loadMissing(['destination', 'day.itinerary']);
        $itinerary = $resolved->day?->itinerary?->fresh();

        return [
            'data' => [
                'item' => $this->formatter->formatItem($resolved),
                'budget_snapshot' => $itinerary ? $this->budgetCalculator->buildBudgetSnapshot($itinerary) : null,
            ],
        ];
    }

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

        $result = $this->checkinManager->checkLocation($userLat, $userLng, $destination);

        return ['data' => ['is_within_radius' => $result['is_near'], 'distance_meters' => $result['distance_meters']]];
    }

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

        $preview = $this->checkinManager->getCheckinPreview($resolved);

        return [
            'data' => array_merge($preview, [
                'destination' => [
                    'name' => $destination->name,
                    'address' => $destination->address,
                    'culture_points' => (int) ($destination->culture_points ?? 0),
                ],
            ]),
        ];
    }

    public function checkin(User $user, int $itineraryId, int $itemId, int $transportModeId): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        if ($resolved->status === 'completed') {
            return ['error' => 'Item itinerary sudah selesai.', 'code' => 422];
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
