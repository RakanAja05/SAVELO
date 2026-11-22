# Refactor Plan: Split God Services

**Date:** 2026-11-22  
**Author:** Kiro (AI Agent)  
**Branch:** `refactor/split-god-services`

## Executive Summary

Behavior-preserving refactor to split two oversized service classes into smaller, cohesive units without changing any external behavior. All API endpoints, request/response shapes, and business logic remain identical.

## Baseline Metrics (Measured)

### PlaceCacheService (884 lines)
- **Public methods (3):** `__construct`, `getCityPlaces`, `getPlaceDetail`
- **Private methods (19):** `fetchCityDiverseDestinations`, `fetchPlaceDetail`, `getNearbyFromDb`, `extractBaseCategory`, `extractCategory`, `resolveMapCategory`, `classifyUmkmByRules`, `categoryFallbackTier`, `culturePointsFor`, `shouldRefreshMicrostory`, `generateMicrostory`, `parseBatchResponse`, `batchClassifyUmkm`, `batchInferPriceTier`, `extractPriceTier`, `extractCountryCode`, `extractCity`, `ensureDetailArea`, `latLngToGrid`
- **Longest method:** `fetchPlaceDetail` (161 lines)
- **Constructor dependencies:** 2 (GooglePlacesService, GeminiService)
- **Responsibilities:** City place caching, place detail fetching, Google Places API orchestration, category classification (base/UMKM/map), price tier resolution, Gemini batch classification, microstory generation, grid/area management

### ItineraryService (1,039 lines)
- **Public methods (9):** `__construct`, `generate`, `getRequestHistory`, `getItineraryDetail`, `getItineraryDay`, `updateItem`, `checkLocation`, `checkinPreview`, `checkin`
- **Private methods (20):** `resolveItem`, `haversineDistance`, `formatItem`, `buildBudgetSnapshot`, `selectCandidates`, `buildPrompt`, `parseGeminiResponse`, `finalizeRecommendation`, `normalizeVariants`, `persistItineraries`, `formatRequest`, `formatItinerarySummary`, `formatItineraries`, `formatItinerary`, `formatDay`, `estimateCost`, `extractVariantMeta`, `buildSummaryTags`, `normalizeTags`, `formatPriceLabel`
- **Longest method:** `persistItineraries` (112 lines)
- **Constructor dependencies:** 2 (PlaceCacheService, GeminiService)
- **Responsibilities:** Itinerary generation (prompt building, Gemini orchestration, parsing), persistence (variants/days/items/legs), retrieval (history, detail, day), item updates, checkin flow, location verification, budget tracking, response formatting

## Known Issues (to be pinned, not fixed)

1. **Missing method:** `ItineraryController@generateSmartSwaps` calls `$this->service->generateSmartSwaps()` which does not exist. Current behavior: 500 error. This will be pinned in tests and documented but NOT fixed in this refactor.

## Refactor Strategy

### Extraction Order (safest to riskiest)

#### Phase 1: PlaceCacheService

1. **GridManager** (low risk, pure calculation)
   - `latLngToGrid`, `ensureDetailArea`
   - Zero dependencies

2. **PlaceCategoryClassifier** (medium risk, complex rules)
   - `extractBaseCategory`, `extractCategory`, `resolveMapCategory`, `classifyUmkmByRules`, `culturePointsFor`
   - Zero dependencies, uses constants

3. **PriceTierResolver** (medium risk, Gemini dependency)
   - `extractPriceTier`, `categoryFallbackTier`, `batchInferPriceTier`, `parseBatchResponse` (price variant)
   - Depends on GeminiService

4. **PlaceEnricher** (low risk, single responsibility)
   - `generateMicrostory`, `shouldRefreshMicrostory`
   - Depends on GeminiService

5. **AddressParser** (low risk, pure parsing)
   - `extractCountryCode`, `extractCity`
   - Zero dependencies

6. **UmkmClassifier** (medium risk, Gemini dependency)
   - `batchClassifyUmkm`, `parseBatchResponse` (umkm variant)
   - Depends on GeminiService

7. **Keep PlaceCacheService as orchestrator**
   - `getCityPlaces`, `getPlaceDetail`, `fetchCityDiverseDestinations`, `fetchPlaceDetail`, `getNearbyFromDb`
   - Will inject the new classes

#### Phase 2: ItineraryService

