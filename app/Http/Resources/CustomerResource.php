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
        $isAdmin = $request->user() && $request->user()->role === 'administrator';

        return [
            'id' => $this->id,
            'pic' => $this->pic,
            'institution' => $this->institution,
            'position' => $this->position,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Only include these for administrators
            'user_id' => $this->when($isAdmin, $this->user_id),
            'kpi_id' => $this->when($isAdmin, $this->kpi_id),
        ];
    }
}
