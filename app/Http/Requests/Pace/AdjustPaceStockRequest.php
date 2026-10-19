<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add (positive) or remove (negative) booklets from a PACE's stock.
 */
class AdjustPaceStockRequest extends FormRequest
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
            'delta' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delta.not_in' => 'Enter how many booklets to add or remove.',
        ];
    }

    public function delta(): int
    {
        return (int) $this->validated('delta');
    }
}
