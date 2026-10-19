<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Branch
 */
class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'is_main' => (bool) $this->is_main,
            'teachers_count' => $this->whenCounted('teachers'),
            'users_count' => $this->whenCounted('users'),
            // Students of the branch's teachers, when the organization detail computed it.
            'students_count' => $this->when(
                $this->resource->getAttribute('students_count') !== null,
                fn () => (int) $this->resource->getAttribute('students_count')
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
