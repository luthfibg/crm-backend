<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_kpi_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Sales yang handle
            $table->foreignId('kpi_id')->constrained()->onDelete('cascade');
            
            // Scoring details
            $table->integer('tasks_completed')->default(0); // Jumlah misi approved
            $table->integer('tasks_total')->default(0);     // Total misi di KPI ini
            $table->decimal('completion_rate', 5, 2)->default(0); // Persentase (0-100)
            
            // Points calculation
            $table->decimal('kpi_weight', 10, 2)->default(0);      // Weight point dari KPI
            $table->decimal('earned_points', 10, 2)->default(0);   // Poin yang diraih
            
            // Status
            $table->enum('status', ['active', 'completed', 'skipped'])->default('active');
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            
            // Index untuk query cepat
            $table->index(['customer_id', 'kpi_id']);
            $table->index(['user_id', 'kpi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_kpi_scores');
    }
};