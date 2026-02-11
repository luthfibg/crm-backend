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
            // Cek apakah kolom 'price' masih ada (belum di-rename)
            if (Schema::hasColumn('products', 'price')) {
                $table->renameColumn('price', 'default_price');
            }
            
            // Tambahkan kolom 'default_price' jika belum ada (untuk kasus sudah di-rename manual)
            if (!Schema::hasColumn('products', 'default_price')) {
                $table->decimal('default_price', 10, 2)->nullable()->after('name');
            }

            // Add missing columns hanya jika belum ada
            if (!Schema::hasColumn('products', 'is_active')) {
                $table->tinyInteger('is_active')->default(1)->after('specification');
            }
            
            if (!Schema::hasColumn('products', 'created_by')) {
                $table->bigInteger('created_by')->unsigned()->nullable()->after('is_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Rename back hanya jika 'default_price' ada dan 'price' tidak ada
            if (Schema::hasColumn('products', 'default_price') && !Schema::hasColumn('products', 'price')) {
                $table->renameColumn('default_price', 'price');
            }

            // Drop columns jika ada
            if (Schema::hasColumn('products', 'is_active')) {
                $table->dropColumn('is_active');
            }
            
            if (Schema::hasColumn('products', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};