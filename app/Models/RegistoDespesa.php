<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// REGISTO de despesas — o documento (folha preenchida de uma vez): cabeçalho + linhas.
// As linhas vivem em `despesas` (uma por célula/categoria — mantém os KPIs); a listagem
// e o PDF tratam o registo como UMA só entrada.
class RegistoDespesa extends Model
{
    use SoftDeletes;

    protected $table = 'registos_despesa';

    /** @var list<string> */
    protected $fillable = ['criado_por', 'matricula', 'departamento'];

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class, 'registo_despesa_id');
    }

    // (A relação anexos() saiu: desde a migração 2026_08_05_000004, os recibos anexam-se
    // sempre às LINHAS — Despesa::anexos() — e nenhum código lê anexos do registo.)

    public function total(): float
    {
        return (float) $this->despesas()->sum('valor');
    }

    // Linhas do registo: 1:1 com as despesas, por ordem cronológica (cada linha = dia,
    // descrição, detalhe, tipo/categoria, valor, A/J e os recibos anexados à própria linha).
    /** @return \Illuminate\Database\Eloquent\Collection<int, Despesa> */
    public function linhasOrdenadas()
    {
        return $this->despesas()->with('anexos')->orderBy('data')->orderBy('id')->get();
    }
}
