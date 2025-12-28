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
        Schema::table('customers', function (Blueprint $table) {
            // change name column to pic
            $table->renameColumn('name', 'pic');
            // add new columns
            $table->string('position')->nullable()->after('institution');
            $table->string('email')->nullable()->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // revert name column back
            $table->renameColumn('pic', 'name');
            // drop newly added columns
            $table->dropColumn(['position', 'email']);
        });
    }
};
