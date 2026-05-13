<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordUpdateRequest extends FormRequest
{
    protected $errorBag = 'updatePassword';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ];
    }
}
