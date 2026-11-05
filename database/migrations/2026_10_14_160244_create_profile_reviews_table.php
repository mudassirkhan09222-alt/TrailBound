<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete(); // Jisne review diya
            $table->foreignId('reviewee_id')->constrained('users')->cascadeOnDelete(); // Profile owner
            $table->decimal('rating', 3, 1)->check('rating >= 1 AND rating <= 5');
            $table->text('comment')->nullable();
            $table->timestamps();

            // Ek user doosre user ko sirf 1 baar review de sakta hai
            $table->unique(['reviewer_id', 'reviewee_id']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_reviews');
    }
};