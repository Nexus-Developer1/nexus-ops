<?php

namespace App\Models;

use App\Enums\PapelUtilizador;
use Database\Factories\UserFactory;
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
        ];
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

    public function ehTecnico(): bool
    {
        return $this->papel === PapelUtilizador::Tecnico;
    }

    public function ehCliente(): bool
    {
        return $this->papel === PapelUtilizador::Cliente;
    }

    // Rota inicial de cada papel (CLAUDE.md §7).
    public function rotaInicial(): string
    {
        return match (true) {
            $this->ehCliente() => 'portal.dashboard',
            $this->ehTecnico() => 'painel',
            default => 'dashboard',
        };
    }
}
