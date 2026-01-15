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
        Schema::table('daily_goals', function (Blueprint $table) {
            $table->foreignId('daily_goal_type_id')->after('kpi_id')->nullable()->constrained('daily_goal_types')->onDelete('set null');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('category')->after('status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_goals', function (Blueprint $table) {
            $table->dropForeign(['daily_goal_type_id']);
            $table->dropColumn('daily_goal_type_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
