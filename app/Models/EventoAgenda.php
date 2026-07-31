<?php

namespace App\Models;

use App\Enums\EstadoEvento;
use App\Enums\EstadoRelatorio;
use App\Enums\TipoEvento;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Evento da agenda — visita preventiva, intervenção ou evento próprio.
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
        'horas_dias', // horas trabalhadas por dia (eventos multi-dia): [{dia, inicio, fim}, ...]
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoEvento::class,
            'estado' => EstadoEvento::class,
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'horas_dias' => 'array',
        ];
    }

    // Intervalos de trabalho REAIS do evento, como pares [início, fim] (Carbon).
    // Multi-dia com horas por dia → um segmento por dia (só linhas válidas e dentro de
    // [inicio..fim] — imune a horas_dias tornado obsoleto por uma edição do intervalo);
    // caso contrário → o próprio [inicio, fim] (evento de um dia ou legado contínuo).
    /** @return list<array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}> */
    public function segmentos(): array
    {
        $segmentos = collect($this->horas_dias ?? [])
            ->map(function ($linha): ?array {
                $dia = $linha['dia'] ?? null;
                $ini = $linha['inicio'] ?? null;
                $fim = $linha['fim'] ?? null;
                if (! is_string($dia) || ! is_string($ini) || ! is_string($fim)) {
                    return null;
                }

                try {
                    $de = \Illuminate\Support\Carbon::parse("$dia $ini");
                    $ate = \Illuminate\Support\Carbon::parse("$dia $fim");
                } catch (\Throwable) {
                    return null;
                }

                return $de->lt($ate) ? [$de, $ate] : null;
            })
            ->filter()
            ->filter(fn (array $s) => ! $s[0]->lt($this->inicio->copy()->startOfDay())
                && ! $s[1]->gt($this->fim->copy()->endOfDay()))
            ->sortBy(fn (array $s) => $s[0])
            ->values();

        if ($segmentos->count() < 2) {
            return [[$this->inicio, $this->fim]];
        }

        // Os segmentos têm de cobrir o PRIMEIRO e o ÚLTIMO dia do evento. Se não cobrem, são
        // restos de um intervalo anterior (ex.: o fim foi esticado noutro sítio) e usá-los
        // deixava os dias novos invisíveis no calendário e livres para double-booking — nesse
        // caso vale o intervalo contínuo, que nunca subestima o trabalho (14.ª revisão).
        $cobrePrimeiro = $segmentos->first()[0]->isSameDay($this->inicio);
        $cobreUltimo = $segmentos->last()[1]->isSameDay($this->fim);

        return ($cobrePrimeiro && $cobreUltimo) ? $segmentos->all() : [[$this->inicio, $this->fim]];
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

    // Ids de TODOS os técnicos do evento (principal + adicionais) — conflitos e iCal.
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

    // Editável/removível pela agenda? Preventivas nunca (são geridas pelo contrato — a geração
    // apaga/recria planeadas e dessincronizava). Convertidos só enquanto o relatório for
    // RASCUNHO — depois de finalizado/enviado é documento oficial e o evento fica trancado
    // (edita-se abrindo a intervenção). Regra partilhada por editar e remover.
    public function editavelPelaAgenda(): bool
    {
        if ($this->tipo === TipoEvento::VisitaPreventiva) {
            return false;
        }

        $relatorio = $this->intervencao?->relatorio;

        return ! $relatorio || $relatorio->estado === EstadoRelatorio::Rascunho;
    }
}
