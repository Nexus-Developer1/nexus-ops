<?php

namespace App\Models;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Equipamento / ativo. Pertence a um local (e, por via deste, a um cliente).
class Equipamento extends Model
{
    use HasFactory, RestritoAoCliente, SoftDeletes;

    protected $table = 'equipamentos';

    // Isolamento por cliente (via local).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->whereHas('local', fn ($q) => $q->where('cliente_id', $clienteId));
    }

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'local_id',
        'tipo',
        'fabricante',
        'modelo',
        'familia',
        'faminome',
        'numero_serie',
        'cliente_final',
        'localizacao_instalacao',
        'data_instalacao',
        'fim_garantia',
        'estado',
        'notas',
        'proxima_troca_baterias',
        'atributos',
        'qr_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoEquipamento::class,
            'estado' => EstadoEquipamento::class,
            'data_instalacao' => 'date',
            'fim_garantia' => 'date',
            'proxima_troca_baterias' => 'date',
            'atributos' => 'array',
        ];
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function intervencoes(): HasMany
    {
        return $this->hasMany(Intervencao::class);
    }

    public function contratos(): BelongsToMany
    {
        return $this->belongsToMany(Contrato::class, 'contrato_equipamentos')->withTimestamps();
    }

    // Cliente a que o equipamento pertence (via local).
    public function cliente(): ?Cliente
    {
        return $this->local?->cliente;
    }
}
