<?php

namespace Database\Seeders;

use App\Models\TransportMode;
use Illuminate\Database\Seeder;

class TransportModeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modes = [
            ['mode' => 'walking',  'co2_per_km' => 0,    'eco_points_rate' => 10, 'label' => 'Jalan Kaki'],
            ['mode' => 'cycling',  'co2_per_km' => 0,    'eco_points_rate' => 8,  'label' => 'Sepeda'],
            ['mode' => 'car',      'co2_per_km' => 0.21, 'eco_points_rate' => 2,  'label' => 'Mobil'],
            ['mode' => 'motorcycle', 'co2_per_km' => 0.11, 'eco_points_rate' => 3,  'label' => 'Motor'],
            ['mode' => 'bus',      'co2_per_km' => 0.05, 'eco_points_rate' => 6,  'label' => 'Bus'],
            ['mode' => 'train',    'co2_per_km' => 0.04, 'eco_points_rate' => 7,  'label' => 'Kereta'],
        ];

        foreach ($modes as $mode) {
            TransportMode::updateOrCreate(['mode' => $mode['mode']], $mode);
        }
    }
}
