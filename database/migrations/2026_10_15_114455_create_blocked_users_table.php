<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->onDelete('cascade'); // Block karne wala
            $table->foreignId('blocked_id')->constrained('users')->onDelete('cascade'); // Jisko block kiya
            $table->string('reason')->nullable(); // Spam, Policy violation, etc.
            $table->timestamps();

            // Ek user ek hi user ko ek baar block kar sakta hai
            $table->unique(['blocker_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
    }
};