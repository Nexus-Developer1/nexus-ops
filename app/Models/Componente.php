<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Componente de um equipamento (baterias, peças) — histórico de substituições.
class Componente extends Model
{
    protected $table = 'componentes';

    /** @var list<string> */
    protected $fillable = [
        'equipamento_id',
        'tipo',
        'numero_serie',
        'data_instalacao',
        'data_substituicao',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data_instalacao' => 'date',
            'data_substituicao' => 'date',
        ];
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }
}
