<?php

namespace App\Models;

use App\Enums\PapelUtilizador;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Utilizador da aplicação. Tabela em português (ver plano A / secção 4 do CLAUDE.md).
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'utilizadores';

    /** @var list<string> */
    protected $fillable = [
        'nome',
        'email',
        'password',
        'papel',
        'faz_servicos',
        'cliente_id',
        'ativo',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'agenda_feed_token', // token do feed ICS: é o segredo do URL de subscrição
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

    // Eventos da agenda em que esta conta é técnico ADICIONAL (pivot evento_tecnicos).
    /**
     * Quem entra nas listas de TÉCNICOS: os técnicos e os administradores que também vão
     * a serviços (`faz_servicos`).
     *
     * O papel manda nas permissões; esta é outra pergunta — "pode ser escolhido para um
     * serviço?". Antes eram a mesma coisa, e quem administrava e também trabalhava em
     * campo não aparecia sequer como opção num evento (equipa, set. 2026).
     */
    public function scopeFazServicos(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('papel', PapelUtilizador::Tecnico)
            ->orWhere(fn (Builder $a) => $a->where('papel', PapelUtilizador::Admin)->where('faz_servicos', true)));
    }

    /**
     * Cor desta pessoa na agenda — guardada, para não mudar de um dia para o outro.
     *
     * Atribuída à primeira utilização: a primeira cor da paleta que mais ninguém tenha.
     * Esgotadas as cores (mais de 12 pessoas), reparte-se pela paleta de forma
     * determinística — pelo id, que nunca muda.
     */
    public function corAgenda(): string
    {
        if ($this->cor_agenda) {
            return $this->cor_agenda;
        }

        $usadas = static::whereNotNull('cor_agenda')->where('id', '!=', $this->id)->pluck('cor_agenda')->all();
        $livres = array_values(array_diff(FonteCalendario::PALETA, $usadas));
        $cor = $livres[0] ?? FonteCalendario::PALETA[$this->id % count(FonteCalendario::PALETA)];

        $this->forceFill(['cor_agenda' => $cor])->save();

        return $cor;
    }

    public function eventosAdicionais(): BelongsToMany
    {
        return $this->belongsToMany(EventoAgenda::class, 'evento_tecnicos', 'user_id', 'evento_agenda_id');
    }
}
