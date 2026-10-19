<?php

namespace App\Http\Requests\Owner;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a plan change records the subscription period; a cancellation
 * needs no dates.
 */
class ApprovePlanRequestRequest extends FormRequest
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
        $cancellation = $this->route('planRequest')?->isCancellation() ?? false;

        return [
            'starts_at' => [$cancellation ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'decision_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_at' => 'start date',
            'ends_at' => 'end date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('starts_at');
            $end = $this->input('ends_at');

            if ($start && $end && $end <= $start) {
                $validator->errors()->add('ends_at', 'The end date must be after the start date.');
            }
        });
    }
}
