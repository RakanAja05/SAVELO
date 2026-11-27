<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Destination;
use App\Services\PlaceCache\PriceTierResolver;
use Illuminate\Support\Collection;

class PromptBuilder
{
    public function __construct(
        private PriceTierResolver $priceTierResolver,
    ) {}

    /**
     * Select a balanced set of candidate places across price tiers and hotels.
     *
     * @param  Collection<int, Destination>  $places
     * @return Collection<int, Destination>
     */
    public function selectCandidates(Collection $places): Collection
    {
        $hotels = $places->where('category', 'hotel')
            ->sortByDesc('rating')
            ->take(5)
            ->values();

        $cheap = $places->whereNotIn('category', ['hotel'])
            ->whereIn('price_tier', ['free', 'budget'])
            ->sortByDesc('rating')
            ->take(8)
            ->values();

        $mid = $places->whereNotIn('category', ['hotel'])
            ->where('price_tier', 'mid')
            ->sortByDesc('rating')
            ->take(6)
            ->values();

        $premium = $places->whereNotIn('category', ['hotel'])
            ->whereIn('price_tier', ['premium', 'luxury'])
            ->sortByDesc('rating')
            ->take(6)
            ->values();

        return $hotels->concat($cheap)->concat($mid)->concat($premium);
    }

    /**
     * Build the Gemini prompt for generating 3 budget-constrained itinerary variants.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, Destination>  $candidates
     */
    public function buildPrompt(array $payload, Collection $candidates): string
    {
        $lines = $candidates->map(function (Destination $destination) {
            $range = $this->priceTierResolver->priceTierRange($destination->price_tier);
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

        $budget = (float) $payload['budget'];
        $hematMax = $budget * 0.75;
        $seimbangMin = $budget * 0.50;
        $seimbangMax = $budget * 0.85;
        $expMin = $budget * 0.80;

        $strBudget = number_format($budget, 0, '.', '');
        $strHematMax = number_format($hematMax, 0, '.', '');
        $strSeimbangMin = number_format($seimbangMin, 0, '.', '');
        $strSeimbangMax = number_format($seimbangMax, 0, '.', '');
        $strExpMin = number_format($expMin, 0, '.', '');

        return <<<PROMPT
    KAMU WAJIB MENGIKUTI SEMUA ATURAN PERSIS. JIKA ADA SATU SAJA PELANGGARAN, HASIL DIANGGAP TIDAK VALID.

    Variabel konteks:
    - origin: {$payload['origin']}
    - destination_label: {$payload['destination_label']}
    - duration_days: {$payload['duration_days']}
    - num_people: {$payload['num_people']}
    - budget_total: {$strBudget}

    Daftar tempat yang tersedia (satu baris per tempat):
    Format: place_id|name|category|min_cost_per_person|max_cost_per_person
    {$lines}

    Buat TEPAT 3 varian dengan key: "hemat", "seimbang", dan "experience".

    Aturan keras:
    1) STRUKTUR HARIAN (KUANTITAS TEMPAT)
        - SEMUA VARIAN WAJIB memiliki 3 hingga 5 destinasi per harinya (DILARANG HANYA 1 ATAU 2 TEMPAT).
        - Untuk SETIAP HARI, item TERAKHIR (order_index paling besar) WAJIB hotel (category = "hotel") dengan visit_time sekitar "21:00".
        - Semua item SEBELUM hotel WAJIB destinasi non-hotel (category != "hotel").
    2) BUDGET & BIAYA (SANGAT KRITIKAL)
        - Kalikan biaya per person dengan num_people ({$payload['num_people']}).
        - Varian "hemat": WAJIB pilih mayoritas tempat murah/gratis. TOTAL BIAYA HARUS DI BAWAH {$strHematMax}.
        - Varian "seimbang": TOTAL BIAYA HARUS BERADA DI ANTARA {$strSeimbangMin} hingga {$strSeimbangMax}.
        - Varian "experience": WAJIB pilih tempat premium/mahal. TOTAL BIAYA HARUS BERADA DI ANTARA {$strExpMin} hingga {$strBudget}.
    3) PRIORITAS
        - Prioritaskan tempat dengan category "umkm" untuk varian hemat.
    4) METADATA
        - Setiap varian WAJIB memiliki: title (string), tags (array TEPAT 3 string; tiap tag MAKS 3 kata), score (integer).
    5) FORMAT KELUARAN
        - KELUARKAN murni JSON saja.

    Skema JSON keluaran (harus sama persis. Jumlah items HANYA CONTOH, buat dinamis 3-5 item per hari):
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
                        {"place_id": "...", "order_index": 3, "visit_time": "16:00"},
                        {"place_id": "...", "order_index": 4, "visit_time": "21:00"}
                    ]
                }
            ]
        },
        "seimbang": { ... bentuk sama ... },
        "experience": { ... bentuk sama ... }
    }
    PROMPT;
    }
}
