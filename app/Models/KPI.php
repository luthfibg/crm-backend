<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Customer;
use App\Models\DailyGoal;
use App\Models\User;

class KPI extends Model
{
    protected $table = 'kpis';

    protected $fillable = [
        'code',
        'description',
        'weight_point',
        'type',
        'sequence',
        'note'
    ];

    public function customers(): HasMany {
        return $this->hasMany(Customer::class);
    }

    public function dailyGoals(): HasMany {
        return $this->hasMany(DailyGoal::class);
    }

    public function users()
    {
        // One KPI can be owned by many sales - pivot table
        return $this->belongsToMany(User::class, 'kpi_user')->withTimestamps();
    }
}
