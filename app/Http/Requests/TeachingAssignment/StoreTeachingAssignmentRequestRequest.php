<?php

namespace App\Http\Requests\TeachingAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher asks to teach a subject to a section.
 */
class StoreTeachingAssignmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'section_id' => 'section',
            'subject_id' => 'subject',
        ];
    }
}
