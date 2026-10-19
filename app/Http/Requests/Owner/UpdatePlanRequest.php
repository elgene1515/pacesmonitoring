<?php

namespace App\Http\Requests\Owner;

use Illuminate\Validation\Rule;

class UpdatePlanRequest extends StorePlanRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($this->route('plan'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_branches' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'max_admins' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'max_teachers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'max_students' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'price_per_year' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planData(): array
    {
        $data = parent::planData();

        if (! $this->has('name')) {
            unset($data['name']);
        }

        return $data;
    }
}
