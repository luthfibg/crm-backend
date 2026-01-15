<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyGoalType extends Model
{
    protected $fillable = ['name', 'description'];

    // Relasi ke DailyGoal
    public function dailyGoals()
    {
        return $this->hasMany(DailyGoal::class);
    }
}
