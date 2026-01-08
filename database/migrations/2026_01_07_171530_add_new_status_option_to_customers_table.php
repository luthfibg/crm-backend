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
       DB::statement("
            ALTER TABLE `customers`
            MODIFY `status`
            ENUM('New','Warm Prospect','Hot Prospect','Deal Won','After Sales','Inactive') NOT NULL DEFAULT 'New'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE `customers`
            MODIFY `status`
            ENUM('New','Warm Prospect','Hot Prospect','Deal Won','After Sales') NOT NULL DEFAULT 'New'
        ");
    }
};
