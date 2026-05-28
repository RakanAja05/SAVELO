<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Destination;
use App\Models\ItineraryRequest;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\TransportMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class DevDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Seed transport modes
        $this->call(TransportModeSeeder::class);

        // Seed a user
        $user = User::factory()->create([
            'email' => 'fe-tester@savelo.dev',
            'name' => 'FE Tester',
            'eco_points' => 0,
            'culture_points' => 0,
            'path_points' => 0,
        ]);

        // Seed a destination
        $destination = Destination::create([
            'area_id' => 1,
            'place_id' => 'demo-place-1',
            'name' => 'Candi Borobudur',
            'slug' => 'candi-borobudur',
            'country_code' => 'ID',
            'city' => 'Magelang',
            'address' => 'Jl. Badrawati, Borobudur, Magelang',
            'lat' => -7.6079,
            'lng' => 110.2038,
            'rating' => 4.8,
            'user_rating_count' => 12000,
            'description' => 'Candi Buddha terbesar di dunia.',
            'category' => 'heritage',
            'map_category' => 'heritage',
            'culture_points' => 35,
            'price_tier' => 'premium',
            'opening_hours' => json_encode(['mon-sun' => '06:00-17:00']),
            'phone' => null,
            'whatsapp' => null,
            'official_url' => 'https://borobudurpark.com',
            'photos' => json_encode([]),
            'ai_microstory' => 'Borobudur adalah mahakarya warisan dunia yang memesona.',
            'cached_at' => now(),
            'detail_fetched_at' => now(),
        ]);

        // Seed itinerary request
        $request = ItineraryRequest::create([
            'user_id' => $user->id,
            'origin' => 'Yogyakarta',
            'destination_label' => 'Magelang',
            'duration_days' => 1,
            'num_people' => 1,
            'budget' => 500000,
            'status' => 'active',
            'gemini_raw_response' => null,
        ]);

        // Seed itinerary
        $itinerary = Itinerary::create([
            'request_id' => $request->id,
            'variant' => 'default',
            'title' => 'Trip Borobudur',
            'total_budget' => 500000,
            'total_cost' => 200000,
            'status' => 'active',
        ]);

        // Seed itinerary day
        $day = ItineraryDay::create([
            'itinerary_id' => $itinerary->id,
            'day_number' => 1,
            'estimated_cost' => 200000,
        ]);

        // Seed itinerary item
        $item = ItineraryItem::create([
            'itinerary_day_id' => $day->id,
            'destination_id' => $destination->id,
            'order_index' => 1,
            'visit_time' => '09:00',
            'duration_minutes' => 120,
            'cost_estimate' => 200000,
            'notes' => 'Jangan lupa bawa topi.',
            'status' => 'pending',
        ]);

        // Seed itinerary leg
        $mode = TransportMode::where('mode', 'car')->first();
        ItineraryLeg::create([
            'from_item_id' => $item->id,
            'to_item_id' => $item->id,
            'distance_km' => 42.0,
            'duration_min' => 90,
            'transport_mode_id' => $mode?->id,
        ]);
    }
}
