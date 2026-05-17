<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Anonymise les données sensibles après import d'un dump prod en local
 * (cf. scripts/load-dump.sh) :
 *  - Emails utilisateurs → user{id}@local.test
 *  - Passwords → tous à 'password' (hashé une fois, partagé)
 *  - Secrets chiffrés (api_keys, oauth tokens) → vidés
 *    (ils ne déchiffreraient pas en local de toute façon — APP_KEY différent)
 *
 * Refuse de tourner en environnement de production (garde-fou).
 */
class DbScrub extends Command
{
    protected $signature = 'db:scrub {--force : Skip confirmation prompt}';

    protected $description = 'Anonymise les données sensibles (emails, passwords, secrets chiffrés) après import d\'un dump prod.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('REFUSÉ : cette commande ne doit JAMAIS être lancée en production.');
            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm('Anonymiser toutes les données sensibles ?', true)) {
            $this->warn('Annulé.');
            return self::SUCCESS;
        }

        // 1. Users : anonymiser emails + reset password.
        // On hashe une seule fois pour partager le hash entre tous les users
        // (Hash::make coûte ~100ms par appel, ça compte si on a 100+ users).
        $defaultPassword = Hash::make('password');
        $count = 0;
        User::query()->orderBy('id')->chunkById(100, function ($users) use ($defaultPassword, &$count) {
            foreach ($users as $user) {
                $user->email             = "user{$user->id}@local.test";
                $user->password          = $defaultPassword;
                $user->email_verified_at = $user->email_verified_at ?? now();
                $user->remember_token    = null;
                $user->saveQuietly();
                $count++;
            }
        });
        $this->info("✓ {$count} utilisateur(s) anonymisé(s) (email + password='password').");

        // 2. weather_apis : api_key + oauth chiffrés (cast 'encrypted' inopérant
        // en local car APP_KEY différent → les valeurs sont des chaînes opaques).
        $cleared = DB::table('weather_apis')->update([
            'api_key'             => null,
            'oauth_client_id'     => null,
            'oauth_client_secret' => null,
            'oauth_token'         => null,
            'oauth_expires_at'    => null,
            'last_error'          => null,
            'last_error_at'       => null,
        ]);
        $this->info("✓ {$cleared} weather_apis nettoyée(s) (api_key + oauth + last_error).");

        // 3. weather_models : oauth chiffrés.
        $cleared = DB::table('weather_models')->update([
            'oauth_client_id'     => null,
            'oauth_client_secret' => null,
            'oauth_token'         => null,
            'oauth_expires_at'    => null,
        ]);
        $this->info("✓ {$cleared} weather_model(s) nettoyé(s) (oauth).");

        // 4. settings.windy.api_key : valeur JSON, on met une chaîne vide.
        // (`Settings::flush()` purge le cache Redis derrière.)
        $affected = DB::table('settings')
            ->where('key', 'windy.api_key')
            ->update(['value' => json_encode('')]);
        if ($affected > 0) {
            app(\App\Services\Settings::class)->flush();
            $this->info('✓ settings.windy.api_key vidée.');
        }

        // 5. page_views : `visitor_hash` est déjà pseudo-anonyme côté RGPD mais
        // le rotate avec un autre sel local n'est pas trivial ; on les laisse.

        $this->newLine();
        $this->info('✓ Scrub terminé.');
        $this->line("  Mot de passe de TOUS les users : 'password'");
        $this->line('  Emails : user{id}@local.test');

        return self::SUCCESS;
    }
}
