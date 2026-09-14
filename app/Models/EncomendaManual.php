<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Encomenda de peças escrita à mão no relatório, ainda por chegar do PHC (nº + ano). Vive até
// o dossiê correspondente ser sincronizado — aí passa a ligação normal e desaparece daqui.
class EncomendaManual extends Model
{
    protected $table = 'intervencao_encomendas_manuais';

    /** @var list<string> */
    protected $fillable = ['intervencao_id', 'obrano', 'ano', 'criado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['obrano' => 'integer', 'ano' => 'integer'];
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }
}
