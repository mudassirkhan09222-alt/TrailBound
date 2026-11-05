<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Trip creator
            
            // Screen se liye gaye fields
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('group_capacity'); // 2, 3, 4, 6, 8, 10
            $table->string('pace'); // Easy, Moderate, Hard
            $table->text('description')->nullable();
            $table->string('media_url')->nullable(); // Photo/Video
            
            // Optional location fields
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};