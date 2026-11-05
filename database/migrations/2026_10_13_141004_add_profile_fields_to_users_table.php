<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable();
            }
            if (!Schema::hasColumn('users', 'looking_for_partner')) {
                $table->boolean('looking_for_partner')->default(false);
            }
            if (!Schema::hasColumn('users', 'total_hikes')) {
                $table->integer('total_hikes')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'looking_for_partner', 'total_hikes']);
        });
    }
};