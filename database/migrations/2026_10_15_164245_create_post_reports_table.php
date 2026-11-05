<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')
                ->constrained('posts')
                ->cascadeOnDelete();

            $table->foreignId('reported_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('reason');
            $table->text('description')->nullable();

            $table->enum('status', [
                'pending',
                'reviewed',
                'dismissed',
                'action_taken'
            ])->default('pending');

            $table->timestamps();

            // Ek user ek post ko sirf ek baar report kar sakega
            $table->unique(['post_id', 'reported_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_reports');
    }
};
