<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One pool change applied to several PACEs at once:
 *  - receive: booklets arrived, add `quantity` to every pool
 *  - adjust:  add (positive) or remove (negative) `quantity` from every pool
 *  - set:     make every pool exactly `quantity` (a recount)
 */
class BatchStockRequest extends FormRequest
{
    public const MODES = ['receive', 'adjust', 'set'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mode = $this->input('mode');

        $quantity = match ($mode) {
            'receive' => ['required', 'integer', 'min:1', 'max:1000000'],
            'adjust' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            default => ['required', 'integer', 'min:0', 'max:1000000'],
        };

        return [
            'pace_ids' => ['required', 'array', 'min:1', 'max:500'],
            'pace_ids.*' => ['required', 'integer', 'distinct'],
            'mode' => ['required', 'string', Rule::in(self::MODES)],
            'quantity' => $quantity,
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.not_in' => 'Enter how many booklets to add or remove.',
            'pace_ids.required' => 'Select at least one PACE.',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function paceIds(): array
    {
        return array_map('intval', $this->validated('pace_ids'));
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
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
