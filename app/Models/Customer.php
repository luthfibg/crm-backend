<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Customer extends Model
{
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
        'sub_category',
        'status_changed_at',
        'earned_points',
        'max_points',
        'score_percentage',
        'created_at',
        'product_ids',
    ];

    protected $casts = [
        'status_changed_at' => 'datetime',
        'earned_points' => 'decimal:2',
        'max_points' => 'decimal:2',
        'score_percentage' => 'decimal:2',
    ];

    /**
     * Get the user that owns the customer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the KPI that owns the customer.
     */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(KPI::class);
    }

    /**
     * Get the current KPI for the customer.
     */
    public function currentKpi(): BelongsTo
    {
        return $this->belongsTo(KPI::class, 'current_kpi_id');
    }

    /**
     * Get the progresses for the customer.
     */
    public function progresses(): HasMany
    {
        return $this->hasMany(Progress::class);
    }

    /**
     * Get the customer summaries for the customer.
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(CustomerSummary::class);
    }

    /**
     * Get the customer KPI scores for the customer.
     */
    public function kpiScores(): HasMany
    {
        return $this->hasMany(CustomerKpiScore::class);
    }

    /**
     * Get the products associated with the customer.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'customer_product')
            ->withPivot(['negotiated_price', 'notes'])
            ->withTimestamps();
    }
}
