<?php

namespace App\Models;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\Concerns\RestritoAoCliente;
use App\Models\Concerns\RestritoAoTecnico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Evento da agenda — visita preventiva, intervenção, ausência ou evento próprio.
// Projeção temporal central da operação (CLAUDE.md §6).
class EventoAgenda extends Model
{
    use RestritoAoCliente, RestritoAoTecnico, SoftDeletes;

    protected $table = 'eventos_agenda';

    // Isolamento por cliente (coluna direta).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->where('cliente_id', $clienteId);
    }

    // Isolamento por técnico (a sua agenda).
    protected static function restringirAoTecnico(Builder $query, int $tecnicoId): void
    {
        $query->where('tecnico_id', $tecnicoId);
    }

    /** @var list<string> */
    protected $fillable = [
        'tipo',
        'titulo',
        'inicio',
        'fim',
        'estado',
        'tecnico_id',
        'cliente_id',
        'local_id',
        'equipamento_id',
        'contrato_id',
        'cobertura', // 'incluida' | 'extra' | null — marcação para o saldo de visitas do contrato
        'intervencao_id',
        // `recorrencia` (RRULE): metadado em DESUSO. É escrita pelo GeradorVisitasPreventivas
        // mas NÃO é lida por ninguém — não há motor de RRULE. A recorrência real é uma linha
        // por ocorrência. Mantida só para rastreio / futura interop iCal.
        'recorrencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoEvento::class,
            'estado' => EstadoEvento::class,
            'inicio' => 'datetime',
            'fim' => 'datetime',
        ];
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }
}
