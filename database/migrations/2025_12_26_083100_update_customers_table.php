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
            if (! Schema::hasColumn('customers', 'current_kpi_id')) {
                $table->foreignId('current_kpi_id')->nullable()->constrained('kpis')->nullOnDelete()->after('kpi_id');
            }

            if (! Schema::hasColumn('customers', 'status')) {
                $table->string('status')->default('New')->after('current_kpi_id');
            }

            if (! Schema::hasColumn('customers', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'status_changed_at')) {
                $table->dropColumn('status_changed_at');
            }
            if (Schema::hasColumn('customers', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('customers', 'current_kpi_id')) {
                $table->dropForeign([$column = 'current_kpi_id']);
                $table->dropColumn('current_kpi_id');
            }
        });
    }
};
