<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Rename 'price' to 'default_price'
            $table->renameColumn('price', 'default_price');

            // Add missing columns
            $table->tinyInteger('is_active')->default(1)->after('specification');
            $table->bigInteger('created_by')->unsigned()->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Rename 'default_price' back to 'price'
            $table->renameColumn('default_price', 'price');

            // Drop added columns
            $table->dropColumn('is_active');
            $table->dropColumn('created_by');
        });
    }
};
