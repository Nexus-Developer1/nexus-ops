<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Separador do caderno do cliente (normalmente um por cliente final) — ver a migração.
class CadernoSeparador extends Model
{
    use SoftDeletes;

    protected $table = 'caderno_separadores';

    // Cores dos separadores (como no OneNote): chave => [fundo, texto].
    public const CORES = [
        'verde' => ['#dcfce7', '#166534'],
        'azul' => ['#dbeafe', '#1e40af'],
        'roxo' => ['#ede9fe', '#5b21b6'],
        'amarelo' => ['#fef3c7', '#92400e'],
        'laranja' => ['#ffedd5', '#9a3412'],
        'rosa' => ['#fce7f3', '#9d174d'],
        'cinzento' => ['#f3f4f6', '#374151'],
    ];

    /** @var list<string> */
    protected $fillable = ['cliente_id', 'nome', 'cor', 'ordem', 'criado_por'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function paginas(): HasMany
    {
        return $this->hasMany(CadernoPagina::class, 'separador_id')->orderBy('ordem')->orderBy('id');
    }

    /** @return array{0: string, 1: string} */
    public function cores(): array
    {
        return self::CORES[$this->cor] ?? self::CORES['verde'];
    }
}
