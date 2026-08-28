<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolPersona;
use Database\Factories\CuentaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

/**
 * Reemplaza al modelo `User` por defecto de Laravel como modelo de
 * autenticación (ver config/auth.php: providers.users.model).
 */
class Cuenta extends Authenticatable
{
    /** @use HasFactory<CuentaFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'cuentas';

    /** @var list<string> */
    protected $fillable = [
        'persona_id',
        'email',
        'password',
        'rol',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol' => RolPersona::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @param  Builder<Cuenta>  $query
     * @return Builder<Cuenta>
     */
    public function scopeConRol(Builder $query, RolPersona $rol): Builder
    {
        return $query->where('rol', $rol);
    }
}
