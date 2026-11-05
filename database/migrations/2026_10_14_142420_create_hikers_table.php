<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hikers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->json('tags')->nullable(); // ["Looking for Partner", "Moderate"]
            $table->unsignedInteger('trails_completed')->default(0);
            $table->decimal('total_miles', 10, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hikers');
    }
};