<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'pseudo', 'bio', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Vrai si l'utilisateur a le rôle admin (accès au BackOffice).
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Nom à afficher : pseudo s'il est défini, sinon nom complet.
     * Utilisé notamment pour le bandeau « Scoring perso · {pseudo} ».
     */
    public function displayName(): string
    {
        return $this->pseudo !== null && $this->pseudo !== ''
            ? $this->pseudo
            : $this->name;
    }
}
