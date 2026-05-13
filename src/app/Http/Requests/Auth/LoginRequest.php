<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            // 'remember' : pas de règle stricte — le controller utilise
            // $request->boolean('remember') qui accepte 1, "1", "on", true.
            // La règle 'boolean' refuserait "on" (valeur par défaut HTML
            // d'une checkbox sans `value=`).
        ];
    }
}
