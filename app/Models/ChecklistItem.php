<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Item de checklist de uma intervenção.
class ChecklistItem extends Model
{
    protected $table = 'checklist_itens';

    /** @var list<string> */
    protected $fillable = [
        'intervencao_id',
        'etapa_id',
        'descricao',
        'concluido',
        'observacao',
        'ordem',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'concluido' => 'boolean',
        ];
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(ChecklistEtapa::class, 'etapa_id');
    }
}
