<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Édition d'un scoring perso existant. Le site_id n'est jamais modifiable
 * (l'utilisateur supprime et recrée si besoin).
 */
class UpdateUserScoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usc = $this->route('scoring');
        return $usc !== null && $this->user() !== null
            && (int) $usc->user_id === (int) $this->user()->id;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ScoringRules::flyingConditions();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            ValidatesScoringCoherence::checkRanges($v, $this->all());
        });
    }
}
