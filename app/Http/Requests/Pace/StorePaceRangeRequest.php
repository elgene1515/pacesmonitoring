<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin registers a run of PACE numbers in a branch's catalog, all with
 * one name. Numbers already there under that name are skipped.
 */
class StorePaceRangeRequest extends FormRequest
{
    /**
     * The most PACEs a single range registration may create at once.
     */
    public const MAX_RANGE_SIZE = 500;

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
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'start' => ['required', 'digits_between:1,50'],
            'end' => ['required', 'digits_between:1,50', 'gte:start', $this->rangeSize()],
            'pool_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Please select the branch whose catalog these PACEs go in.',
            'branch_id.exists' => 'The selected branch is invalid.',
            'start.digits_between' => 'The start number must contain digits only.',
            'end.digits_between' => 'The end number must contain digits only.',
            'end.gte' => 'The end number must not be less than the start number.',
            'pool_stock.min' => 'The stock cannot be negative.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['branch_id' => 'branch', 'pool_stock' => 'stock'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rangeData(): array
    {
        $data = $this->safe()->only(['name', 'start', 'end', 'pool_stock']);
        $data['name'] = trim((string) $data['name']);
        $data['pool_stock'] = isset($data['pool_stock']) ? (int) $data['pool_stock'] : 0;

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
