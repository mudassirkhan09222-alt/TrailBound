<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_joins', function (Blueprint $table) {
            $table->id();
            
            // Link to Trip
            $table->foreignId('trip_id')->constrained()->onDelete('cascade');
            
            // Link to User (Participant)
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Status: Pending approval by Host or Auto-accept
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            
            $table->timestamps();
            
            // Unique: Ek user ek trip mein sirf ek baar join request bhej sakta hai
            $table->unique(['trip_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_joins');
    }
};