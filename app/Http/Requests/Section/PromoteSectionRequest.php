<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Promote (move) a section's students in one go: a destination per student,
 * null for the ones who stay where they are.
 */
class PromoteSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->isPlatform());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'moves' => ['required', 'array', 'min:1'],
            'moves.*.student_id' => ['required', 'integer', 'distinct', Rule::exists('students', 'id')],
            'moves.*.section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'moves' => 'students',
            'moves.*.student_id' => 'student',
            'moves.*.section_id' => 'destination section',
        ];
    }

    /**
     * student id => destination section id (null = stays).
     *
     * @return array<int, int|null>
     */
    public function moves(): array
    {
        $moves = [];

        foreach ($this->validated('moves') as $move) {
            $moves[(int) $move['student_id']] = isset($move['section_id']) ? (int) $move['section_id'] : null;
        }

        return $moves;
    }
}
