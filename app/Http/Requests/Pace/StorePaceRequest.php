<?php

namespace App\Http\Requests\Pace;

use App\Models\Pace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin registers a PACE in a branch's catalog, optionally with booklets
 * already in the pool.
 */
class StorePaceRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'number' => ['required', 'digits_between:1,50', $this->notInCatalog()],
            'pool_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Please select the branch whose catalog this PACE goes in.',
            'branch_id.exists' => 'The selected branch is invalid.',
            'number.digits_between' => 'The PACE number must contain digits only.',
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
    public function paceData(): array
    {
        $data = $this->safe()->only(['name', 'number', 'pool_stock']);
        $data['name'] = trim((string) $data['name']);
        $data['pool_stock'] = isset($data['pool_stock']) ? (int) $data['pool_stock'] : 0;

        return $data;
    }

    /**
     * The same number + name may not be registered twice in one branch.
     */
    protected function notInCatalog(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $exists = Pace::query()
                ->where('branch_id', (int) $this->input('branch_id'))
                ->where('number', (string) $value)
                ->where('name', trim((string) $this->input('name')))
                ->exists();

            if ($exists) {
                $fail("This PACE is already in the branch's catalog.");
            }
        };
    }
}
