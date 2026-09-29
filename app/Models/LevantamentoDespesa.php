<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// Levantamento de dinheiro do cartão do técnico, dentro de um registo de despesas. O talão do
// multibanco é o anexo (obrigatório). O que se gastou desse dinheiro são as linhas do registo
// com «Pago por» = Dinheiro levantado — ver RegistoDespesa::contasDoDinheiro().
class LevantamentoDespesa extends Model
{
    protected $table = 'levantamentos_despesa';

    /** @var list<string> */
    protected $fillable = ['registo_despesa_id', 'data', 'valor'];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'valor' => 'decimal:2',
        ];
    }

    public function registo(): BelongsTo
    {
        return $this->belongsTo(RegistoDespesa::class, 'registo_despesa_id');
    }

    // O talão do multibanco (fotografia).
    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }
}
