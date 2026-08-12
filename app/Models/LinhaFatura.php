<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Linha de faturação espelhada do ERP PHC (read-only na app; sincronizada por id_erp =
// fi.fistamp). Só contém linhas com número de série (equipamentos físicos).
class LinhaFatura extends Model
{
    protected $table = 'linhas_fatura';

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'cliente_no',
        'nmdoc',
        'fno',
        'data',
        'ref',
        'design',
        'series',
        'qtt',
        'preco_unitario',
        'desconto',
        'total_linha',
        'total_documento',
        'total_documento_iva',
        'anulada',
        'synced_at',
        'hash_sync',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'qtt' => 'decimal:3',
            'preco_unitario' => 'decimal:3',
            'desconto' => 'decimal:2',
            'total_linha' => 'decimal:2',
            'total_documento' => 'decimal:2',
            'total_documento_iva' => 'decimal:2',
            'anulada' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
