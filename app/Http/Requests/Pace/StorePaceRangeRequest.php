<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;

class StorePaceRangeRequest extends FormRequest
{
    /**
     * The most PACEs a single range registration may create at once.
     */
    public const MAX_RANGE_SIZE = 500;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start' => ['required', 'digits_between:1,50'],
            'end' => ['required', 'digits_between:1,50', 'gte:start', $this->rangeSize()],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start.digits_between' => 'The start number must contain digits only.',
            'end.digits_between' => 'The end number must contain digits only.',
            'end.gte' => 'The end number must not be less than the start number.',
            'stock.min' => 'The stock cannot be negative.',
        ];
    }

    /**
     * The validated attributes needed to register the range.
     *
     * @return array<string, mixed>
     */
    public function rangeData(): array
    {
        $data = $this->safe()->only(['name', 'start', 'end', 'stock']);
        $data['stock'] = isset($data['stock']) ? (int) $data['stock'] : 0;

        return $data;
    }

    /**
     * Keep a single range registration from creating an unbounded number of PACEs.
     */
    protected function rangeSize(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $start = $this->input('start');

            if (! is_numeric($start) || ! is_numeric($value)) {
                return;
            }

            $size = ((int) $value) - ((int) $start) + 1;

            if ($size > self::MAX_RANGE_SIZE) {
                $fail('A single range may register at most '.self::MAX_RANGE_SIZE.' PACEs.');
            }
        };
    }
}
