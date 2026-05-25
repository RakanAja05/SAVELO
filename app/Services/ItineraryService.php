<?php

namespace App\Services;

use App\Jobs\FetchItineraryLegsJob;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\ItineraryRequest;
use App\Models\User;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ItineraryService
{
    private const DEFAULT_ITEM_DURATION = 90;

    private const DEFAULT_ECO_POINTS = 200;

    public function __construct(
        private PlaceCacheService $places,
        private GeminiService $gemini,
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

            return [
                'error' => $placesResult['error'],
                'code' => 404,
            ];
        }

        $candidates = $this->selectCandidates($placesResult['places']);

        if ($candidates->isEmpty()) {
            $request->update(['status' => 'failed']);

            return [
                'error' => 'Destinasi belum tersedia untuk kota ini.',
                'code' => 404,
            ];
        }
        $prompt = $this->buildPrompt($payload, $candidates);
        $rawResponse = $this->gemini->generate($prompt);

        if ($rawResponse === null) {
            $request->update(['status' => 'failed']);

            return [
                'error' => 'Gagal membuat itinerary, coba lagi nanti.',
                'code' => 500,
            ];
        }

        $parsed = $this->parseGeminiResponse($rawResponse);

        if ($parsed === null) {
            $request->update([
                'status' => 'failed',
                'gemini_raw_response' => ['raw' => $rawResponse],
            ]);

            return [
                'error' => 'Format itinerary tidak valid.',
                'code' => 422,
            ];
        }

        $variants = $this->normalizeVariants($parsed);

        if ($variants === []) {
            $request->update([
                'status' => 'failed',
                'gemini_raw_response' => ['raw' => $rawResponse],
            ]);

            return [
                'error' => 'Format itinerary tidak valid.',
                'code' => 422,
            ];
        }

        $variants = $this->finalizeRecommendation($variants);
        $parsed = ['variants' => $variants];

        $request->update([
            'status' => 'generated',
            'gemini_raw_response' => [
                'raw' => $rawResponse,
                'parsed' => $parsed,
            ],
        ]);

        $itineraries = $this->persistItineraries($request, $parsed, $candidates);

        if ($itineraries === null) {
            $request->update(['status' => 'failed']);

            return [
                'error' => 'Gagal menyimpan itinerary.',
                'code' => 500,
            ];
        }

        $request->update(['status' => 'completed']);

        FetchItineraryLegsJob::dispatch($itineraries);

        return [
            'data' => [
                'request' => $this->formatRequest($request->fresh()),
                'itineraries' => $this->formatItineraries($itineraries, $request),
            ],
        ];
    }

    public function getRequestHistory(User $user, int $requestId): array
    {
        $request = ItineraryRequest::with(['itineraries.days.items.destination'])
            ->where('user_id', $user->id)
            ->find($requestId);

        if (! $request) {
            return [
                'error' => 'Itinerary tidak ditemukan.',
                'code' => 404,
            ];
        }

        return [
            'data' => [
                'request' => $this->formatRequest($request),
                'itineraries' => $this->formatItineraries($request->itineraries, $request),
            ],
        ];
    }

    public function getItineraryDetail(User $user, int $itineraryId): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting', 'request'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->find($itineraryId);

        if (! $itinerary) {
            return [
                'error' => 'Itinerary tidak ditemukan.',
                'code' => 404,
            ];
        }

        return [
            'data' => [
                'request' => $this->formatRequest($itinerary->request),
                'itinerary' => $this->formatItinerary($itinerary, $itinerary->request, true),
            ],
        ];
    }

    public function getItineraryDay(User $user, int $itineraryId, int $dayNumber): array
    {
        $itinerary = Itinerary::with(['days.items.destination', 'days.items.legDeparting', 'request'])
            ->whereHas('request', fn ($query) => $query->where('user_id', $user->id))
            ->find($itineraryId);

        if (! $itinerary) {
            return [
                'error' => 'Itinerary tidak ditemukan.',
                'code' => 404,
            ];
        }

        $day = $itinerary->days->firstWhere('day_number', $dayNumber);

        if (! $day) {
            return [
                'error' => 'Rencana hari tidak ditemukan.',
                'code' => 404,
            ];
        }

        return [
            'data' => [
                'request' => $this->formatRequest($itinerary->request),
                'itinerary' => $this->formatItinerarySummary($itinerary, $itinerary->request),
                'day' => $this->formatDay($day),
            ],
        ];
    }

    public function updateItem(User $user, int $itineraryId, int $itemId, array $payload): array
    {
        $item = ItineraryItem::with(['day.itinerary.request'])
            ->where('id', $itemId)
            ->whereHas('day.itinerary', fn ($query) => $query->where('id', $itineraryId))
            ->whereHas('day.itinerary.request', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (! $item) {
            return [
                'error' => 'Item itinerary tidak ditemukan.',
                'code' => 404,
            ];
        }

        $destination = Destination::where('place_id', $payload['place_id'])->first();

        if (! $destination) {
            return [
                'error' => 'Destinasi tidak ditemukan.',
                'details' => ['place_id' => $payload['place_id']],
                'code' => 422,
            ];
        }

        $itinerary = $item->day->itinerary;
        $numPeople = (int) ($itinerary->request?->num_people ?? 1);

        DB::transaction(function () use ($item, $destination, $numPeople) {
            $item->update([
                'destination_id' => $destination->id,
                'cost_estimate' => $this->estimateCost($destination, $numPeople),
            ]);

            ItineraryLeg::where('from_item_id', $item->id)
                ->orWhere('to_item_id', $item->id)
                ->delete();

            $day = $item->day;
            $day->update(['estimated_cost' => (float) $day->items()->sum('cost_estimate')]);
        });

        $itinerary->loadMissing(['request', 'days.items.destination', 'days.items.legDeparting']);

        FetchItineraryLegsJob::dispatch(collect([$itinerary]));

        return [
            'data' => [
                'request' => $this->formatRequest($itinerary->request),
                'itinerary' => $this->formatItinerary($itinerary, $itinerary->request, true),
            ],
        ];
    }

    private function selectCandidates(Collection $places): Collection
    {
        return $places
            ->sortByDesc('rating')
            ->values()
            ->take(12);
    }

    private function buildPrompt(array $payload, Collection $candidates): string
    {
        $lines = $candidates->map(function (Destination $destination) {
            $range = PlaceCacheService::priceTierRange($destination->price_tier);
            $min = $range['min'] ?? 0;
            $max = $range['max'] ?? 0;

            return implode('|', [
                $destination->place_id,
                $destination->name,
                $destination->category,
                $min,
                $max,
            ]);
        })->implode("\n");

        $budget = number_format((float) $payload['budget'], 0, '.', '');

        return <<<PROMPT
KAMU WAJIB MENGIKUTI SEMUA ATURAN PERSIS. JIKA ADA SATU SAJA PELANGGARAN, HASIL DIANGGAP TIDAK VALID.

Variabel konteks:
- origin: {$payload['origin']}
- destination_label: {$payload['destination_label']}
- duration_days: {$payload['duration_days']}
- num_people: {$payload['num_people']}
- budget: {$budget}

Daftar tempat yang tersedia (satu baris per tempat):
Format: place_id|name|category|min_cost_per_person|max_cost_per_person
{$lines}

Buat TEPAT 3 varian dengan key: "hemat", "seimbang", dan "experience".

Aturan keras:
1) STRUKTUR HARIAN
     - Untuk setiap hari 1..{$payload['duration_days']}, wajib ada TEPAT 4 item.
     - order_index 1, 2, 3 WAJIB destinasi non-hotel (category != "hotel").
     - order_index 4 WAJIB hotel (category = "hotel") dengan visit_time tepat "21:00".
2) BUDGET & BIAYA
     - Untuk setiap item, estimasi biaya per orang menggunakan rentang min/max yang tersedia.
     - Kalikan biaya per orang dengan num_people ({$payload['num_people']}) untuk mendapatkan total biaya item.
     - Biaya hotel WAJIB dihitung dalam total budget.
     - Total biaya setiap varian TIDAK BOLEH melebihi {$budget}.
