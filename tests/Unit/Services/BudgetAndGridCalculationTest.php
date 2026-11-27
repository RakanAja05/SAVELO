<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\Itinerary;
use App\Services\Gemini\GeminiBatchParser;
use App\Services\Gemini\GeminiService;
use App\Services\Itinerary\BudgetCalculator;
use App\Services\PlaceCache\AddressParser;
use App\Services\PlaceCache\GridManager;
use App\Services\PlaceCache\PlaceCategoryClassifier;
use App\Services\PlaceCache\PriceTierResolver;

test('GridManager quantizes coordinates into 0.02 degree integer spatial grid', function () {
    $gridManager = new GridManager;

    expect($gridManager->latLngToGrid(-7.7956, 110.3695))->toBe([
        'grid_lat' => (int) floor(-7.7956 / 0.02),
        'grid_lng' => (int) floor(110.3695 / 0.02),
    ]);
});

test('AddressParser returns default ID country code and empty city when missing', function () {
    $parser = new AddressParser;

    expect($parser->extractCountryCode([]))->toBe('ID')
        ->and($parser->extractCity([]))->toBe('')
        ->and($parser->extractCountryCode([
            ['types' => ['country'], 'shortText' => 'SG'],
        ]))->toBe('SG')
        ->and($parser->extractCity([
            ['types' => ['locality'], 'longText' => 'Yogyakarta'],
        ]))->toBe('Yogyakarta');
});

test('PriceTierResolver provides deterministic category fallback tiers', function () {
    $resolver = new PriceTierResolver(
        Mockery::mock(GeminiService::class),
        new PlaceCategoryClassifier,
        new GeminiBatchParser
    );

    expect($resolver->categoryFallbackTier('umkm'))->toBe('budget')
        ->and($resolver->categoryFallbackTier('taman'))->toBe('budget')
        ->and($resolver->categoryFallbackTier('wisata'))->toBe('mid')
        ->and($resolver->categoryFallbackTier('hotel'))->toBe('premium')
        ->and($resolver->categoryFallbackTier('lainnya'))->toBe('unknown');
});

test('BudgetCalculator estimates cost, status cost deltas, and remaining budget snapshot', function () {
    $resolver = new PriceTierResolver(
        Mockery::mock(GeminiService::class),
        new PlaceCategoryClassifier,
        new GeminiBatchParser
    );
    $calculator = new BudgetCalculator($resolver);

    $destination = new Destination(['price_tier' => 'mid']);
    expect($calculator->estimateCost($destination, 3))->toBe(150000.0)
        ->and($calculator->estimateCost($destination, 0))->toBe(50000.0);

    expect($calculator->calculateStatusCostDelta('pending', 'pending', 20000, 50000))->toBe(30000.0)
        ->and($calculator->calculateStatusCostDelta('pending', 'completed', 50000, 50000))->toBe(-50000.0)
        ->and($calculator->calculateStatusCostDelta('completed', 'pending', 50000, 50000))->toBe(50000.0)
        ->and($calculator->calculateStatusCostDelta('completed', 'completed', 50000, 50000))->toBe(0.0);

    $itinerary = new Itinerary([
        'total_budget' => 1000000,
        'total_cost' => 350000,
    ]);

    expect($calculator->buildBudgetSnapshot($itinerary))->toBe([
        'total_budget' => 1000000.0,
        'total_cost' => 350000.0,
        'remaining_budget' => 650000.0,
    ]);
});
