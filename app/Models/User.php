<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'address',
        'role',
        'profile_photo_path',
        'date_of_birth',
        'points',
        'level',
        'bio',
        'badge',
        'is_developer_mode',
        'allow_force_push'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'points' => 'integer',
            'level' => 'integer',
        ];
    }

    public function customers(): HasMany {
        return $this->hasMany(Customer::class);
    }

    public function dailyGoals(): HasMany {
        return $this->hasMany(DailyGoal::class);
    }

    public function progresses(): HasMany {
        return $this->hasMany(Progress::class);
    }

    public function kpis()
    {
        // Sales can have many kpi's - pivot table medium
        // Explicit pivot keys to avoid incorrect snake-casing for class name "KPI"
        return $this->belongsToMany(\App\Models\KPI::class, 'kpi_user', 'user_id', 'kpi_id')->withTimestamps();
    }

    protected static function booted()
    {
        static::updating(function ($model) {
            if ($model->isDirty('email')) {
                // cancel the change by reverting to the original value
                // $model->email = $model->getOriginal('email');

                // throw an exception to prevent the update
                abort(403, 'Kolom email tidak boleh diubah.');
            }
        });
    }
}
