<?php

namespace Database\Factories;

use App\Models\Itinerary;
use App\Models\ItineraryRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryFactory extends Factory
{
    protected $model = Itinerary::class;

    public function definition(): array
    {
        return [
            'request_id' => ItineraryRequest::factory(),
            'variant' => $this->faker->randomElement(['hemat', 'seimbang', 'experience']),
            'title' => $this->faker->sentence(3),
            'total_budget' => $this->faker->numberBetween(500000, 2000000),
            'total_cost' => $this->faker->numberBetween(300000, 1500000),
            'status' => 'generated',
        ];
    }
}
