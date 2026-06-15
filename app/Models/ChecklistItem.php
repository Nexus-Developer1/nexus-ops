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
        'descricao',
        'concluido',
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
}
