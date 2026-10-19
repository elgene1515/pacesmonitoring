<?php

namespace App\Http\Requests\PaceRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher corrects their pending request. Every field is optional; the
 * service makes sure a request without a linked PACE still names one.
 */
class UpdatePaceRequestRequest extends FormRequest
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
            'pace_id' => ['sometimes', 'nullable', 'integer', Rule::exists('paces', 'id')->where('created_by', $this->user()?->id)],
            'pace_number' => ['sometimes', 'nullable', 'digits_between:1,50'],
            'pace_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pace_id' => 'PACE',
            'pace_number' => 'PACE number',
            'pace_name' => 'PACE name',
            'needed_by' => 'needed-by date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pace_id.exists' => 'That PACE is not one of yours.',
            'pace_number.digits_between' => 'The PACE number must contain digits only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestData(): array
    {
        return $this->safe()->only(['pace_id', 'pace_number', 'pace_name', 'quantity', 'needed_by', 'message']);
    }
}
