<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Progress extends Model
{
    protected $table = 'progresses';

    protected $fillable = [
        'user_id',
        'kpi_id',
        'daily_goal_id',
        'customer_id',
        'time_completed',
        'progress_value',
        'progress_date',
        'status',
        'reviewer_note',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function dailyGoal(): BelongsTo {
        return $this->belongsTo(DailyGoal::class);
    }

    public function customer(): BelongsTo {
        return $this->belongsTo(Customer::class);
    }

    public function attachments(): HasMany {
        return $this->hasMany(\App\Models\ProgressAttachment::class);
    }
}
