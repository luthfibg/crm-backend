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
        Schema::table('key_performance_indicators', function (Blueprint $table) {
            if (Schema::hasColumn('key_performance_indicators', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }

            if (Schema::hasColumn('key_performance_indicators', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('key_performance_indicators', 'target_value')) {
                $table->dropColumn('target_value');
            }
            if (Schema::hasColumn('key_performance_indicators', 'actual_value')) {
                $table->dropColumn('actual_value');
            }
            if (Schema::hasColumn('key_performance_indicators', 'measurement_date')) {
                $table->dropColumn('measurement_date');
            }

            $table->string('code')->after('id');
            $table->text('description')->after('code')->change();
            $table->integer('weight_point')->after('description');
            $table->integer('total_daily_goals')->after('weight_point');
            $table->enum('type', ['cycle', 'periodic', 'achievement'])->after('total_daily_goals')->default('cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('key_performance_indicators', function (Blueprint $table) {
            // Kembalikan kolom yang dihapus (sesuaikan dengan tipe semula)
            if (! Schema::hasColumn('key_performance_indicators', 'name')) {
                $table->string('name')->after('id');
            }
            if (! Schema::hasColumn('key_performance_indicators', 'target_value')) {
                $table->decimal('target_value', 15, 2);
            }
            if (! Schema::hasColumn('key_performance_indicators', 'actual_value')) {
                $table->decimal('actual_value', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('key_performance_indicators', 'measurement_date')) {
                $table->date('measurement_date');
            }
            if (! Schema::hasColumn('key_performance_indicators', 'user_id')) {
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
            }
        });
    }
};
