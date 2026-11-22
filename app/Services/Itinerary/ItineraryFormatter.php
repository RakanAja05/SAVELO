<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryRequest;
use Illuminate\Support\Collection;

class ItineraryFormatter
{
    private const DEFAULT_ECO_POINTS = 200;

    public function __construct(
        private BudgetCalculator $budgetCalculator,
    ) {}

    public function formatRequest(ItineraryRequest $request): array
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

    public function formatItinerarySummary(Itinerary $itinerary, ?ItineraryRequest $request): array
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

    public function formatItineraries(Collection $itineraries, ?ItineraryRequest $request): array
    {
        return $itineraries->values()->map(function (Itinerary $itinerary) use ($request) {
            return $this->formatItinerarySummary($itinerary, $request);
        })->all();
    }

    public function formatItinerary(Itinerary $itinerary, ?ItineraryRequest $request, bool $isRecommended): array
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

    public function formatDay(ItineraryDay $day): array
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

    public function formatItem(ItineraryItem $item): array
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

    public function formatPriceLabel(float $cost): string
    {
        if ($cost <= 0) {
            return 'Gratis';
        }

        return 'Rp '.number_format($cost, 0, ',', '.');
    }

    public function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->filter(fn ($tag) => is_string($tag) && $tag !== '')
            ->values()
            ->take(3)
            ->all();
    }

    public function buildSummaryTags(Collection $items): array
    {
        $iconicCount = $items->filter(
            fn (ItineraryItem $item) => $item->destination?->map_category === 'iconic'
        )->count();

        $iconicTag = $iconicCount.' destinasi ikonik';
        $transportTag = 'Mix taxi + transit';
        $ecoTag = 'Eco +'.self::DEFAULT_ECO_POINTS.' pts';

        return [$iconicTag, $transportTag, $ecoTag];
    }

    public function extractVariantMeta(?ItineraryRequest $request): array
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
}
