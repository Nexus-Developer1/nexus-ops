<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;

// Folha MENSAL de despesas de um colaborador — espelho da folha Excel da empresa:
// cabeçalho (matrícula, departamento, adiantado) + um lançamento por dia/coluna
// (guardado como Despesa ligada por folha_despesa_id).
class FolhaDespesa extends Model
{
    use SoftDeletes;

    protected $table = 'folhas_despesa';

    // As COLUNAS da folha = as categorias fixas de despesa (a ordem é a da folha impressa).
    public const COLUNAS = ['Combustíveis', 'Outros (veículos)', 'Hotel', 'Refeições', 'Táxi / Comboio / Avião', 'Outras despesas'];

    /** @var list<string> */
    protected $fillable = ['user_id', 'ano', 'mes', 'matricula', 'departamento', 'adiantado'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['adiantado' => 'decimal:2'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class, 'folha_despesa_id');
    }

    // Recibos digitalizados (fotos tiradas com o telemóvel) — anexos polimórficos.
    public function anexos(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }

    // "julho 2026" — cabeçalho e listagens.
    public function rotuloMes(): string
    {
        return Carbon::create($this->ano, $this->mes, 1)->translatedFormat('F Y');
    }

    public function diasDoMes(): int
    {
        return Carbon::create($this->ano, $this->mes, 1)->daysInMonth;
    }

    /** Totais por coluna (coluna => soma) sobre as despesas da folha. */
    /** @return array<string, float> */
    public function totaisPorColuna(): array
    {
        $somas = $this->despesas()
            ->selectRaw('categoria, sum(valor) as total')
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        return collect(self::COLUNAS)
            ->mapWithKeys(fn (string $c) => [$c => (float) ($somas[$c] ?? 0)])
            ->all();
    }

    public function total(): float
    {
        return (float) $this->despesas()->sum('valor');
    }

    // Saldo da folha: adiantado > despesas → o colaborador devolve; despesas > adiantado → recebe.
    public function aDevolver(): float
    {
        return max(0, (float) $this->adiantado - $this->total());
    }

    public function aReceber(): float
    {
        return max(0, $this->total() - (float) $this->adiantado);
    }
}
