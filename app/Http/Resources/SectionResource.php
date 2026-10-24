<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Section
 */
class SectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $viewerId = $request?->user()?->id;

        return [
            'id' => $this->id,
            'grade_level_id' => $this->grade_level_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'students_count' => $this->whenCounted('students'),
            'grade_level' => new GradeLevelResource($this->whenLoaded('gradeLevel')),
            // The subjects the section offers (ids of its grade level's subjects).
            'subject_ids' => $this->whenLoaded('subjects', fn () => $this->subjects->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
            // Who teaches what in this section.
            'teaching_assignments' => TeachingAssignmentResource::collection($this->whenLoaded('teachingAssignments')),
            // The subjects the signed-in teacher handles in this section (from the loaded assignments).
            'my_subject_ids' => $this->when(
                $viewerId !== null && $this->relationLoaded('teachingAssignments'),
                fn () => $this->teachingAssignments
                    ->where('teacher_id', $viewerId)
                    ->pluck('subject_id')
                    ->values()
                    ->all()
            ),
            'students' => StudentResource::collection($this->whenLoaded('students')),
        ];
    }
}
