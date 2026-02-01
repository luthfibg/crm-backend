<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progresses', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_id')->nullable()->after('user_id');
            $table->index('sales_id');
        });
    }

    public function down(): void
    {
        Schema::table('progresses', function (Blueprint $table) {
            $table->dropColumn('sales_id');
        });
    }
};
