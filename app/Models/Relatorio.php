<?php

namespace App\Models;

use App\Enums\EstadoRelatorio;
use App\Models\Concerns\RestritoAoCliente;
use App\Models\Concerns\RestritoAoTecnico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Relatório gerado de uma intervenção (com numeração sequencial).
class Relatorio extends Model
{
    use RestritoAoCliente, RestritoAoTecnico, SoftDeletes;

    protected $table = 'relatorios';

    // Isolamento por cliente (via intervenção → equipamento → local).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->whereHas('intervencao.equipamento.local', fn ($q) => $q->where('cliente_id', $clienteId));
    }

    // Isolamento por técnico (relatórios das suas intervenções).
    protected static function restringirAoTecnico(Builder $query, int $tecnicoId): void
    {
        $query->whereHas('intervencao', fn ($q) => $q->where('tecnico_id', $tecnicoId));
    }

    /** @var list<string> */
    protected $fillable = [
        'intervencao_id',
        'numero',
        'data',
        'estado',
        'pdf_path',
        'enviado_em',
        'enviado_para',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'estado' => EstadoRelatorio::class,
            'enviado_em' => 'datetime',
        ];
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }
}
