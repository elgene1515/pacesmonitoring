<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The school admin adjusts a pending stock request before handing it over:
 * the PACE, how many booklets and the date they are needed by.
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
            'pace_number' => ['sometimes', 'required', 'digits_between:1,50'],
            'pace_name' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
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
            'pace_number.digits_between' => 'The PACE number must contain digits only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestData(): array
    {
        return $this->safe()->only(['pace_number', 'pace_name', 'quantity', 'needed_by']);
    }
}
