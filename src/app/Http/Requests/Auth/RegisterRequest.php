<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:120'],
            'pseudo'   => ['nullable', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_\-\.]+$/', 'unique:users,pseudo'],
            'email'    => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pseudo.regex'    => 'Le pseudo ne peut contenir que des lettres, chiffres, tirets, points et soulignés.',
            'pseudo.unique'   => 'Ce pseudo est déjà pris.',
            'email.unique'    => 'Un compte existe déjà avec cet email.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}
