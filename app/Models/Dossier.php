<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dossiê espelhado do ERP PHC (tabela `bo`) — read-only na app, sincronizado por
// id_erp = bo.bostamp. Tipos: 1 (Encomenda Peças), 3 (Proposta), 7 (Encomenda Produção).
class Dossier extends Model
{
    protected $table = 'dossiers';

    // Rótulos dos tipos de dossiê (ndos) que sincronizamos do PHC.
    public const TIPOS = [
        1 => 'Encomenda Peças',
        3 => 'Proposta',
        7 => 'Encomenda Produção',
    ];

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'ndos',
        'nmdos',
        'obrano',
        'data',
        'ano',
        'cliente_no',
        'nome',
        'total_debito',
        'fechada',
        'u_relat',
        'synced_at',
        'hash_sync',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'total_debito' => 'decimal:2',
            'fechada' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    // Cliente correlacionado por cliente_no = clientes.id_erp (pode não existir na app).
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_no', 'id_erp');
    }

    // Rótulo legível do tipo (fallback ao nome do PHC, depois ao número).
    public function tipoRotulo(): string
    {
        return self::TIPOS[$this->ndos] ?? ($this->nmdos ?: (string) $this->ndos);
    }
}
