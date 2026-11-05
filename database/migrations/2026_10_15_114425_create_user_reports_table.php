<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->onDelete('cascade'); // Report karne wala
            $table->foreignId('reported_id')->constrained('users')->onDelete('cascade'); // Jisko report kiya
            $table->string('reason'); // Harassment, Fake account, etc.
            $table->text('details')->nullable(); // Additional details
            $table->enum('status', ['pending', 'reviewed', 'resolved', 'rejected'])->default('pending');
            $table->timestamps();

            // Duplicate report se bachne ke liye unique constraint
            $table->unique(['reporter_id', 'reported_id', 'reason'], 'unique_report');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};