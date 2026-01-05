<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerKpiScore extends Model
{
    protected $fillable = [
        'customer_id',
        'user_id',
        'kpi_id',
        'tasks_completed',
        'tasks_total',
        'completion_rate',
        'kpi_weight',
        'earned_points',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'completion_rate' => 'decimal:2',
        'kpi_weight' => 'decimal:2',
        'earned_points' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    // Relasi
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kpi()
    {
        return $this->belongsTo(KPI::class);
    }
}