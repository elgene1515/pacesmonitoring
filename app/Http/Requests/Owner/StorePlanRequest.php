<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($this->route('plan'))],
            'description' => ['nullable', 'string', 'max:255'],
            'max_branches' => ['required', 'integer', 'min:1', 'max:65535'],
            'max_admins' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'max_teachers' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'price_per_year' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
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
            'max_branches' => 'branch limit',
            'max_admins' => 'admin limit',
            'max_teachers' => 'teacher limit',
            'max_students' => 'student limit',
            'price_per_year' => 'price per year',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planData(): array
    {
        $data = $this->safe()->only([
            'name', 'slug', 'description', 'max_branches', 'max_admins', 'max_teachers', 'max_students', 'price_per_year', 'currency', 'is_active', 'sort_order',
        ]);

        $data['name'] = trim((string) ($data['name'] ?? ''));

        if (empty($data['slug'])) {
            unset($data['slug']);

            if ($this->isMethod('post')) {
                $data['slug'] = Str::slug($data['name']) ?: Str::lower(Str::random(8));
            }
        }

        if (array_key_exists('currency', $data)) {
            $data['currency'] = $data['currency'] === null ? 'PHP' : Str::upper($data['currency']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $this->boolean('is_active');
        }

        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }
}
