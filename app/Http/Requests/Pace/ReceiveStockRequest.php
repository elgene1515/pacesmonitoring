<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A delivery of booklets arrived at the branch: add them to the pool.
 */
class ReceiveStockRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
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
