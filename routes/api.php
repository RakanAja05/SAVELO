<?php

use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\GoogleController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Destination\DestinationController;
use App\Http\Controllers\Api\Favorite\FavoriteController;
use App\Http\Controllers\Api\Itinerary\ItineraryController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class);
    Route::post('/register', [RegisterController::class, 'register']);
    Route::post('/register/verify', [RegisterController::class, 'verify']);

    Route::post('/forgot-password', [ForgotPasswordController::class, 'requestOtp']);
    Route::post('/forgot-password/reset', [ForgotPasswordController::class, 'reset']);

    // Google OAuth
    Route::get('google', [GoogleController::class, 'redirect']);
    Route::get('google/callback', [GoogleController::class, 'callback']);
});

Route::prefix('destinations')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [DestinationController::class, 'index']);
    Route::get('/map', [DestinationController::class, 'map']);
    Route::get('{placeId}', [DestinationController::class, 'show']);
});

Route::prefix('favorites')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [FavoriteController::class, 'index']);
    Route::post('/', [FavoriteController::class, 'store']);
    Route::delete('/{destinationId}', [FavoriteController::class, 'destroy']);
});

Route::prefix('itineraries')->middleware('auth:sanctum')->group(function () {
    Route::post('/generate', [ItineraryController::class, 'generate']);
    Route::post('/{itineraryId}/smart-swaps', [ItineraryController::class, 'generateSmartSwaps']);
    Route::get('/requests/{requestId}', [ItineraryController::class, 'requestHistory']);
    Route::get('/{itineraryId}/days/{dayNumber}', [ItineraryController::class, 'dayPlan']);
    Route::patch('/{itineraryId}/{itemId}', [ItineraryController::class, 'updateItem']);
    Route::post('/{itineraryId}/items/{itemId}/check-location', [ItineraryController::class, 'checkLocation']);
    Route::get('/{itineraryId}/items/{itemId}/checkin-preview', [ItineraryController::class, 'checkinPreview']);
    Route::patch('/{itineraryId}/items/{itemId}/checkin', [ItineraryController::class, 'checkin']);
    Route::get('/{itineraryId}', [ItineraryController::class, 'show']);
});
