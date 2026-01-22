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
            'user_id' => $this->user_id, // Always include user_id for debugging and mapping
            'kpi_id' => $this->kpi_id,
        ];
    }
}
