<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Código de verificação em duas etapas (MFA por email). Uso único, validade curta.
// Guarda apenas o hash do código (codigo_hash), nunca o valor em claro.
class CodigoMfa extends Model
{
    protected $table = 'codigos_mfa';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'codigo_hash',
        'expira_em',
        'tentativas',
        'usado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'usado_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Códigos ainda utilizáveis: por usar e dentro da validade.
    public function scopeVivo(Builder $query): Builder
    {
        return $query->whereNull('usado_em')->where('expira_em', '>', now());
    }
}
