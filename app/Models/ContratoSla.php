<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SLA de um contrato — medido contra as intervenções corretivas (CLAUDE.md §4).
// (A "prioridade" saiu a pedido da equipa; a coluna fica na BD para o histórico.)
class ContratoSla extends Model
{
    protected $table = 'contrato_slas';

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'tempo_resposta_horas',
        'resposta_nbd',
        'tempo_resolucao_horas',
        'horario_cobertura',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resposta_nbd' => 'boolean',
        ];
    }

    // Rótulo do tempo de resposta: NBD (Next Business Day) ou as horas.
    public function rotuloResposta(): string
    {
        if ($this->resposta_nbd) {
            return 'NBD';
        }

        return $this->tempo_resposta_horas ? $this->tempo_resposta_horas . ' h' : '—';
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
