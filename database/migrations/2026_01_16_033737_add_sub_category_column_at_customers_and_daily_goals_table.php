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
        // Update untuk tabel Customers
        Schema::table('customers', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('category');
        });

        // Update untuk tabel Daily Goals
        Schema::table('daily_goals', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('daily_goal_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
