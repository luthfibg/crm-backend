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
            if (Schema::hasColumn('kpis', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }

            if (Schema::hasColumn('kpis', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('kpis', 'target_value')) {
                $table->dropColumn('target_value');
            }
            if (Schema::hasColumn('kpis', 'actual_value')) {
                $table->dropColumn('actual_value');
            }
            if (Schema::hasColumn('kpis', 'measurement_date')) {
                $table->dropColumn('measurement_date');
            }

            $table->string('code')->after('id');
            $table->text('description')->after('code')->change();
            $table->integer('weight_point')->after('description');
            $table->enum('type', ['cycle', 'periodic', 'achievement'])->after('weight_point')->default('cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpis', function (Blueprint $table) {
            // Kembalikan kolom yang dihapus (sesuaikan dengan tipe semula)
            if (! Schema::hasColumn('kpis', 'name')) {
                $table->string('name')->after('id');
            }
            if (! Schema::hasColumn('kpis', 'target_value')) {
                $table->decimal('target_value', 15, 2);
            }
            if (! Schema::hasColumn('kpis', 'actual_value')) {
                $table->decimal('actual_value', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('kpis', 'measurement_date')) {
                $table->date('measurement_date');
            }
            if (! Schema::hasColumn('kpis', 'user_id')) {
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
            }
        });
    }
};
