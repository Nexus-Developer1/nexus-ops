<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Cliente — fonte de verdade no ERP; sincronizado por id_erp (read-only na app).
class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'nome',
        'nif',
        'email',
        'telefone',
        'morada',
        'ativo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function locais(): HasMany
    {
        return $this->hasMany(Local::class);
    }

    public function utilizadores(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }
}
