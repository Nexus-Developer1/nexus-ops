<?php

namespace App\Models;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Evento da agenda — visita preventiva, intervenção, ausência ou evento próprio.
// Projeção temporal central da operação (CLAUDE.md §6).
class EventoAgenda extends Model
{
    use RestritoAoCliente, SoftDeletes;

    protected $table = 'eventos_agenda';

    // Isolamento por cliente (coluna direta). NÃO há isolamento por técnico: o técnico tem a
    // mesma visibilidade que o admin (exceto gerir utilizadores).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->where('cliente_id', $clienteId);
    }

    /** @var list<string> */
    protected $fillable = [
        'tipo',
        'titulo',
        'inicio',
        'fim',
        'estado',
        'tecnico_id',
        'tecnico_nome', // nome do técnico em texto livre (quando não é uma conta de utilizador)
        'cliente_id',
        'local_id',
        'equipamento_id',
        'contrato_id',
        'cobertura', // 'incluida' | 'extra' | null — marcação para o saldo de visitas do contrato
        'intervencao_id',
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

    // Técnicos ADICIONAIS (além do principal em tecnico_id) — um evento pode ter vários.
    public function tecnicosAdicionais(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'evento_tecnicos', 'evento_agenda_id', 'user_id');
    }

    // Ids de TODOS os técnicos do evento (principal + adicionais) — conflitos, iCal, notificações.
    /** @return list<int> */
    public function tecnicoIdsTodos(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            [$this->tecnico_id],
            $this->tecnicosAdicionais->pluck('id')->all(),
        ))));
    }

    // Nomes a mostrar: principal (conta ligada ou nome em texto livre) + adicionais.
    public function getTecnicoLabelAttribute(): ?string
    {
        $nomes = array_values(array_unique(array_filter(array_merge(
            [$this->tecnico?->nome ?? $this->tecnico_nome],
            $this->tecnicosAdicionais->pluck('nome')->all(),
        ))));

        return $nomes === [] ? null : implode(', ', $nomes);
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
