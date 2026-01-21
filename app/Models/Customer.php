<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'user_id',
        'kpi_id',
        'pic',
        'institution',
        'position',
        'email',
        'phone_number',
        'notes',
        'current_kpi_id',
        'status',
        'category',
        'status_changed_at',
        'earned_points',
        'max_points',
        'score_percentage',
        'created_at',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function kpi(): BelongsTo {
        return $this->belongsTo(KPI::class);
    }

    public function currentKpi(): BelongsTo {
        return $this->belongsTo(KPI::class, 'current_kpi_id');
    }

    public function kpiScores()
    {
        return $this->hasMany(CustomerKpiScore::class);
    }

    public function progresses(): HasMany {
        return $this->hasMany(Progress::class);
    }

    protected $casts = [
        'status_changed_at' => 'datetime',
    ];
}
