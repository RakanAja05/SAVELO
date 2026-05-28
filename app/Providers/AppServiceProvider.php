<?php

namespace App\Providers;

use App\Services\FirebaseClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FirebaseClient::class, function () {
            $credentials = config('firebase.credentials');
            $databaseUrl = config('firebase.database_url');
            $projectId = config('firebase.project_id');

            $factory = new Factory;

            if ($credentials) {
                $factory = $factory->withServiceAccount($credentials);
            }

            if ($databaseUrl) {
                $factory = $factory->withDatabaseUri($databaseUrl);
            }

            if ($projectId) {
                $factory = $factory->withProjectId($projectId);
            }

            return new FirebaseClient($factory);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'status'  => 'error',
                    'message' => 'Terlalu banyak permintaan. Coba lagi nanti.',
                ], 429));
        });

        RateLimiter::for('itinerary-generate', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'status'  => 'error',
                    'message' => 'Batas generate itinerary tercapai. Coba lagi dalam 1 jam.',
                ], 429));
        });

        RateLimiter::for('smart-swaps', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => response()->json([
                    'status'  => 'error',
                    'message' => 'Batas smart swap tercapai. Coba lagi dalam 1 jam.',
                ], 429));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'status'  => 'error',
                    'message' => 'Terlalu banyak percobaan. Coba lagi nanti.',
                ], 429));
        });
    }
}
