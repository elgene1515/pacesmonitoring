<?php

namespace App\Http\Requests\Pace;

use Illuminate\Foundation\Http\FormRequest;

class StorePaceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'number' => ['required', 'digits_between:1,50'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.digits_between' => 'The PACE number must contain digits only.',
            'stock.min' => 'The stock cannot be negative.',
        ];
    }

    /**
     * The validated attributes that may be persisted on a PACE. A blank stock
     * is left out, so an update without it keeps the current count.
     *
     * @return array<string, mixed>
     */
    public function paceData(): array
    {
        $data = $this->safe()->only(['name', 'number', 'stock']);

        if (array_key_exists('stock', $data)) {
            if ($data['stock'] === null) {
                unset($data['stock']);
            } else {
                $data['stock'] = (int) $data['stock'];
            }
        }

        return $data;
    }
}
