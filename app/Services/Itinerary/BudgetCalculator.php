<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Services\PlaceCache\PriceTierResolver;

class BudgetCalculator
{
    public function __construct(
        private PriceTierResolver $priceTierResolver,
    ) {}

    /**
     * Estimate cost for a destination based on its price tier and party size.
     */
    public function estimateCost(Destination $destination, int $numPeople): float
    {
        $range = $this->priceTierResolver->priceTierRange($destination->price_tier);
        $min = $range['min'] ?? 0;

        return (float) $min * max(1, $numPeople);
    }

    /**
     * Calculate the total_cost delta when an item changes destination or completion status.
     */
    public function calculateStatusCostDelta(
        string $previousStatus,
        string $newStatus,
        float $previousCost,
        float $newCost
    ): float {
        if ($previousStatus === 'pending' && $newStatus === 'pending') {
            return $newCost - $previousCost;
        }

        if ($previousStatus === 'pending' && $newStatus === 'completed') {
            return -$previousCost;
        }

        if ($previousStatus === 'completed' && $newStatus === 'pending') {
            return $newCost;
        }

        return 0.0;
    }

    /**
     * Build a budget snapshot summary for an itinerary.
     *
     * @return array{total_budget: float, total_cost: float, remaining_budget: float}
     */
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
