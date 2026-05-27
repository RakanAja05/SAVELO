<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itinerary_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_item_id')->constrained('itinerary_items')->cascadeOnDelete();
            $table->foreignId('to_item_id')->constrained('itinerary_items')->cascadeOnDelete();
            $table->decimal('distance_km', 8, 2);
            $table->unsignedInteger('duration_min');
            $table->foreignId('transport_mode_id')->nullable()->constrained('transport_modes')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_legs');
    }
};
