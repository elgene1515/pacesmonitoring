<?php

namespace App\Http\Requests\PlanRequest;

use App\Models\PlanRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A school admin asks to change plan (`change` + plan_id) or to cancel (`cancel`).
 */
class StorePlanRequestRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(PlanRequest::actions())],
            'plan_id' => [
                Rule::requiredIf(fn () => $this->input('action') === PlanRequest::ACTION_CHANGE),
                'nullable',
                'integer',
                Rule::exists('plans', 'id')->where('is_active', true),
            ],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['plan_id' => 'plan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.exists' => 'That plan is not available.',
            'plan_id.required' => 'Pick the plan you want to move to.',
        ];
    }
}
