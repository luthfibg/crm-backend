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
            if (! Schema::hasColumn('daily_goals', 'input_type')) {
                $table->enum('input_type', ['none','text','phone','file','image','video'])->default('none')->after('description');
            }
            if (! Schema::hasColumn('daily_goals', 'order')) {
                $table->integer('order')->nullable()->after('input_type');
            }
            if (! Schema::hasColumn('daily_goals', 'evidence_required')) {
                $table->boolean('evidence_required')->default(false)->after('is_completed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_goals', function (Blueprint $table) {
            if (Schema::hasColumn('daily_goals', 'evidence_required')) {
                $table->dropColumn('evidence_required');
            }
            if (Schema::hasColumn('daily_goals', 'order')) {
                $table->dropColumn('order');
            }
            if (Schema::hasColumn('daily_goals', 'input_type')) {
                $table->dropColumn('input_type');
            }
        });
    }
};
