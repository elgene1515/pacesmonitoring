<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SchoolYear
 */
class SchoolYearResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `is_current` is what counts as current for the viewer (a teacher's own
     * pick, or the organization's default); `is_organization_default` is the
     * organization's default regardless of the viewer.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $default = $this->resource->getAttribute('is_organization_default');

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_current' => (bool) $this->is_current,
            'is_organization_default' => $default === null ? (bool) $this->getRawOriginal('is_current') : (bool) $default,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'grade_levels_count' => $this->whenCounted('gradeLevels'),
            'students_count' => $this->whenCounted('students'),
            'assignments_count' => $this->when(
                $this->resource->getAttribute('assignments_count') !== null,
                fn () => (int) $this->resource->getAttribute('assignments_count')
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
