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
            'anulada' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
