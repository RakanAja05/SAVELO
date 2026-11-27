<?php

declare(strict_types=1);

use App\Services\Gemini\GeminiBatchParser;

test('parseBatchResponse accepts predictions at or above 0.70 confidence and rejects low confidence as null', function () {
    $parser = new GeminiBatchParser;

    $raw = implode("\n", [
        'place_1|Y|0.70',
        'place_2|N|0.95',
        'place_3|Y|0.69',
        'malformed_line',
    ]);

    $result = $parser->parseBatchResponse($raw, 'umkm');

    expect($result)->toBe([
        'place_1' => true,
        'place_2' => false,
        'place_3' => null,
    ]);
});

test('parseBatchResponse validates allowed price tiers and discards unknown tiers', function () {
    $parser = new GeminiBatchParser;

    $raw = implode("\n", [
        'p1|free|0.90',
        'p2|budget|0.85',
        'p3|mid|0.75',
        'p4|premium|0.80',
        'p5|luxury|0.99',
        'p6|super_vip|0.99',
        'p7|mid|0.40',
    ]);

    $result = $parser->parseBatchResponse($raw, 'price');

    expect($result)->toBe([
        'p1' => 'free',
        'p2' => 'budget',
        'p3' => 'mid',
        'p4' => 'premium',
        'p5' => 'luxury',
        'p6' => null,
        'p7' => null,
    ]);
});
