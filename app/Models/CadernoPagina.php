<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Página do caderno do cliente ("Equipamento 1", "Dados CCTV"…). O conteúdo é HTML já limpo
// (LimpezaHtmlCaderno); as imagens e os ficheiros (PDF, manuais…) são anexos desta página,
// servidos por /anexos/{id}. Pode ser SUBPÁGINA de outra do mesmo separador (um só nível).
class CadernoPagina extends Model
{
    use SoftDeletes;

    protected $table = 'caderno_paginas';

    /** @var list<string> */
    protected $fillable = ['separador_id', 'pai_id', 'equipamento_id', 'titulo', 'conteudo', 'versao', 'ordem', 'criado_por', 'atualizado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['versao' => 'integer', 'ordem' => 'integer', 'pai_id' => 'integer'];
    }

    public function separador(): BelongsTo
    {
        return $this->belongsTo(CadernoSeparador::class, 'separador_id');
    }

    // Página de cima, quando esta é uma subpágina.
    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    public function subpaginas(): HasMany
    {
        return $this->hasMany(self::class, 'pai_id')->orderBy('ordem')->orderBy('id');
    }

    // Equipamento de que a página trata (opcional) — aparece também na ficha do equipamento.
    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel');
    }

    public function autorAlteracao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }
}
