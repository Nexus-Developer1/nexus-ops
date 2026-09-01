<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Alerta dado como concluído (ver ServicoAlertas::concluir). A chave é o identificador
// estável do alerta calculado; o resto é o instantâneo para o histórico do painel.
class AlertaConcluido extends Model
{
    protected $table = 'alertas_concluidos';

    protected $fillable = ['chave', 'tipo', 'titulo', 'descricao', 'url', 'concluido_por', 'concluido_em'];

    protected function casts(): array
    {
        return ['concluido_em' => 'datetime'];
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }
}
