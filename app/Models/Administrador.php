<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdministradorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administrador extends Model
{
    /** @use HasFactory<AdministradorFactory> */
    use HasFactory;

    protected $table = 'administradores';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = [
        'persona_id',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}