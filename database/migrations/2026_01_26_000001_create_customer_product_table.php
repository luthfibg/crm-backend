<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create pivot table for many-to-many relationship between customers and products.
     * Includes negotiated_price for custom pricing per customer.
     */
    public function up(): void
    {
        Schema::create('customer_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('negotiated_price')->nullable(); // Custom price per customer (dalam rupiah)
            $table->text('notes')->nullable(); // Catatan khusus untuk produk ini
            $table->timestamps();
            
            // Unique constraint: one product per customer (can be removed if duplicates allowed)
            $table->unique(['customer_id', 'product_id']);
            
            // Index for queries
            $table->index(['customer_id']);
            $table->index(['product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_product');
    }
};

