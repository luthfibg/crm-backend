<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyGoal extends Model
{
    protected $table = 'daily_goals';

    protected $fillable = [
        'description',
        'user_id',
        'kpi_id',
        'is_completed',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(KPI::class);
    }

    public function progresses(): HasMany
    {
        return $this->hasMany(Progress::class);
    }
}