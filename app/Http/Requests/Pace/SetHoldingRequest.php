<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set how many booklets a teacher has; the difference comes from or goes
 * back to the pool.
 */
class SetHoldingRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
