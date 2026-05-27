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
        Schema::create('transport_modes', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->unique();
            $table->decimal('co2_per_km', 8, 4)->default(0);
            $table->unsignedInteger('eco_points_rate')->default(0);
            $table->string('label');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_modes');
    }
};
