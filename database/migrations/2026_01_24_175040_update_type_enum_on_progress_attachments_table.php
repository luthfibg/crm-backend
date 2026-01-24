<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE progress_attachments 
            MODIFY COLUMN type 
            ENUM(
                'file',
                'image',
                'video',
                'text',
                'phone',
                'date',
                'number',
                'currency'
            ) NOT NULL DEFAULT 'file'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE progress_attachments 
            MODIFY COLUMN type 
            ENUM(
                'file',
                'image',
                'video',
                'text',
                'phone'
            ) NOT NULL DEFAULT 'file'
        ");
    }
};
