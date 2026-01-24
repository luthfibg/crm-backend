<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Change the enum to include the new types: date, number, currency
        DB::statement("ALTER TABLE daily_goals MODIFY COLUMN input_type ENUM('none','text','phone','file','image','video','date','number','currency') NOT NULL DEFAULT 'none'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to the original enum values
        DB::statement("ALTER TABLE daily_goals MODIFY COLUMN input_type ENUM('none','text','phone','file','image','video') NOT NULL DEFAULT 'none'");
    }
};
