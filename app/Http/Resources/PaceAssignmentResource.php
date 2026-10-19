<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaceAssignment
 */
class PaceAssignmentResource extends JsonResource
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
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'pace_id' => $this->pace_id,
            'school_year' => $this->school_year,
            'quarter' => $this->quarter,
            'issued_date' => $this->issued_date?->format('Y-m-d'),
            'date_tested' => $this->date_tested?->format('Y-m-d'),
            'score' => $this->score === null ? null : (float) $this->score,
            'remarks' => $this->remarks,
            'retake' => (bool) $this->retake,
            'pace' => new PaceResource($this->whenLoaded('pace')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'student' => new StudentResource($this->whenLoaded('student')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
