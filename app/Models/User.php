<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements \Filament\Models\Contracts\FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, \Laravel\Fortify\TwoFactorAuthenticatable;

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
            'active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->active && $this->role === 'owner_admin'
            && (bool) $this->two_factor_secret && $this->two_factor_confirmed_at !== null;
    }
}
