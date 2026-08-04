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
        'folha_despesa_id', // folha mensal do colaborador a que o lançamento pertence (null = avulsa)
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

    public function folha(): BelongsTo
    {
        return $this->belongsTo(FolhaDespesa::class, 'folha_despesa_id');
    }
}
