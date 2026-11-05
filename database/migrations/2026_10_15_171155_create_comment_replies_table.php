<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('comment_id')
                ->constrained('post_comments')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->text('reply');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_replies');
    }
};