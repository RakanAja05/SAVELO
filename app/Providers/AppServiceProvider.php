<?php

namespace App\Providers;

use App\Services\FirebaseClient;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
