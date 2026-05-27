<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FetchItineraryLegsJob;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\ItineraryRequest;
use App\Models\TransportMode;
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

        $itinerary = $item->day->itinerary;
        $numPeople = (int) ($itinerary->request?->num_people ?? 1);
        $previousCost = (float) $item->cost_estimate;
        $previousStatus = (string) $item->status;
        $statusProvided = array_key_exists('status', $payload);
        $newStatus = $statusProvided ? (string) $payload['status'] : $previousStatus;
        $newCost = $destination ? $this->estimateCost($destination, $numPeople) : $previousCost;

        DB::transaction(function () use ($item, $destination, $previousCost, $previousStatus, $itinerary, $statusProvided, $newStatus, $newCost) {
            $updates = [];

            if ($destination) {
                $updates['destination_id'] = $destination->id;
                $updates['cost_estimate'] = $newCost;
            }

            if ($statusProvided) {
                $updates['status'] = $newStatus;
            }

            if ($updates !== []) {
                $item->update($updates);
            }

            if ($destination) {
                ItineraryLeg::where('from_item_id', $item->id)
                    ->orWhere('to_item_id', $item->id)
                    ->delete();

                $day = $item->day;
                $day->update(['estimated_cost' => (float) $day->items()->sum('cost_estimate')]);
            }

            $delta = 0.0;

            if ($previousStatus === 'pending' && $newStatus === 'pending') {
                $delta = $newCost - $previousCost;
            } elseif ($previousStatus === 'pending' && $newStatus === 'completed') {
                $delta = -$previousCost;
            } elseif ($previousStatus === 'completed' && $newStatus === 'pending') {
                $delta = $newCost;
            }

            if ($delta !== 0.0) {
                $itinerary->update([
                    'total_cost' => max(0, (float) $itinerary->total_cost + $delta),
                ]);
            }
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

    public function checkLocation(
        User $user,
        int $itineraryId,
        int $itemId,
        float $userLat,
        float $userLng
    ): array {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return [
                'error' => 'Destinasi tidak ditemukan.',
                'code' => 422,
            ];
        }

        $distance = $this->haversineDistance(
            $userLat,
            $userLng,
            (float) $destination->lat,
            (float) $destination->lng
        );

        return [
            'data' => [
                'is_within_radius' => $distance <= 100,
                'distance_meters' => (int) round($distance),
            ],
        ];
    }

    public function checkinPreview(User $user, int $itineraryId, int $itemId): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return [
                'error' => 'Destinasi tidak ditemukan.',
                'code' => 422,
            ];
        }

        $leg = ItineraryLeg::where('to_item_id', $resolved->id)->first();
        $distanceKm = (float) ($leg?->distance_km ?? 0);

        $transportModes = TransportMode::query()
            ->orderByDesc('eco_points_rate')
            ->get();

        return [
            'data' => [
                'destination' => [
                    'name' => $destination->name,
                    'address' => $destination->address,
                    'culture_points' => (int) ($destination->culture_points ?? 0),
                ],
                'leg_distance_km' => $distanceKm,
                'transport_modes' => $transportModes->map(fn (TransportMode $mode) => [
                    'id' => $mode->id,
                    'mode' => $mode->mode,
                    'label' => $mode->label,
                    'eco_points_rate' => (int) $mode->eco_points_rate,
                    'co2_per_km' => (float) $mode->co2_per_km,
                ])->all(),
            ],
        ];
    }

    public function checkin(User $user, int $itineraryId, int $itemId, int $transportModeId): array
    {
        $resolved = $this->resolveItem($user, $itineraryId, $itemId);

        if (is_array($resolved)) {
            return $resolved;
        }

        if ($resolved->status === 'completed') {
            return [
                'error' => 'Item itinerary sudah selesai.',
                'code' => 422,
            ];
        }

        $destination = $resolved->destination;

        if (! $destination) {
            return [
                'error' => 'Destinasi tidak ditemukan.',
                'code' => 422,
            ];
        }

        $transportMode = TransportMode::find($transportModeId);

        if (! $transportMode) {
            return [
                'error' => 'Transport mode tidak ditemukan.',
                'code' => 404,
            ];
        }

        $leg = ItineraryLeg::where('to_item_id', $resolved->id)->first();
        $distanceKm = (float) ($leg?->distance_km ?? 0);
        $ecoPoints = (int) round($distanceKm * (int) $transportMode->eco_points_rate);
        $culturePoints = (int) ($destination->culture_points ?? 0);
        $pathPoints = $ecoPoints + $culturePoints;

        $itinerary = $resolved->day?->itinerary;
        $previousCost = (float) $resolved->cost_estimate;

        DB::transaction(function () use (
            $resolved,
            $leg,
            $transportMode,
            $user,
            $ecoPoints,
            $culturePoints,
            $pathPoints,
            $itinerary,
            $previousCost
        ) {
            $resolved->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($leg) {
                $leg->update(['transport_mode_id' => $transportMode->id]);
            }

            if ($itinerary) {
                $itinerary->update([
                    'total_cost' => max(0, (float) $itinerary->total_cost - $previousCost),
                ]);
            }

            $user->increment('eco_points', $ecoPoints);
            $user->increment('culture_points', $culturePoints);
            $user->increment('path_points', $pathPoints);
        });

        $user->refresh();
        $resolved->loadMissing(['destination', 'day.itinerary.request']);
        $itinerary = $resolved->day?->itinerary?->fresh();

        return [
            'data' => [
                'item' => $this->formatItem($resolved),
                'points_earned' => [
                    'eco_points' => $ecoPoints,
                    'culture_points' => $culturePoints,
                    'path_points' => $pathPoints,
                ],
                'user_total_path_points' => (int) $user->path_points,
                'budget_snapshot' => $itinerary ? $this->buildBudgetSnapshot($itinerary) : null,
            ],
        ];
    }

    public function generateSmartSwaps(Itinerary $itinerary, int $currentDayNumber): array
    {
        $itinerary->loadMissing(['request']);

        $pendingItems = ItineraryItem::query()
            ->with(['day', 'destination'])
            ->where('status', 'pending')
            ->whereHas('day', function ($query) use ($itinerary, $currentDayNumber) {
                $query
                    ->where('itinerary_id', $itinerary->id)
                    ->where('day_number', '>=', $currentDayNumber);
            })
            ->get();

        if ($pendingItems->isEmpty()) {
            return ['data' => ['swaps' => []]];
        }

        $city = (string) ($pendingItems->first()?->destination?->city
            ?? $itinerary->request?->destination_label
            ?? '');
        $numPeople = (int) ($itinerary->request?->num_people ?? 1);

        $cheapAlternatives = Destination::query()
            ->where('price_tier', '<=', 2)
            ->when($city !== '', fn ($query) => $query->where('city', $city))
            ->limit(20)
            ->get();

        $pendingItemsString = $pendingItems->map(function (ItineraryItem $item) {
            $destination = $item->destination;
            $dayNumber = (int) ($item->day?->day_number ?? 0);
            $cost = (float) $item->cost_estimate;

            return implode('|', [
                $item->id,
                $dayNumber,
                $item->visit_time,
                $destination?->place_id,
                $destination?->name,
                $destination?->category,
                $cost,
            ]);
        })->implode("\n");

        $cheapAlternativesString = $cheapAlternatives->map(function (Destination $destination) use ($numPeople) {
            $cost = $this->estimateCost($destination, $numPeople);

            return implode('|', [
                $destination->place_id,
                $destination->name,
                $destination->category,
                $cost,
            ]);
        })->implode("\n");

        $prompt = <<<PROMPT
Kamu adalah asisten Smart Re-planner untuk itinerary travel.
Tugasmu adalah menganalisis jadwal yang tersisa dan mengganti beberapa destinasi dengan alternatif yang lebih murah untuk menghemat budget user.

Daftar Destinasi Tersisa Beserta Jadwal (Pending):
{$pendingItemsString}

Daftar Alternatif Destinasi (Price Tier 0-2):
{$cheapAlternativesString}

ATURAN MUTLAK:
1. Perhatikan 'day_number' dan 'visit_time' dari destinasi yang akan diganti. Pilih destinasi alternatif yang SANGAT COCOK dengan waktu kunjungan tersebut (misal: jika visit_time jam 12:00 atau 19:00, prioritaskan kategori tempat makan/kuliner. Jangan pilih tempat yang tutup pada jam tersebut).
2. Kamu BEBAS menentukan JUMLAH destinasi yang diganti dan MANA SAJA yang diganti dari daftar pending, asalkan logis, searah, dan menghemat budget.
3. DILARANG BERHALUSINASI. Destinasi pengganti HANYA BOLEH diambil dari "Daftar Alternatif Destinasi" di atas.
4. Nilai 'new_name' dan 'new_cost' HARUS persis dengan data Daftar Alternatif. Jangan mengarang harga.
5. Hitung "savings" = current_cost - new_cost.
6. Buat array "tags" (maksimal 2 item, contoh: "Kuliner Malam", "UMKM Hemat").
7. Berikan "reason" logis (1 kalimat singkat) mengapa tempat ini cocok menggantikannya di jam tersebut.
8. Output HARUS pure JSON tanpa markdown (tanpa kode fence ```).

Format Output JSON:
{
  "swaps": [
    {
      "original_item_id": 15,
      "original_name": "Nama Lama",
      "original_cost": 85000,
      "new_place_id": "ChIJ...",
      "new_name": "Nama Tempat Sesuai Daftar Alternatif",
      "new_cost": 20000,
      "savings": 65000,
      "tags": ["Tag 1", "Tag 2"],
      "reason": "Alasan..."
    }
  ]
}
PROMPT;

        $rawResponse = $this->gemini->generate($prompt);

        if ($rawResponse === null) {
            return [
                'error' => 'Gagal menghubungi AI.',
                'code' => 500,
            ];
        }

        $parsed = $this->parseGeminiResponse($rawResponse);

        if ($parsed === null) {
            return [
                'error' => 'Format rekomendasi tidak valid.',
                'code' => 422,
            ];
        }

        return ['data' => $parsed];
    }

    private function resolveItem(User $user, int $itineraryId, int $itemId): ItineraryItem|array
    {
        $item = ItineraryItem::with(['destination', 'day.itinerary.request'])
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

        return $item;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function formatItem(ItineraryItem $item): array
    {
        $destination = $item->destination;

        return [
            'id' => $item->id,
            'status' => $item->status,
            'completed_at' => $item->completed_at,
            'destination' => $destination ? [
                'place_id' => $destination->place_id,
                'name' => $destination->name,
                'address' => $destination->address,
                'culture_points' => (int) ($destination->culture_points ?? 0),
            ] : null,
        ];
    }

    private function buildBudgetSnapshot(Itinerary $itinerary): array
    {
        $totalBudget = (float) $itinerary->total_budget;
        $totalCost = (float) $itinerary->total_cost;

        return [
            'total_budget' => $totalBudget,
            'total_cost' => $totalCost,
            'remaining_budget' => max(0, $totalBudget - $totalCost),
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
                        'total_cost' => 0,
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

                        $itinerary->total_cost = (float) $itinerary->total_cost + $dayCost;

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
                            ]);
                        }
                    }

                    $itinerary->save();
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

        $budget = (float) ($itinerary->total_budget ?? $request?->budget ?? 0);
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
            'total_budget' => (float) $itinerary->total_budget,
            'total_cost' => (float) $itinerary->total_cost,
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

        $budget = (float) ($itinerary->total_budget ?? $request?->budget ?? 0);
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
            'total_budget' => (float) $itinerary->total_budget,
            'total_cost' => (float) $itinerary->total_cost,
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
                    'id' => $item->id,
                    'place_id' => $destination?->place_id,
                    'name' => $destination?->name,
                    'map_category' => $destination?->map_category,
                    'category' => $destination?->category,
                    'status' => $item->status,
                    'order_index' => $item->order_index,
                    'visit_time' => $item->visit_time,
                    'cost_estimate' => (float) $item->cost_estimate,
                    'cost_label' => $priceLabel,
                    'leg_to_next' => $leg ? [
                        'distance_km' => (float) $leg->distance_km,
                        'duration_min' => (int) $leg->duration_min,
                        'transport_mode' => $leg->transportMode?->mode,
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
