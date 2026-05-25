<?php

use App\Models\ItineraryRequest;

$raw = ItineraryRequest::latest()->first()?->gemini_raw_response['raw'] ?? '';
file_put_contents(storage_path('logs/gemini_raw.txt'), $raw);
