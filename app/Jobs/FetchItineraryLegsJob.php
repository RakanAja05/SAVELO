<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ItineraryLeg;
use App\Services\DistanceMatrixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class FetchItineraryLegsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private Collection $itineraries) {}

    public function handle(DistanceMatrixService $distanceMatrix): void
    {
        $this->itineraries->each(function ($itinerary) use ($distanceMatrix) {
            $itinerary->loadMissing(['days.items.destination']);

            foreach ($itinerary->days as $day) {
                $items = $day->items->sortBy('order_index')->values();

                for ($index = 0; $index < $items->count() - 1; $index++) {
                    $from = $items[$index];
                    $to = $items[$index + 1];

                    if (ItineraryLeg::where('from_item_id', $from->id)->exists()) {
                        continue;
                    }

                    $fromDestination = $from->destination;
                    $toDestination = $to->destination;

                    if (! $fromDestination || ! $toDestination) {
                        continue;
                    }

                    if ($fromDestination->lat === null || $fromDestination->lng === null) {
                        continue;
                    }

                    if ($toDestination->lat === null || $toDestination->lng === null) {
                        continue;
                    }

                    $result = $distanceMatrix->get(
                        (float) $fromDestination->lat,
                        (float) $fromDestination->lng,
                        (float) $toDestination->lat,
                        (float) $toDestination->lng
                    );

                    ItineraryLeg::create([
                        'from_item_id' => $from->id,
                        'to_item_id' => $to->id,
                        'distance_km' => (float) $result['distance_km'],
                        'duration_min' => (int) $result['duration_min'],
                        'transport_mode' => (string) $result['mode'],
                    ]);
                }
            }
        });
    }
}
