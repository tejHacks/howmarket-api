<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'area'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Same defaults as the database, so a new model reads them before it is reloaded. */
    protected $attributes = ['area' => 'Ibadan', 'trust' => 1];

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
            'trust' => 'float',
        ];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(PriceReport::class);
    }

    /**
     * An id other people may see next to a report. Stable, but not the account id,
     * so reporters can't be enumerated or linked to an account from the app.
     */
    public function publicId(): string
    {
        return substr(hash_hmac('sha256', 'reporter:'.$this->id, (string) config('app.key')), 0, 16);
    }
}
