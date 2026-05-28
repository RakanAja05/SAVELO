<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'name' => 'Demo User',
                'email' => 'demo@example.com',
                'email_verified_at' => now(),
                'google_id' => null,
                'password' => Hash::make('password'),
                'avatar_url' => null,
                'locale' => 'id',
                'eco_points' => 100,
                'culture_points' => 50,
                'path_points' => 25,
                'remember_token' => Str::random(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Tambahkan user lain jika perlu
        ]);
    }
}
