<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Crée un compte administrateur en interactif (ou via options non
 * interactives pour script). Pas d'inscription publique côté UI →
 * tout admin doit passer par cette commande.
 *
 * Usage interactif :
 *   php artisan admin:create
 *
 * Usage non interactif (CI / déploiement) :
 *   php artisan admin:create --name="..." --email="..." --password="..."
 *
 * Si l'email existe déjà, on propose la promotion en admin (et on
 * peut aussi reset le mot de passe).
 */
class AdminCreate extends Command
{
    protected $signature = 'admin:create
        {--name= : Nom complet}
        {--email= : Email}
        {--password= : Mot de passe (déconseillé en CLI, préférer le mode interactif)}';

    protected $description = "Crée un compte administrateur du BackOffice";

    public function handle(): int
    {
        $name     = $this->option('name')     ?: $this->ask('Nom complet');
        $email    = $this->option('email')    ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Mot de passe');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name'     => ['required', 'string', 'max:255'],
                'email'    => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $err) {
                $this->error($err);
            }
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            if (! $this->confirm("Un user avec cet email existe déjà. Le promouvoir admin et reset son mot de passe ?")) {
                $this->warn('Abandonné.');
                return self::FAILURE;
            }
            $existing->name     = $name;
            $existing->password = Hash::make($password);
            $existing->role     = 'admin';
            $existing->save();
            $this->info("✓ User #{$existing->id} ({$email}) mis à jour en admin.");
            return self::SUCCESS;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password, // hashé via le cast 'hashed'
            'role'     => 'admin',
        ]);

        $this->info("✓ Admin créé : #{$user->id} ({$user->email}).");
        return self::SUCCESS;
    }
}
