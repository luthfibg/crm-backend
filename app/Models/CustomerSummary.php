<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSummary extends Model
{
    protected $table = 'customer_summaries';

    protected $fillable = [
        'customer_id',
        'user_id',
        'kpi_id',
        'summary',
    ];

    /**
     * Get the customer that owns the summary.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user (sales) who created the summary.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the KPI this summary belongs to.
     */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(KPI::class);
    }
}

