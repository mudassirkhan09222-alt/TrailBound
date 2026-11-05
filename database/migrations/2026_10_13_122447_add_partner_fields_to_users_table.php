<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Partner dhoondne ka toggle
            if (!Schema::hasColumn('users', 'looking_for_partner')) {
                $table->boolean('looking_for_partner')->default(false)->after('bio');
            }
            // Total hikes count
            if (!Schema::hasColumn('users', 'total_hikes')) {
                $table->integer('total_hikes')->default(0)->after('looking_for_partner');
            }
             // Bio field agar nahi hai toh
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['looking_for_partner', 'total_hikes', 'bio']);
        });
    }
};