3) PRIORITAS
     - Prioritaskan tempat dengan category "umkm" untuk item non-hotel jika memungkinkan.
4) METADATA
     - Setiap varian WAJIB memiliki: title (string), tags (array TEPAT 3 string; tiap tag MAKS 3 kata), score (integer).
5) FORMAT KELUARAN
     - KELUARKAN murni JSON saja. Tanpa markdown, tanpa code fence, tanpa teks tambahan.

Skema JSON keluaran (harus sama persis):
{
    "hemat": {
        "title": "...",
        "tags": ["...", "...", "..."],
        "score": 0,
        "total_cost": 0,
        "days": [
            {
                "day_number": 1,
                "items": [
                    {"place_id": "...", "order_index": 1, "visit_time": "08:30"},
                    {"place_id": "...", "order_index": 2, "visit_time": "12:30"},
                    {"place_id": "...", "order_index": 3, "visit_time": "17:00"},
                    {"place_id": "...", "order_index": 4, "visit_time": "21:00"}
                ]
            }
        ]
    },
    "seimbang": { ... bentuk sama ... },
    "experience": { ... bentuk sama ... }
}

Ringkasan aturan (tanpa pengecualian):
- Gunakan HANYA place_id dari daftar yang diberikan.
- Setiap hari TEPAT 4 item, hotel di order_index 4 pada "21:00".
- Total biaya per varian <= {$budget} dan termasuk biaya hotel.
- TEPAT 3 varian: hemat, seimbang, experience.
- Tags jumlah = 3, tiap tag maksimal 3 kata.
- Output JSON saja.
PROMPT;
    }

    private function parseGeminiResponse(string $response): ?array
    {
        $clean = trim($response);

        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $firstBrace = strpos($clean, '{');
        $lastBrace = strrpos($clean, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $clean = substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function finalizeRecommendation(array $variants): array
    {
        $maxScore = null;
        $recommendedIndex = null;

        foreach ($variants as $index => $variant) {
            $score = is_numeric($variant['score'] ?? null)
                ? (float) $variant['score']
                : null;

            if ($score === null) {
                continue;
            }

            if ($maxScore === null || $score > $maxScore) {
                $maxScore = $score;
                $recommendedIndex = $index;
            }
        }

        if ($recommendedIndex === null) {
            $recommendedIndex = 0;
        }

        foreach ($variants as $index => $variant) {
            $variants[$index]['is_recommended'] = $index === $recommendedIndex;
        }

        return $variants;
    }

    private function normalizeVariants(array $parsed): array
    {
        if (isset($parsed['variants']) && is_array($parsed['variants'])) {
            return $parsed['variants'];
        }

        $variants = [];
        $keys = ['hemat', 'seimbang', 'experience'];

        foreach ($keys as $key) {
            $variant = $parsed[$key] ?? null;

            if (! is_array($variant)) {
                continue;
            }

            $variant['variant'] = $key;
            $variants[] = $variant;
        }

        return $variants;
    }

    private function persistItineraries(
        ItineraryRequest $request,
        array $parsed,
        Collection $candidates
    ): ?Collection {
        $variants = $parsed['variants'] ?? null;

        if (! is_array($variants) || $variants === []) {
            return null;
        }

        $destinationMap = $candidates->keyBy('place_id');
        $itineraryIds = [];
        $createdItemCount = 0;

        try {
            DB::transaction(function () use ($variants, $request, $destinationMap, &$itineraryIds, &$createdItemCount) {
                foreach ($variants as $variant) {
                    $variantKey = (string) ($variant['variant'] ?? 'custom');
                    $title = (string) ($variant['title'] ?? Str::title($variantKey));

                    $itinerary = Itinerary::create([
                        'request_id' => $request->id,
                        'variant' => $variantKey,
                        'title' => $title,
                        'total_budget' => (float) ($variant['total_budget'] ?? $request->budget),
                        'status' => 'generated',
                    ]);

                    $itineraryIds[] = $itinerary->id;

                    $days = $variant['days'] ?? [];

                    foreach ($days as $dayIndex => $dayData) {
                        $dayNumber = (int) ($dayData['day_number'] ?? $dayIndex + 1);

                        $day = ItineraryDay::create([
                            'itinerary_id' => $itinerary->id,
                            'day_number' => $dayNumber,
                            'estimated_cost' => 0,
                        ]);

                        $items = $dayData['items'] ?? [];
                        $createdItemsByOrder = [];
                        $dayCost = 0;

                        foreach ($items as $itemIndex => $itemData) {
                            $placeId = (string) ($itemData['place_id'] ?? '');
                            $destination = $destinationMap->get($placeId);

                            if (! $destination) {
                                continue;
                            }

                            $item = ItineraryItem::create([
                                'itinerary_day_id' => $day->id,
                                'destination_id' => $destination->id,
                                'order_index' => (int) ($itemData['order_index'] ?? $itemIndex + 1),
                                'visit_time' => (string) ($itemData['visit_time'] ?? '09:00'),
                                'duration_minutes' => (int) ($itemData['duration_minutes'] ?? self::DEFAULT_ITEM_DURATION),
                                'cost_estimate' => $this->estimateCost($destination, (int) $request->num_people),
                                'notes' => $itemData['notes'] ?? null,
                            ]);

                            $createdItemsByOrder[$item->order_index] = $item;
                            $createdItemCount++;
                            $dayCost += (float) $item->cost_estimate;
                        }

                        $day->update(['estimated_cost' => $dayCost]);

                        foreach (($dayData['legs'] ?? []) as $legData) {
                            $fromOrder = (int) ($legData['from_order'] ?? 0);
                            $toOrder = (int) ($legData['to_order'] ?? 0);
                            $fromItem = $createdItemsByOrder[$fromOrder] ?? null;
                            $toItem = $createdItemsByOrder[$toOrder] ?? null;

                            if (! $fromItem || ! $toItem) {
                                continue;
                            }

                            ItineraryLeg::create([
                                'from_item_id' => $fromItem->id,
                                'to_item_id' => $toItem->id,
                                'distance_km' => (float) ($legData['distance_km'] ?? 0),
                                'duration_min' => (int) ($legData['duration_min'] ?? 0),
                                'transport_mode' => (string) ($legData['transport_mode'] ?? 'unknown'),
                            ]);
                        }
                    }
                }

                if ($createdItemCount === 0) {
                    throw new \RuntimeException('No itinerary items created.');
                }
            });
        } catch (\Throwable $e) {
            Log::error('ItineraryService: persist failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($itineraryIds === []) {
            return null;
        }

        return Itinerary::with(['days.items.destination'])
            ->whereIn('id', $itineraryIds)
            ->get();
    }

    private function formatRequest(ItineraryRequest $request): array
    {
        return [
            'id' => $request->id,
            'origin' => $request->origin,
            'destination_label' => $request->destination_label,
            'duration_days' => $request->duration_days,
            'num_people' => $request->num_people,
            'budget' => (float) $request->budget,
            'status' => $request->status,
        ];
    }

    private function formatItinerarySummary(Itinerary $itinerary, ?ItineraryRequest $request): array
    {
        $itinerary->loadMissing(['days.items.destination']);

        $budget = (float) ($request?->budget ?? 0);
        $variantMeta = $this->extractVariantMeta($request);
        $meta = $variantMeta[$itinerary->variant] ?? [];
        $isRecommended = (bool) ($meta['is_recommended'] ?? false);

        $items = $itinerary->days->flatMap(fn (ItineraryDay $day) => $day->items);
        $totalEstimate = $itinerary->days->sum('estimated_cost');
        $budgetPercent = $budget > 0 ? round(($totalEstimate / $budget) * 100) : 0;
        $tags = $this->normalizeTags($meta['tags'] ?? []) ?: $this->buildSummaryTags($items);

        return [
            'id' => $itinerary->id,
            'variant' => $itinerary->variant,
            'title' => $itinerary->title,
            'total_estimate' => (float) $totalEstimate,
            'budget_percent' => $budgetPercent,
            'summary' => [
                'tags' => $tags,
                'eco_points' => self::DEFAULT_ECO_POINTS,
                'is_recommended' => $isRecommended,
            ],
            // Tidak ada 'days' — hanya summary card
        ];
    }

    private function formatItineraries(Collection $itineraries, ?ItineraryRequest $request): array
    {
        return $itineraries->values()->map(function (Itinerary $itinerary) use ($request) {
            return $this->formatItinerarySummary($itinerary, $request);
        })->all();
    }

    private function formatItinerary(Itinerary $itinerary, ?ItineraryRequest $request, bool $isRecommended): array
    {
        $itinerary->loadMissing(['days.items.destination', 'days.items.legDeparting']);

        $budget = (float) ($request?->budget ?? 0);
        $variantMeta = $this->extractVariantMeta($request);
        $meta = $variantMeta[$itinerary->variant] ?? [];

        $items = $itinerary->days->flatMap(fn (ItineraryDay $day) => $day->items);
        $totalEstimate = $itinerary->days->sum('estimated_cost');
        $budgetPercent = $budget > 0 ? round(($totalEstimate / $budget) * 100) : 0;
        $tags = $this->normalizeTags($meta['tags'] ?? []) ?: $this->buildSummaryTags($items);

        return [
            'id' => $itinerary->id,
            'variant' => $itinerary->variant,
            'title' => $itinerary->title,
            'total_estimate' => (float) $totalEstimate,
            'budget_percent' => $budgetPercent,
            'summary' => [
                'tags' => $tags,
                'eco_points' => self::DEFAULT_ECO_POINTS,
                'is_recommended' => (bool) ($meta['is_recommended'] ?? false),
            ],
            'days' => $itinerary->days->sortBy('day_number')->values()->map(
                fn (ItineraryDay $day) => $this->formatDay($day)
            )->all(),
        ];
    }

    private function formatDay(ItineraryDay $day): array
    {
        return [
            'day_number' => $day->day_number,
            'estimated_cost' => (float) $day->estimated_cost,
            'items' => $day->items->sortBy('order_index')->values()->map(function (ItineraryItem $item) {
                $destination = $item->destination;
                $priceLabel = $this->formatPriceLabel((float) $item->cost_estimate);
                $leg = $item->legDeparting;

                return [
                    'place_id' => $destination?->place_id,
                    'name' => $destination?->name,
                    'map_category' => $destination?->map_category,
                    'category' => $destination?->category,
                    'order_index' => $item->order_index,
                    'visit_time' => $item->visit_time,
                    'cost_estimate' => (float) $item->cost_estimate,
                    'cost_label' => $priceLabel,
                    'leg_to_next' => $leg ? [
                        'distance_km' => (float) $leg->distance_km,
                        'duration_min' => (int) $leg->duration_min,
                        'transport_mode' => (string) $leg->transport_mode,
                    ] : null,
                ];
            })->all(),
        ];
    }

    private function estimateCost(Destination $destination, int $numPeople): float
    {
        $range = PlaceCacheService::priceTierRange($destination->price_tier);
        $min = $range['min'] ?? 0;

        return (float) $min * max(1, $numPeople);
    }

    private function extractVariantMeta(?ItineraryRequest $request): array
    {
        if (! $request) {
            return [];
        }

        $parsed = $request->gemini_raw_response['parsed'] ?? null;

        if (! is_array($parsed)) {
            return [];
        }

        $variants = $parsed['variants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        $meta = [];

        foreach ($variants as $variant) {
            $key = (string) ($variant['variant'] ?? '');

            if ($key === '') {
                continue;
            }

            $meta[$key] = [
                'is_recommended' => $variant['is_recommended'] ?? false,
                'tags' => $variant['tags'] ?? [],
            ];
        }

        return $meta;
    }

    private function buildSummaryTags(Collection $items): array
    {
        $iconicCount = $items->filter(
            fn (ItineraryItem $item) => $item->destination?->map_category === 'iconic'
        )->count();

        $iconicTag = $iconicCount.' destinasi ikonik';
        $transportTag = 'Mix taxi + transit';
        $ecoTag = 'Eco +'.self::DEFAULT_ECO_POINTS.' pts';

        return [$iconicTag, $transportTag, $ecoTag];
    }

    private function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn ($tag) => is_string($tag) && $tag !== '')
            ->values()
            ->take(3)
            ->all();
    }

    private function formatPriceLabel(float $cost): string
    {
        if ($cost <= 0) {
            return 'Gratis';
        }

        return 'Rp '.number_format($cost, 0, ',', '.');
    }
}
