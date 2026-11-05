<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Nullable user_id add karein (taake existing rows crash na hon)
        if (!Schema::hasColumn('trips', 'user_id')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        // 2. Existing trips ko apni REAL User ID se link karein
        // Note: Yahan wo ID dalein jo aapke users table mein actually exist karti ho
        DB::table('trips')->whereNull('user_id')->update(['user_id' => 1]); 

        // 3. Foreign key lagayein aur required banayein
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};