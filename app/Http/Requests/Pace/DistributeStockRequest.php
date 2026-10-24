<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hand booklets from the pool to a teacher.
 */
class DistributeStockRequest extends FormRequest
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
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['teacher_id' => 'teacher'];
    }

    public function teacherId(): int
    {
        return (int) $this->validated('teacher_id');
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note', ''));

        return $note === '' ? null : $note;
    }
}
