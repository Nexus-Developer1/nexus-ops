<?php

namespace App\Models;

use App\Enums\Periodicidade;
use App\Enums\TipoEquipamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Plano de visita preventiva de um contrato, por tipo de equipamento.
// Define a periodicidade que alimenta a geração automática de visitas (CLAUDE.md §6).
class ContratoPlanoVisita extends Model
{
    protected $table = 'contrato_planos_visita';

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'equipamento_tipo',
        'periodicidade',
        'duracao_estimada_min',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'equipamento_tipo' => TipoEquipamento::class,
            'periodicidade' => Periodicidade::class,
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
