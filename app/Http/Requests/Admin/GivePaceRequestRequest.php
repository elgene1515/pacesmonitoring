<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking a request as given: optionally the quantity actually handed over
 * (defaults to what was asked) and a note shown to the teacher.
 */
class GivePaceRequestRequest extends FormRequest
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
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'decision_note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
