<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Customer;
use App\Models\DailyGoal;

class KPI extends Model
{
    protected $table = 'kpis';

    protected $fillable = [
        'code',
        'description',
        'weight_point',
        'total_daily_goals',
        'type',
    ];

    public function customers(): HasMany {
        return $this->hasMany(Customer::class);
    }

    public function dailyGoals(): HasMany {
        return $this->hasMany(DailyGoal::class);
    }
}
