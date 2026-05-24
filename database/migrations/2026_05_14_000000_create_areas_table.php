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
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->integer('grid_lat');
            $table->integer('grid_lng');
            $table->unsignedInteger('radius')->default(3000);
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
            $table->unique(['grid_lat', 'grid_lng']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
