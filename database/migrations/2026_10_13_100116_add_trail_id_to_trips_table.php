<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // Assuming you have a 'trails' table. If not, use unsignedBigInteger or string depending on your setup.
            $table->foreignId('trail_id')->nullable()->constrained()->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['trail_id']);
            $table->dropColumn('trail_id');
        });
    }
};