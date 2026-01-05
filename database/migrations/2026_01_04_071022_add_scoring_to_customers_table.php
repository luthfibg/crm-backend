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
        Schema::table('customers', function (Blueprint $table) {
            // Total poin yang sudah diraih dari customer ini
            $table->decimal('earned_points', 10, 2)->default(0)->after('status_changed_at');
            
            // Poin maksimal yang bisa diraih (berdasarkan KPI cycle yang sudah dilalui)
            $table->decimal('max_points', 10, 2)->default(0)->after('earned_points');
            
            // Progress percentage untuk scoring
            $table->decimal('score_percentage', 5, 2)->default(0)->after('max_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['earned_points', 'max_points', 'score_percentage']);
        });
    }
};
