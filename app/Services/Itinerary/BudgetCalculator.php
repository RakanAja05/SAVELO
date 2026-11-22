<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Services\PlaceCacheService;

class BudgetCalculator
{
    public function __construct(
        private PlaceCacheService $places,
    ) {}

    public function estimateCost(Destination $destination, int $numPeople): float
    {
        $range = PlaceCacheService::priceTierRange($destination->price_tier);
        $min = $range['min'] ?? 0;

        return (float) $min * max(1, $numPeople);
    }

    public function buildBudgetSnapshot(Itinerary $itinerary): array
    {
        $totalBudget = (float) $itinerary->total_budget;
        $totalCost = (float) $itinerary->total_cost;

        return [
            'total_budget' => $totalBudget,
            'total_cost' => $totalCost,
            'remaining_budget' => max(0, $totalBudget - $totalCost),
        ];
    }
}
