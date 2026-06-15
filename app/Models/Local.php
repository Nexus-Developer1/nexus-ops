<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Local de um cliente (instalação onde residem os equipamentos). Origem: aplicação.
class Local extends Model
{
    protected $table = 'locais';

    /** @var list<string> */
    protected $fillable = [
        'cliente_id',
        'designacao',
        'morada',
        'latitude',
        'longitude',
        'notas_acesso',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function equipamentos(): HasMany
    {
        return $this->hasMany(Equipamento::class);
    }
}
