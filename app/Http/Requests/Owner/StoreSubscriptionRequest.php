<?php

namespace App\Http\Requests\Owner;

use App\Enums\SubscriptionStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
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
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'status' => ['required', 'string', Rule::in(SubscriptionStatus::values())],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom attribute names used in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan_id' => 'plan',
            'starts_at' => 'start date',
            'ends_at' => 'end date',
        ];
    }

    /**
     * The end date must come after the start date.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
                return;
            }

            $start = $this->input('starts_at') ?? $this->route('subscription')?->starts_at?->toDateString();
            $end = $this->input('ends_at');

            if ($start && $end && $end <= $start) {
                $validator->errors()->add('ends_at', 'The end date must be after the start date.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionData(): array
    {
        return $this->safe()->only(['plan_id', 'status', 'starts_at', 'ends_at', 'notes']);
    }
}
