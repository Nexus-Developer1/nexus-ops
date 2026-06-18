<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Etapa (secção) da checklist de uma intervenção.
class ChecklistEtapa extends Model
{
    protected $table = 'checklist_etapas';

    /** @var list<string> */
    protected $fillable = [
        'intervencao_id',
        'titulo',
        'ordem',
    ];

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'etapa_id')->orderBy('ordem');
    }
}
