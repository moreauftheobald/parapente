<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Site;
use App\Models\UserSiteCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Création d'un scoring perso pour le user authentifié sur un site donné.
 */
class StoreUserScoringRequest extends FormRequest
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
            'site_id' => [
                'required', 'integer',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('active', true)),
                Rule::unique('user_site_conditions', 'site_id')->where(
                    fn ($q) => $q->where('user_id', $userId)
                ),
            ],
            ...ScoringRules::flyingConditions(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $userId = $this->user()->id;

            // Cap soft sur le nombre de scorings stockés par user
            $count = UserSiteCondition::where('user_id', $userId)->count();
            if ($count >= UserSiteCondition::MAX_STORED) {
                $v->errors()->add('site_id', sprintf(
                    'Tu as atteint la limite de %d scorings perso enregistrés. Supprime-en avant d\'en créer un nouveau.',
                    UserSiteCondition::MAX_STORED,
                ));
            }

            ValidatesScoringCoherence::checkRanges($v, $this->all());
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'site_id.exists'         => 'Ce site n\'existe pas ou n\'est plus actif.',
            'site_id.unique'         => 'Tu as déjà un scoring perso sur ce site — édite-le plutôt.',
        ];
    }
}
