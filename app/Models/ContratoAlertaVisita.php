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
    protected $fillable = ['contrato_id', 'data', 'texto'];

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
