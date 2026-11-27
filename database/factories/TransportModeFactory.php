<?php

namespace Database\Factories;

use App\Models\TransportMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportMode>
 */
class TransportModeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $modes = [
            ['mode' => 'walk', 'label' => 'Jalan Kaki', 'co2_per_km' => 0, 'eco_points_rate' => 10],
            ['mode' => 'bicycle', 'label' => 'Sepeda', 'co2_per_km' => 0, 'eco_points_rate' => 8],
            ['mode' => 'public_transport', 'label' => 'Transportasi Umum', 'co2_per_km' => 0.05, 'eco_points_rate' => 6],
            ['mode' => 'motorcycle', 'label' => 'Motor', 'co2_per_km' => 0.08, 'eco_points_rate' => 3],
            ['mode' => 'car', 'label' => 'Mobil', 'co2_per_km' => 0.15, 'eco_points_rate' => 1],
        ];

        $selected = fake()->randomElement($modes);

        return [
            'mode' => $selected['mode'],
            'label' => $selected['label'],
            'co2_per_km' => $selected['co2_per_km'],
            'eco_points_rate' => $selected['eco_points_rate'],
        ];
    }
}
