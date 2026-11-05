<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trail_media', function (Blueprint $table) {
            $table->id();
            
            // Trail ID jo is media se connect hogi
            $table->foreignId('trail_id')
                  ->constrained('trails')
                  ->onDelete('cascade'); // Agar trail delete ho, toh media bhi delete ho jaye
            
            // Image ya Video ka URL/Path
            $table->string('url');
            
            // Type: image ya video
            $table->enum('type', ['image', 'video'])->default('image');
            
            // Order: konsi pehle dikhegi (0, 1, 2...)
            $table->integer('order')->default(0);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trail_media');
    }
};