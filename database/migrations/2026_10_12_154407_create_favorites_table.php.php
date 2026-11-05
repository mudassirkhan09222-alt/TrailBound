<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            
            // Link to User
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Link to Trip
            $table->foreignId('trip_id')->constrained()->onDelete('cascade');
            
            $table->timestamps();
            
            // Unique: Ek user ek trip ko sirf ek baar favorite kar sakta hai
            $table->unique(['user_id', 'trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};