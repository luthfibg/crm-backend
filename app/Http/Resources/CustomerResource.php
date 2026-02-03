<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
public function toArray(Request $request): array
    {
        // Get products as array of objects when loaded
        $productsArray = $this->whenLoaded('products', function () {
            return $this->products->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'default_price' => $product->default_price,
                ];
            });
        }, []);

        // Also check if product_ids exists as JSON array in the column
        $productIdsFromColumn = [];
        if ($this->product_ids && is_string($this->product_ids)) {
            $decoded = json_decode($this->product_ids, true);
            if (is_array($decoded)) {
                $productIdsFromColumn = $decoded;
            }
        } elseif (is_array($this->product_ids)) {
            $productIdsFromColumn = $this->product_ids;
        }

        // If we have product_ids but products relationship is not loaded, get product details
        $productsFromIds = [];
        if (empty($productsArray) && !empty($productIdsFromColumn)) {
            $productsFromIds = \App\Models\Product::whereIn('id', $productIdsFromColumn)
                ->get(['id', 'name', 'default_price'])
                ->map(function($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'default_price' => $product->default_price,
                    ];
                })
                ->toArray();
        }

        // Use products from relationship or fallback to productsFromIds
        $finalProducts = !empty($productsArray) ? $productsArray : $productsFromIds;

        // Get comma-separated names for backward compatibility
        $productNames = collect($finalProducts)->pluck('name')->implode(', ');

        return [
            'id' => $this->id,
            'pic' => $this->pic,
            'institution' => $this->institution,
            'position' => $this->position,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'notes' => $this->notes,
            'category' => $this->category,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at,
            'user_id' => $this->user_id,
            'kpi_id' => $this->kpi_id,
            'current_kpi_id' => $this->current_kpi_id,
            'display_name' => $this->display_name,
            'sub_category' => $this->sub_category,
            // Products as array of objects
            'products' => $finalProducts,
            // Product IDs for reference
            'product_ids' => $productIdsFromColumn,
            // Comma-separated product names (for backward compatibility)
            'product_names' => $productNames,
        ];
    }
}
