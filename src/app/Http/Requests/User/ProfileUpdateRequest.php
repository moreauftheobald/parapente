<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name'   => ['required', 'string', 'max:120'],
            'pseudo' => [
                'nullable', 'string', 'min:2', 'max:40',
                'regex:/^[A-Za-z0-9_\-\.]+$/',
                Rule::unique('users', 'pseudo')->ignore($userId),
            ],
            'email'  => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'bio'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pseudo.regex'  => 'Le pseudo ne peut contenir que des lettres, chiffres, tirets, points et soulignés.',
            'pseudo.unique' => 'Ce pseudo est déjà pris.',
            'email.unique'  => 'Un autre compte utilise déjà cet email.',
        ];
    }
}
