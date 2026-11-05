<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('name');
            $table->string('location_name');
            
            // Coordinates for Map Pin
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            
            // Auto-calculated Stats
            $table->decimal('distance', 8, 2)->default(0);
            $table->decimal('elevation', 8, 2)->default(0);
            
            $table->enum('difficulty', ['easy', 'moderate', 'hard'])->default('moderate');
            $table->string('peak_month')->nullable();
            $table->text('description')->nullable();
            $table->string('media_url')->nullable();
            
            // NEW: Track Points JSON column
            $table->json('track_points')->nullable(); 
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trails');
    }
};