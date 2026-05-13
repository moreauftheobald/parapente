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
        return [
            'wind_dir_min'         => ['required', 'integer', 'between:0,360'],
            'wind_dir_max'         => ['required', 'integer', 'between:0,360'],
            'wind_speed_min'       => ['required', 'integer', 'between:0,100'],
            'wind_speed_max'       => ['required', 'integer', 'between:0,100'],
            'wind_speed_ideal'     => ['required', 'integer', 'between:0,100'],
            'wind_gust_orange_kmh' => ['nullable', 'numeric', 'between:0,200'],
            'wind_gust_red_kmh'    => ['nullable', 'numeric', 'between:0,200'],
            'cloud_base_min_m'     => ['nullable', 'integer', 'between:0,5000'],
            'cloud_cover_low_max'  => ['nullable', 'integer', 'between:0,100'],
            'notes'                => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            ValidatesScoringCoherence::checkRanges($v, $this->all());
        });
    }
}
