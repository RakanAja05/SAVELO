<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\ItineraryRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ItineraryPersister
{
    private const DEFAULT_ITEM_DURATION = 90;

    public function __construct(
        private BudgetCalculator $budgetCalculator,
    ) {}

    /**
     * Persist parsed itinerary variants to database.
     *
     * @param  array<string, mixed>  $parsed
     * @param  Collection<int, Destination>  $candidates
     * @return Collection<int, Itinerary>|null
     */
    public function persistItineraries(
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
                                'cost_estimate' => $this->budgetCalculator->estimateCost($destination, (int) $request->num_people),
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

    /**
     * Atomically update an itinerary item's destination and/or status and recalculate day/itinerary costs.
     */
    public function applyItemUpdate(
        ItineraryItem $item,
        ?Destination $destination,
        bool $statusProvided,
        string $newStatus
    ): Itinerary {
        $itinerary = $item->day->itinerary;
        $numPeople = (int) ($itinerary->request?->num_people ?? 1);
        $previousCost = (float) $item->cost_estimate;
        $previousStatus = (string) $item->status;
        $newCost = $destination ? $this->budgetCalculator->estimateCost($destination, $numPeople) : $previousCost;

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

            $delta = $this->budgetCalculator->calculateStatusCostDelta(
                $previousStatus,
                $newStatus,
                $previousCost,
                $newCost
            );

            if ($delta !== 0.0) {
                $itinerary->update([
                    'total_cost' => max(0, (float) $itinerary->total_cost + $delta),
                ]);
            }
        });

        $itinerary->loadMissing(['request', 'days.items.destination', 'days.items.legDeparting']);

        return $itinerary;
    }
}
