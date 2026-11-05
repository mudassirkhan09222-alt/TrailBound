<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('trip_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('trip_id')->constrained()->onDelete('cascade'); // Trip se link
            $table->string('condition')->default('On Time'); // e.g., Delayed, Cancelled, On Time
            $table->text('description')->nullable();
            $table->json('issues')->nullable(); // e.g., ["no_transport", "bad_weather"]
            $table->integer('confirmations')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('trip_reports'); }
};