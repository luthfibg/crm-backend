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
        Schema::table('kpis', function (Blueprint $table) {
            if (Schema::hasColumn('kpis', 'total_daily_goals')) {
                $table->dropColumn('total_daily_goals');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpis', function (Blueprint $table) {
            if (! Schema::hasColumn('kpis', 'total_daily_goals')) {
                $table->integer('total_daily_goals')->after('weight_point');
            }
        });
    }
};
