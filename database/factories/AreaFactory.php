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
            'grid_lat' => $this->faker->unique()->numberBetween(-90000, 90000),
            'grid_lng' => $this->faker->numberBetween(-180000, 180000),
            'radius' => 3000,
            'cached_at' => now(),
        ];
    }
}
