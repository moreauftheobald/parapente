<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class AccountDeleteRequest extends FormRequest
{
    protected $errorBag = 'deleteAccount';

    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isAdmin();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => 'Le mot de passe est incorrect.',
        ];
    }
}
