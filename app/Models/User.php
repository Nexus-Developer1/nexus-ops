<?php

namespace App\Models;

use App\Enums\PapelUtilizador;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Utilizador da aplicação. Tabela em português (ver plano A / secção 4 do CLAUDE.md).
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'utilizadores';

    /** @var list<string> */
    protected $fillable = [
        'nome',
        'email',
        'password',
        'papel',
        'cliente_id',
        'ativo',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'papel' => PapelUtilizador::class,
            'ativo' => 'boolean',
            'password_alterada_em' => 'datetime', // invalidação de sessões antigas (Vaga 1)
        ];
    }

    // Storage: o email é sempre guardado em minúsculas (emails são case-insensitive). Garante
    // que nenhuma conta nasce com capitalização diferente, venha de onde vier (create/update,
    // convite, seeder, factory). A comparação no login/reset normaliza o INPUT à parte.
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $valor) => $valor === null ? null : strtolower(trim($valor)),
        );
    }

    // Só preenchido para utilizadores do portal de cliente.
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ehAdmin(): bool
    {
        return $this->papel === PapelUtilizador::Admin;
    }

    public function ehCliente(): bool
    {
        return $this->papel === PapelUtilizador::Cliente;
    }

    // Rota inicial de cada papel. Técnico = admin (aterra no dashboard); só o cliente vai
    // para o portal.
    public function rotaInicial(): string
    {
        return $this->ehCliente() ? 'portal.dashboard' : 'dashboard';
    }
}
