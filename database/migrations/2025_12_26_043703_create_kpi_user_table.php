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
        Schema::create('kpi_user', function (Blueprint $table) {
            $table->id();
            // Connect to users table (sales)
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Connect to kpis table
            $table->foreignId('kpi_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_user');
    }
};
