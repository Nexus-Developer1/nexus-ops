<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Alerta de manutenção programado num equipamento: data em que deve disparar + texto editável.
// Gerido na ficha do equipamento; consumido pelo ServicoAlertas (painel, dashboard, email diário).
class EquipamentoAlertaManutencao extends Model
{
    protected $table = 'equipamento_alertas_manutencao';

    /** @var list<string> */
    // user_id: a quem o alerta está atribuído (null = equipa completa).
    protected $fillable = ['equipamento_id', 'data', 'texto', 'user_id'];

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }
}
