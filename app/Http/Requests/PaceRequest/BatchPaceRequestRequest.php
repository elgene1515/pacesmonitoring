<?php

namespace App\Http\Requests\PaceRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Several stock requests in one go: a list of PACEs (from the branch catalog,
 * or named by number and name) each with its quantity, sharing one optional
 * needed-by date and note.
 */
class BatchPaceRequestRequest extends FormRequest
{
    public const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isTeacher() && $user->branch_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.pace_id' => ['nullable', 'integer', Rule::exists('paces', 'id')->where('branch_id', $this->user()?->branch_id)],
            'items.*.pace_number' => ['required_without:items.*.pace_id', 'nullable', 'digits_between:1,50'],
            'items.*.pace_name' => ['required_without:items.*.pace_id', 'nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'PACEs',
            'items.*.pace_id' => 'PACE',
            'items.*.pace_number' => 'PACE number',
            'items.*.pace_name' => 'PACE name',
            'items.*.quantity' => 'quantity',
            'needed_by' => 'needed-by date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Pick at least one PACE.',
            'items.min' => 'Pick at least one PACE.',
            'items.max' => 'Send at most '.self::MAX_ITEMS.' PACEs in one go.',
            'items.*.pace_id.exists' => "That PACE is not in your branch's catalog.",
            'items.*.pace_number.required_without' => 'Pick a PACE from the catalog or enter the PACE number.',
            'items.*.pace_name.required_without' => 'Pick a PACE from the catalog or enter the PACE name.',
            'items.*.pace_number.digits_between' => 'The PACE number must contain digits only.',
            'items.*.quantity.min' => 'Enter how many booklets you need (at least 1).',
        ];
    }

    /**
     * The items with the shared date and note folded in, ready for the service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        $data = $this->validated();

        return array_map(fn (array $item) => [
            'pace_id' => $item['pace_id'] ?? null,
            'pace_number' => $item['pace_number'] ?? null,
            'pace_name' => $item['pace_name'] ?? null,
            'quantity' => $item['quantity'],
            'needed_by' => $data['needed_by'] ?? null,
            'message' => $data['message'] ?? null,
        ], array_values($data['items']));
    }
}
