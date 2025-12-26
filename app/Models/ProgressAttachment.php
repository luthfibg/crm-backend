<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressAttachment extends Model
{
    protected $table = 'progress_attachments';

    protected $fillable = [
        'progress_id',
        'original_name',
        'file_path',
        'mime_type',
        'size',
        'type',
        'content',
    ];

    public function progress(): BelongsTo {
        return $this->belongsTo(Progress::class);
    }
}
