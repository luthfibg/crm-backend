<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
