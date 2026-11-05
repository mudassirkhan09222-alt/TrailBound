<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_media', function (Blueprint $table) {
            $table->id();
            
            // Link to Trip
            $table->foreignId('trip_id')->constrained()->onDelete('cascade');
            
            // File Details
            $table->string('url');          // Image/Video ka path or URL
            $table->enum('type', ['image', 'video'])->default('image'); // Type check karne ke liye
            $table->integer('order')->default(0); // Gallery mein sequence maintain karne ke liye
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_media');
    }
};