<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'name',
        'default_price',
        'description',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'default_price' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user who created this product.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all customers who purchased this product.
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_product')
            ->withPivot(['negotiated_price', 'notes'])
            ->withTimestamps();
    }

    /**
     * Get only active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get products formatted for dropdown/list.
     */
    public static function getForDropdown()
    {
        return self::active()->orderBy('name')->get(['id', 'name', 'default_price']);
    }

    /**
     * Format price to Indonesian Rupiah.
     */
    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->default_price, 0, ',', '.');
    }
}

