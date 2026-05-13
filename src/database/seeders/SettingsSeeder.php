<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Database\Seeder;

/**
 * Initialise / met à jour les libellés et descriptions des paramètres
 * globaux. Si une clé n'a pas de valeur en base, on insère la valeur par
 * défaut (catalogue Settings::DEFAULTS). Si la valeur existe déjà, on ne
 * l'écrase pas — on rafraîchit seulement label / description.
 *
 * Lance la commande : `php artisan db:seed --class=SettingsSeeder`
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Settings::DEFAULTS as $key => $meta) {
            $existing = Setting::where('key', $key)->first();
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value'       => $existing?->value ?? $meta['default'],
                    'label'       => $meta['label']       ?? null,
                    'description' => $meta['description'] ?? null,
                ]
            );
        }

        app(Settings::class)->flush();
    }
}
