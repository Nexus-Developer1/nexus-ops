<?php

namespace App\Models;

use App\Enums\EstadoContrato;
use App\Enums\TipoContrato;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

// Contrato de manutenção — nasce na app (CLAUDE.md §10). Âmbito, SLA, faturação e
// nº de visitas incluídas; as visitas são agendadas manualmente na agenda (§6).
class Contrato extends Model
{
    use HasFactory, RestritoAoCliente, SoftDeletes;

    protected $table = 'contratos';

    // Isolamento por cliente (coluna direta).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->where('cliente_id', $clienteId);
    }

    /** @var list<string> */
    protected $fillable = [
        'numero',
        'cliente_id',
        'data_inicio',
        'data_fim',
        'visitas_incluidas', // total de visitas incluídas pela vida do contrato (null = não controlado)
        'estado',
        'tipo',
        'modelo_faturacao_id',
        'valor',
        'periodo_faturacao',
        'coberturas',
        'exclusoes',
        'renovacao_automatica',
        'periodo_aviso_dias',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'estado' => EstadoContrato::class,
            'tipo' => TipoContrato::class,
            'valor' => 'decimal:2',
            'renovacao_automatica' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function modeloFaturacao(): BelongsTo
    {
        return $this->belongsTo(ModeloFaturacao::class, 'modelo_faturacao_id');
    }

    public function equipamentos(): BelongsToMany
    {
        return $this->belongsToMany(Equipamento::class, 'contrato_equipamentos')->withTimestamps();
    }

    public function slas(): HasMany
    {
        return $this->hasMany(ContratoSla::class);
    }

    // Alertas de visita programados (data + texto editável) — geridos no editor do contrato.
    public function alertasVisita(): HasMany
    {
        return $this->hasMany(ContratoAlertaVisita::class);
    }

    /**
     * Saldo de visitas do contrato (CLAUDE.md §6): determinístico — conta eventos pela
     * cobertura, não por periodicidade. Null = contrato sem controlo de saldo. Extraído da
     * ficha (Vaga 1) para o modal da agenda mostrar o saldo ao marcar a cobertura.
     *
     * @return array{incluidas: int, usadas: int, extras: int, restantes: int, excedido: int}|null
     */
    public function saldoVisitas(): ?array
    {
        $incluidas = $this->visitas_incluidas;
        if ($incluidas === null) {
            return null;
        }

        $usadas = $this->eventos()
            ->where('cobertura', 'incluida')
            ->where('estado', '!=', \App\Enums\EstadoEvento::Cancelado->value)
            ->count();
        $extras = $this->eventos()
            ->where('cobertura', 'extra')
            ->where('estado', '!=', \App\Enums\EstadoEvento::Cancelado->value)
            ->count();

        return [
            'incluidas' => $incluidas,
            'usadas' => $usadas,
            'extras' => $extras,
            'restantes' => max(0, $incluidas - $usadas), // nunca negativo
            'excedido' => max(0, $usadas - $incluidas),
        ];
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAgenda::class);
    }

    // Relatórios das intervenções feitas no âmbito deste contrato (via intervencoes.contrato_id
    // — é este campo que decide "incluído no contrato" vs. faturável à parte, CLAUDE.md §6).
    public function relatorios(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Relatorio::class, Intervencao::class, 'contrato_id', 'intervencao_id');
    }

    // Contratos ativos dentro da própria janela de aviso de renovação (CLAUDE.md §6).
    // Expressão ciente do driver: Postgres em produção, sqlite nos testes.
    public function scopeAExpirar(Builder $query): Builder
    {
        $condicao = DB::connection()->getDriverName() === 'pgsql'
            ? "data_fim <= (CURRENT_DATE + (periodo_aviso_dias || ' days')::interval)"
            : "data_fim <= date('now', '+' || periodo_aviso_dias || ' days')";

        return $query
            ->where('estado', EstadoContrato::Ativo)
            ->whereDate('data_fim', '>=', now())
            ->whereRaw($condicao);
    }

    // Dias até ao fim do contrato (negativo se já expirou).
    public function diasParaFim(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->data_fim, false);
    }

    // Está dentro da janela de aviso de renovação?
    public function estaAExpirar(): bool
    {
        $dias = $this->diasParaFim();

        return $this->estado === EstadoContrato::Ativo
            && $dias >= 0
            && $dias <= $this->periodo_aviso_dias;
    }
}
