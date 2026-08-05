<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Despesa da operação — área de gestão (admin). Liga-se opcionalmente a cliente,
// equipamento, intervenção e contrato; faturavel distingue "à parte" de "incluído".
class Despesa extends Model
{
    use SoftDeletes;

    protected $table = 'despesas';

    // Categorias FIXAS (as da folha de despesas da empresa) — whitelist no editor.
    public const CATEGORIAS = ['Combustíveis', 'Outros (veículos)', 'Hotel', 'Refeições', 'Táxi / Comboio / Avião', 'Outras despesas'];

    /** @var list<string> */
    protected $fillable = [
        'data',
        'categoria',
        'descricao',
        'valor',
        'faturavel',
        'cliente_id',
        'equipamento_id',
        'intervencao_id',
        'contrato_id',
        'criado_por',
        'registo_despesa_id', // documento (registo) a que esta linha pertence
        'detalhe', // "o que realmente é" (ex.: Portagem A1, Almoço com cliente)
        'refeicao_tipo', // 'A' (almoço) | 'J' (jantar) — só nas despesas de Refeições (nota a) da folha)
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'valor' => 'decimal:2',
            'faturavel' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    // Registo (documento) a que esta linha pertence.
    public function registo(): BelongsTo
    {
        return $this->belongsTo(RegistoDespesa::class, 'registo_despesa_id');
    }

    // Recibos digitalizados DESTA linha (anexos polimórficos).
    public function anexos(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }
}
