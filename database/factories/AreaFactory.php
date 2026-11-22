<?php

namespace Database\Factories;

use App\Models\Area;
use Illuminate\Database\Eloquent\Factories\Factory;

class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            'grid_lat' => $this->faker->numberBetween(-400, -300),
            'grid_lng' => $this->faker->numberBetween(5300, 5700),
            'radius' => 3000,
            'cached_at' => now(),
        ];
    }
}
