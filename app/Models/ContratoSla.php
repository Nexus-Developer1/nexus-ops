<?php

namespace App\Models;

use App\Enums\PrioridadeSla;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SLA de um contrato, por prioridade — medido contra as intervenções corretivas (CLAUDE.md §4).
class ContratoSla extends Model
{
    protected $table = 'contrato_slas';

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'prioridade',
        'tempo_resposta_horas',
        'tempo_resolucao_horas',
        'horario_cobertura',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'prioridade' => PrioridadeSla::class,
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
