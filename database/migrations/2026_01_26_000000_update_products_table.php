<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update products table: rename price to default_price, add is_active
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Rename price column to default_price
            $table->renameColumn('price', 'default_price');
            
            // Add is_active column
            $table->boolean('is_active')->default(true)->after('description');
            
            // Add created_by column
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'created_by']);
            $table->renameColumn('default_price', 'price');
        });
    }
};

