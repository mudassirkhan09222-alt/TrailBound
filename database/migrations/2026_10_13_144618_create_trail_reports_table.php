<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trail_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Kis user ne report kiya
            $table->foreignId('trail_id')->constrained()->onDelete('cascade'); // Kis trail ke liye hai
            
            // Main Condition (Clear, Muddy, Snow, etc.)
            $table->string('condition')->default('Clear'); 
            
            // User ka written report
            $table->text('description')->nullable(); 
            
            // Facilities/Tags (JSON format mein store karenge taake multiple select ho sakein)
            // e.g., ["water_available", "trail_blocked"]
            $table->json('facilities')->nullable(); 
            
            // Confirmation count (Kitne logon ne is report ko confirm kiya)
            $table->integer('confirmations')->default(0);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_reports');
    }
};