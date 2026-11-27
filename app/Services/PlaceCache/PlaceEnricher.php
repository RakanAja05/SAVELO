<?php

namespace App\Services\PlaceCache;

use App\Models\Destination;
use App\Services\Gemini\GeminiService;

class PlaceEnricher
{
    public function __construct(
        private GeminiService $gemini,
    ) {}

    /**
     * Check if destination microstory needs refresh.
     */
    public function shouldRefreshMicrostory(Destination $destination): bool
    {
        if ($destination->ai_microstory === null) {
            return true;
        }

        return ! $destination->isDetailFresh();
    }

    /**
     * Generate AI microstory for a destination.
     */
    public function generateMicrostory(Destination $destination): ?string
    {
        $name = $destination->name;
        $category = $destination->category;
        $city = $destination->city;
        $address = $destination->address ?? '';

        $prompt = <<<PROMPT
Kamu adalah copywriter pariwisata Indonesia.

Buat microstory 2-3 kalimat dalam bahasa Indonesia tentang tempat berikut.
Gaya: hangat, informatif, tanpa klaim yang tidak pasti. Jika informasinya terbatas, gunakan deskripsi umum yang aman.
Hindari bullet list dan emoji.

Nama: {$name}
Kategori: {$category}
Kota: {$city}
Alamat: {$address}
PROMPT;

        $story = $this->gemini->generate($prompt);

        if ($story === null) {
            return null;
        }

        $story = trim($story);

        return $story === '' ? null : $story;
    }
}
