<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Alerta de visita programado num contrato: data em que deve disparar + texto editável.
// Gerido na edição do contrato; consumido pelo ServicoAlertas (painel, dashboard, email diário).
class ContratoAlertaVisita extends Model
{
    protected $table = 'contrato_alertas_visita';

    /** @var list<string> */
    // user_id: a quem o alerta está atribuído (null = equipa completa).
    protected $fillable = ['contrato_id', 'data', 'texto', 'user_id'];

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
