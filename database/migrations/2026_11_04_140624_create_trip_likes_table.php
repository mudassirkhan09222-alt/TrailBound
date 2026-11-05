<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Ek user ek trip ko sirf ek baar like kar sakta hai
            $table->unique(['user_id', 'trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_likes');
    }
};