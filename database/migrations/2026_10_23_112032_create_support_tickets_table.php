<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('concern'); // Account Issue, Trip Problem, etc.
            $table->text('message');
            $table->string('attachment_path')->nullable(); // File ka path
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Logged-in user ke liye
            $table->enum('status', ['pending', 'in_progress', 'resolved'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};