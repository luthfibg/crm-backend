<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores summary/kesimpulan for each prospect at each KPI stage.
     * Only one summary per customer (replaced when advancing to next KPI).
     */
    public function up(): void
    {
        Schema::create('customer_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('kpi_id')->constrained('kpis')->onDelete('cascade');
            $table->text('summary')->nullable();
            $table->timestamps();
            
            // One summary per customer (replaced when advancing to next KPI)
            $table->unique(['customer_id']);
            
            // Index for queries
            $table->index(['user_id', 'kpi_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_summaries');
    }
};

