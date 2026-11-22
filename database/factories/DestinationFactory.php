<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'place_id' => 'place_'.$this->faker->unique()->uuid(),
            'name' => $this->faker->company(),
            'slug' => $this->faker->slug(),
            'country_code' => 'ID',
            'city' => $this->faker->city(),
            'address' => $this->faker->address(),
            'lat' => $this->faker->latitude(-8, -6),
            'lng' => $this->faker->longitude(106, 114),
            'rating' => $this->faker->randomFloat(2, 3, 5),
            'user_rating_count' => $this->faker->numberBetween(10, 5000),
            'description' => $this->faker->paragraph(),
            'category' => $this->faker->randomElement(['wisata', 'restoran', 'cafe', 'hotel', 'museum']),
            'map_category' => $this->faker->randomElement(['iconic', 'hidden_gem', 'umkm', null]),
            'price_tier' => $this->faker->randomElement(['free', 'budget', 'mid', 'premium']),
            'culture_points' => $this->faker->numberBetween(0, 35),
            'opening_hours' => null,
            'phone' => $this->faker->phoneNumber(),
            'whatsapp' => null,
            'official_url' => $this->faker->url(),
            'photos' => [],
            'ai_microstory' => $this->faker->sentence(),
            'cached_at' => now(),
            'detail_fetched_at' => null,
        ];
    }
}
