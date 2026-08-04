<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

    // Recibos digitalizados do registo.
    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }

    public function total(): float
    {
        return (float) $this->despesas()->sum('valor');
    }

    // Linhas da grelha reconstruídas das despesas: agrupadas por (data, descrição), com os
    // valores nas colunas respetivas e o A/J das refeições. Ordem cronológica.
    /** @return list<array{data: string, descricao: string, valores: array<int, string>, refeicao_tipo: string}> */
    public function linhas(): array
    {
        return $this->despesas()->orderBy('data')->orderBy('id')->get()
            ->groupBy(fn (Despesa $d) => $d->data->toDateString() . '|' . $d->descricao)
            ->map(function ($grupo) {
                $valores = array_fill(0, count(Despesa::CATEGORIAS), '');
                $refeicaoTipo = '';
                foreach ($grupo as $d) {
                    $indice = array_search($d->categoria, Despesa::CATEGORIAS, true);
                    $indice = $indice === false ? count(Despesa::CATEGORIAS) - 1 : $indice;
                    // Duplicados na mesma célula somam (legado); normal é 1 despesa por célula.
                    $atual = $valores[$indice] === '' ? 0 : (float) $valores[$indice];
                    $valores[$indice] = number_format($atual + (float) $d->valor, 2, '.', '');
                    if ($d->refeicao_tipo) {
                        $refeicaoTipo = $d->refeicao_tipo;
                    }
                }

                return [
                    'data' => $grupo->first()->data->toDateString(),
                    'descricao' => $grupo->first()->descricao,
                    'valores' => $valores,
                    'refeicao_tipo' => $refeicaoTipo,
                ];
            })
            ->values()
            ->all();
    }
}