1. **ItineraryFormatter** (low risk, pure formatting)
   - All `format*` methods (8 methods), `normalizeTags`, `formatPriceLabel`
   - Depends on PlaceCacheService (for priceTierRange)

2. **BudgetCalculator** (low risk, pure calculation)
   - `estimateCost`, `buildBudgetSnapshot`
   - Depends on PlaceCacheService (for priceTierRange)

3. **ItineraryParser** (medium risk, JSON parsing)
   - `parseGeminiResponse`, `normalizeVariants`, `finalizeRecommendation`, `extractVariantMeta`
   - Zero dependencies

4. **PromptBuilder** (low risk, string building)
   - `buildPrompt`, `selectCandidates`
   - Depends on PlaceCacheService (for priceTierRange)

5. **CheckinManager** (medium risk, complex flow)
   - `checkLocation`, `checkinPreview`, `checkin`, `resolveItem`, `haversineDistance`
   - Depends on models, BudgetCalculator

6. **ItineraryPersister** (high risk, complex DB transactions)
   - `persistItineraries`
   - Depends on BudgetCalculator

7. **ItineraryGenerator** (high risk, orchestration)
   - `generate` (the big method that ties everything together)
   - Depends on PlaceCacheService, GeminiService, PromptBuilder, ItineraryParser, ItineraryPersister

8. **Keep ItineraryService as orchestrator**
   - Public methods delegate to extracted classes
   - `getRequestHistory`, `getItineraryDetail`, `getItineraryDay`, `updateItem` stay in orchestrator

## Target Structure

```
app/Services/
├── PlaceCache/
│   ├── PlaceCacheService.php (orchestrator, ~200 lines)
│   ├── GridManager.php
│   ├── PlaceCategoryClassifier.php
│   ├── PriceTierResolver.php
│   ├── PlaceEnricher.php
│   ├── AddressParser.php
│   └── UmkmClassifier.php
├── Itinerary/
│   ├── ItineraryService.php (orchestrator, ~250 lines)
│   ├── ItineraryFormatter.php
│   ├── BudgetCalculator.php
│   ├── ItineraryParser.php
│   ├── PromptBuilder.php
│   ├── CheckinManager.php
│   ├── ItineraryPersister.php
│   └── ItineraryGenerator.php
├── GooglePlacesService.php (already exists, thin wrapper)
└── Gemini/
    └── GeminiService.php (already exists)
```

## Testing Strategy

### Characterization Tests (write FIRST, before any extraction)

1. **PlaceCacheServiceTest**
   - `getCityPlaces`: cache hit, cache miss, lock timeout, empty results, Google API failure
   - `getPlaceDetail`: cached, stale detail, new place, nearby fetch, microstory generation
   - Synthetic Google Places fixtures (no real API calls)
   - Synthetic Gemini fixtures (UMKM classification, price tier inference, microstory)

2. **ItineraryServiceTest**
   - `generate`: full flow with 3 variants, missing places, Gemini failure, parse failure, persist failure
   - `updateItem`: swap destination, change status, budget recalculation
   - `checkin`: location check, points calculation, budget update
   - `getRequestHistory`, `getItineraryDetail`, `getItineraryDay`: retrieval paths
   - Synthetic Gemini fixtures for itinerary generation

3. **Known Bug Test**
   - `generateSmartSwaps`: expect method not found error (pins current behavior)

### Test Infrastructure
- Use SQLite :memory: (already configured in phpunit.xml)
- Http::fake() for all external calls (Google, Gemini)
- Http::preventStrayRequests() to catch accidental real calls
- RefreshDatabase trait
- Factory/seeder usage for test data

## Commit Strategy

Each extraction is one commit with this structure:
1. Extract class to new file
2. Update service to inject and delegate
3. Run tests (must be green)
4. Run Pint (must pass)
5. Commit with message: `refactor(places): extract GridManager` or `refactor(itinerary): extract ItineraryFormatter`

## Success Criteria

- All existing tests pass (after writing characterization tests)
- New extracted classes are <250 lines each
- No change to routes, controllers, migrations, models, or frontend
- Pint passes on all touched files
- Static analysis (PHPStan level 5) passes on extracted classes with no errors
- CI workflow runs tests, Pint, and static analysis
- README updated with architecture diagram
- This document updated with actual before/after metrics

## Out of Scope

- Fixing the missing `generateSmartSwaps` method
- Adding new features
- Changing existing behavior
- Performance optimization
- Database schema changes
- Frontend changes
