<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Cliente — fonte de verdade no ERP; sincronizado por id_erp (read-only na app).
class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'nome',
        'nif',
        'email',
        'telefone',
        'tlmvl',
        'morada',
        'codpost',
        'vendedor',
        'vendnm',
        'ativo',
        'hash_sync',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vendedor' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
