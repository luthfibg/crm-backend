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
        // Get product names as comma-separated string
        $productNames = $this->whenLoaded('products', function () {
            return $this->products->pluck('name')->implode(', ');
        }, '');

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

        // If we have product_ids but products relationship is not loaded, get names
        $productNamesFromIds = '';
        if (empty($productNames) && !empty($productIdsFromColumn)) {
            $productNamesFromIds = \App\Models\Product::whereIn('id', $productIdsFromColumn)
                ->pluck('name')
                ->implode(', ');
        }

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
            // Products as comma-separated names
            'products' => $productNames ?: $productNamesFromIds,
            'product_ids' => $productIdsFromColumn,
        ];
    }
}
