<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            ['id' => 1, 'grid_lat' => -389, 'grid_lng' => 5517, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 2, 'grid_lat' => -391, 'grid_lng' => 5518, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 3, 'grid_lat' => -394, 'grid_lng' => 5522, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 4, 'grid_lat' => -391, 'grid_lng' => 5506, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 5, 'grid_lat' => -391, 'grid_lng' => 5526, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 6, 'grid_lat' => -381, 'grid_lng' => 5522, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 7, 'grid_lat' => -392, 'grid_lng' => 5519, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 8, 'grid_lat' => -388, 'grid_lng' => 5518, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 9, 'grid_lat' => -391, 'grid_lng' => 5517, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            ['id' => 10, 'grid_lat' => -390, 'grid_lng' => 5518, 'radius' => 3000, 'cached_at' => null, 'created_at' => '2026-05-23 19:02:53', 'updated_at' => '2026-05-23 19:02:53'],
            // ... tambahkan data lain sesuai kebutuhan ...
        ];
        DB::table('areas')->insert($areas);
    }
}
