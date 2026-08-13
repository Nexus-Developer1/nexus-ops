<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Despesa (linha de um RegistoDespesa) — área de gestão. As colunas de ligação a
// equipamento/intervenção/contrato mantêm o histórico na BD, mas deixaram de se editar
// e mostrar (saíram do formulário a pedido da equipa) — as relações órfãs foram removidas;
// cliente_id continua vivo (filtros da listagem); faturavel ficou só como histórico na BD
// (os KPIs e o filtro de faturável saíram da listagem — o editor grava sempre false).
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

    // Registo (documento) a que esta linha pertence.
    public function registo(): BelongsTo
    {
        return $this->belongsTo(RegistoDespesa::class, 'registo_despesa_id');
    }

    // Recibos digitalizados DESTA linha (anexos polimórficos).
    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }
}
