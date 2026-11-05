<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Kis user ne likha
            $table->foreignId('trail_id')->constrained()->onDelete('cascade'); // Kis trail ke liye hai
            $table->integer('rating'); // 1 se 5 stars
            $table->text('comment')->nullable(); // Review text
            $table->timestamps();
            
            // Ek user ek trail ko sirf ek baar review kar sakta hai (Optional constraint)
            $table->unique(['user_id', 'trail_id']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};