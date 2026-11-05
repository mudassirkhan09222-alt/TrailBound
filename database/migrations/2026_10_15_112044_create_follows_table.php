<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            // Jo user follow kar raha hai (Current Logged-in User)
            $table->foreignId('follower_id')->constrained('users')->onDelete('cascade');
            // Jise follow kiya ja raha hai (Target User)
            $table->foreignId('following_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Important: Ek user ek hi dusre user ko multiple baar follow nahi kar sakta
            $table->unique(['follower_id', 'following_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};