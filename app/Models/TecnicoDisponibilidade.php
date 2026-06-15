<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ausência / férias de um técnico — usada na deteção de conflitos da agenda (CLAUDE.md §4).
class TecnicoDisponibilidade extends Model
{
    protected $table = 'tecnico_disponibilidade';

    /** @var list<string> */
    protected $fillable = [
        'tecnico_id',
        'tipo',
        'inicio',
        'fim',
        'motivo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fim' => 'datetime',
        ];
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }
}
