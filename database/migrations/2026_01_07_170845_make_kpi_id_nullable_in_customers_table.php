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
        // drop the existing foreign key
        $table->dropForeign(['kpi_id']);
    });

    Schema::table('customers', function (Blueprint $table) {
        // make column nullable
        $table->unsignedBigInteger('kpi_id')->nullable()->change();

        // re-add FK (adjust 'kpis' or actual referenced table name if different)
        $table->foreign('kpi_id')->references('id')->on('kpis')->nullOnDelete();
        // OR use ->nullOnDelete() if you prefer kpi_id set to NULL when KPI is deleted:
        // $table->foreign('kpi_id')->references('id')->on('kpis')->nullOnDelete();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            //
        });
    }
};
