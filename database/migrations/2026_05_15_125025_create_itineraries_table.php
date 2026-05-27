<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('itinerary_requests')->cascadeOnDelete();
            $table->string('variant');
            $table->string('title');
            $table->decimal('total_budget', 12, 2);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itineraries');
    }
};
