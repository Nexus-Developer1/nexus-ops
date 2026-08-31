<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Alerta programado num evento da agenda: data em que deve disparar + texto editável.
// Gerido no modal do evento; consumido pelo ServicoAlertas (painel, dashboard, email diário).
class EventoAlerta extends Model
{
    protected $table = 'evento_alertas';

    /** @var list<string> */
    protected $fillable = ['evento_agenda_id', 'data', 'texto'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoAgenda::class, 'evento_agenda_id');
    }
}
