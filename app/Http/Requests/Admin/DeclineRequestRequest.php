<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decline a teacher's request (teaching assignment, student change) with an
 * optional reason the teacher can read.
 */
class DeclineRequestRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('reason', ''));

        return $reason === '' ? null : $reason;
    }
}
