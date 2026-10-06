<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Página do caderno do cliente ("Equipamento 1", "Dados CCTV"…). O conteúdo é HTML já limpo
// (LimpezaHtmlCaderno); as imagens são anexos desta página, servidos por /anexos/{id}.
class CadernoPagina extends Model
{
    use SoftDeletes;

    protected $table = 'caderno_paginas';

    /** @var list<string> */
    protected $fillable = ['separador_id', 'titulo', 'conteudo', 'versao', 'ordem', 'criado_por', 'atualizado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['versao' => 'integer'];
    }

    public function separador(): BelongsTo
    {
        return $this->belongsTo(CadernoSeparador::class, 'separador_id');
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
