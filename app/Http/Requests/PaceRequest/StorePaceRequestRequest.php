<?php

namespace App\Http\Requests\PaceRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher asks their school admin for PACE booklets: one of their registered
 * PACEs (`pace_id`) or a PACE named by number and name.
 */
class StorePaceRequestRequest extends FormRequest
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
        $freeForm = fn (): bool => ! $this->filled('pace_id');

        return [
            'pace_id' => ['nullable', 'integer', Rule::exists('paces', 'id')->where('created_by', $this->user()?->id)],
            'pace_number' => [Rule::requiredIf($freeForm), 'nullable', 'digits_between:1,50'],
            'pace_name' => [Rule::requiredIf($freeForm), 'nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
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
            'pace_number.required' => 'Pick one of your PACEs or enter the PACE number.',
            'pace_name.required' => 'Pick one of your PACEs or enter the PACE name.',
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
