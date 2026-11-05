<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Purana user_id hatayein agar exist karta hai
        if (Schema::hasColumn('trips', 'user_id')) {
            Schema::table('trips', function (Blueprint $table) {
                try { $table->dropForeign(['user_id']); } catch (\Exception $e) {}
                $table->dropColumn('user_id');
            });
        }

        // 2. Data Cleanup: Jo bhi trips hiker_id 0 ya null par hain, unhein Hiker 1 se link karein
        DB::table('trips')->where('hiker_id', 0)->orWhereNull('hiker_id')->update(['hiker_id' => 1]);

        // 3. Sirf Foreign Key ADD karein (Drop mat karein kyunki woh exist hi nahi karta)
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('hiker_id')
                  ->references('id')
                  ->on('hikers')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            try { $table->dropForeign(['hiker_id']); } catch (\Exception $e) {}
        });
    }
};