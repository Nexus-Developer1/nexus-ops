<?php

namespace App\Models;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Models\Concerns\RestritoAoCliente;
use App\Models\Concerns\RestritoAoTecnico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Intervenção / ordem de trabalho sobre um equipamento.
class Intervencao extends Model
{
    use HasFactory, RestritoAoCliente, RestritoAoTecnico, SoftDeletes;

    protected $table = 'intervencoes';

    // Isolamento por cliente (via equipamento → local).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->whereHas('equipamento.local', fn ($q) => $q->where('cliente_id', $clienteId));
    }

    // Isolamento por técnico (as suas intervenções).
    protected static function restringirAoTecnico(Builder $query, int $tecnicoId): void
    {
        $query->where('tecnico_id', $tecnicoId);
    }

    /** @var list<string> */
    protected $fillable = [
        'equipamento_id',
        'tecnico_id',
        'contrato_id',
        'evento_agenda_id',
        'tipo',
        'estado',
        'data_inicio',
        'data_fim',
        'hora_inicio',
        'hora_fim',
        'descricao_problema',
        'trabalho_realizado',
        'observacoes',
        'diagnostico',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoIntervencao::class,
            'estado' => EstadoIntervencao::class,
            'data_inicio' => 'datetime',
            'data_fim' => 'datetime',
            'diagnostico' => 'array',
        ];
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    // Equipamentos ADICIONAIS cobertos por este relatório (além do principal).
    public function equipamentosCobertos(): BelongsToMany
    {
        return $this->belongsToMany(Equipamento::class, 'intervencao_equipamentos');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function relatorio(): HasOne
    {
        return $this->hasOne(Relatorio::class);
    }

    public function checklistItens(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('ordem');
    }

    // Etapas (secções) da checklist, ordenadas; cada uma com os seus itens.
    public function checklistEtapas(): HasMany
    {
        return $this->hasMany(ChecklistEtapa::class)->orderBy('ordem');
    }

    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }

    // Evento de agenda que originou esta intervenção (fonte única — CLAUDE.md §6).
    public function eventoAgenda(): BelongsTo
    {
        return $this->belongsTo(EventoAgenda::class, 'evento_agenda_id');
    }
}
