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
        // Add display_name column to customers table for storing the actual sub-category name shown to user
        Schema::table('customers', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('sub_category');
        });

        // Add display_name1-4 columns to daily_goals table for storing multiple alias names
        Schema::table('daily_goals', function (Blueprint $table) {
            $table->string('display_name1')->nullable()->after('sub_category');
            $table->string('display_name2')->nullable()->after('display_name1');
            $table->string('display_name3')->nullable()->after('display_name2');
            $table->string('display_name4')->nullable()->after('display_name3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });

        Schema::table('daily_goals', function (Blueprint $table) {
            $table->dropColumn(['display_name1', 'display_name2', 'display_name3', 'display_name4']);
        });
    }
};

