<?php

namespace App\Http\Requests\Owner;

use App\Enums\SubscriptionStatus;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends StoreSubscriptionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['sometimes', 'required', 'integer', Rule::exists('plans', 'id')],
            'status' => ['sometimes', 'required', 'string', Rule::in(SubscriptionStatus::values())],
            'starts_at' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
