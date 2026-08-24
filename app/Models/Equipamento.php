<?php

namespace App\Models;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Equipamento / ativo. Pertence a um local (e, por via deste, a um cliente).
class Equipamento extends Model
{
    use RestritoAoCliente, SoftDeletes;

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
        'equipamento_pai_id',
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
        'hash_sync',
        'criado_erp_em', // data de criação no PHC (ma.ousrdata) — ordena os "mais recentes"
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoEquipamento::class,
            'estado' => EstadoEquipamento::class,
            'data_instalacao' => 'date',
            'fim_garantia' => 'date',
            'criado_erp_em' => 'datetime',
            'proxima_troca_baterias' => 'date',
            'atributos' => 'array',
        ];
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    /**
     * URL da ficha pelo MASTAMP do PHC (id_erp) em vez do id interno: /ativos/Mic23091346621,906000001
     * em vez de /ativos/17822 — quem olha para o link vê logo o registo do PHC a que corresponde.
     *
     * Cai para o id interno em dois casos: equipamentos MANUAIS (id_erp null, "não vendidos por
     * nós") e a defesa contra um mastamp com '/' (partiria o segmento do URL e daria 404).
     */
    public function getRouteKey(): mixed
    {
        $mastamp = (string) ($this->id_erp ?? '');

        return $mastamp !== '' && ! str_contains($mastamp, '/') ? $mastamp : $this->getKey();
    }

    /**
     * Resolve /ativos/{equipamento} por mastamp OU por id interno. O id TEM de continuar a
     * funcionar: as etiquetas QR já impressas e coladas nos UPS/geradores levam /ativos/<id>
     * dentro do código e não se reimprimem — trocar a chave sem este fallback cegava-as todas.
     * Também mantém de pé links antigos em emails de alertas e favoritos do browser.
     *
     * Ordem: mastamp primeiro (é o que os links novos geram), id só se o valor for todo dígitos
     * — um mastamp do PHC traz sempre letras e vírgula, por isso não há ambiguidade possível.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $valor = (string) $value;

        return $this->newQuery()->where('id_erp', $valor)->first()
            ?? (ctype_digit($valor) ? $this->newQuery()->whereKey((int) $valor)->first() : null);
    }

    /**
     * ONDE o equipamento está instalado, para mostrar em listas e fichas. O nome do local
     * ("Instalação principal") não diz nada, por isso a cascata é: localização explícita →
     * morada do local → morada da sede do cliente (ERP) → nome do local.
     */
    public function localInstalacao(): string
    {
        $explicito = trim((string) $this->localizacao_instalacao);
        if ($explicito !== '') {
            return $explicito;
        }

        $moradaLocal = trim((string) ($this->local?->morada ?? ''));
        if ($moradaLocal !== '') {
            return $moradaLocal;
        }

        $sede = collect([trim((string) ($this->local?->cliente?->morada ?? '')), trim((string) ($this->local?->cliente?->codpost ?? ''))])
            ->filter(fn ($s) => $s !== '')
            ->implode(' · ');

        return $sede !== '' ? $sede : trim((string) ($this->local?->designacao ?? '—'));
    }

    // Equipamento "pai" — ex.: o UPS a que este banco de baterias está associado.
    public function equipamentoPai(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class, 'equipamento_pai_id');
    }

    // Equipamentos associados a este — ex.: bancos de baterias/kits ligados a um UPS.
    public function equipamentosAssociados(): HasMany
    {
        return $this->hasMany(Equipamento::class, 'equipamento_pai_id');
    }

    public function intervencoes(): HasMany
    {
        return $this->hasMany(Intervencao::class);
    }

    // Alertas de manutenção programados (data + texto editável) — geridos na ficha.
    public function alertasManutencao(): HasMany
    {
        return $this->hasMany(EquipamentoAlertaManutencao::class);
    }

    public function contratos(): BelongsToMany
    {
        return $this->belongsToMany(Contrato::class, 'contrato_equipamentos')->withTimestamps();
    }

    // --- Bancos de baterias (parte do equipamento; um UPS pode ter vários) ------------------
    // Guardados em atributos.bancos como lista [{numero_serie, modelo, capacidade, num_baterias,
    // data_instalacao, proxima_troca}]. Para manter integrações: atributos.num_baterias fica com o
    // TOTAL (a ficha de medição pré-preenche por aí) e a coluna proxima_troca_baterias com a data
    // MAIS PRÓXIMA (os alertas de baterias a vencer disparam por essa).

    /**
     * Bancos no formato do formulário (lista de strings). Converte o formato antigo (um banco
     * em atributos.banco_*) para uma linha — retrocompatibilidade.
     *
     * @return list<array<string, string>>
     */
    public function bancosParaFormulario(): array
    {
        $attrs = $this->atributos ?? [];
        $linha = fn (array $b): array => [
            'numero_serie' => (string) ($b['numero_serie'] ?? ''),
            'modelo' => (string) ($b['modelo'] ?? ''),
            'capacidade' => (string) ($b['capacidade'] ?? ''),
            'num_baterias' => isset($b['num_baterias']) ? (string) $b['num_baterias'] : '',
            'data_instalacao' => (string) ($b['data_instalacao'] ?? ''),
            'proxima_troca' => (string) ($b['proxima_troca'] ?? ''),
        ];

        if (! empty($attrs['bancos'])) {
            return array_map($linha, $attrs['bancos']);
        }

        // Formato antigo (um banco solto em atributos.banco_*) → uma linha.
        if (! empty($attrs['banco_numero_serie']) || ! empty($attrs['banco_modelo']) || ! empty($attrs['banco_capacidade'])) {
            return [[
                'numero_serie' => (string) ($attrs['banco_numero_serie'] ?? ''),
                'modelo' => (string) ($attrs['banco_modelo'] ?? ''),
                'capacidade' => (string) ($attrs['banco_capacidade'] ?? ''),
                'num_baterias' => isset($attrs['num_baterias']) ? (string) $attrs['num_baterias'] : '',
                'data_instalacao' => (string) ($attrs['data_baterias'] ?? ''),
                'proxima_troca' => $this->proxima_troca_baterias?->format('Y-m-d') ?? '',
            ]];
        }

        return [];
    }

    /**
     * Normaliza a lista de bancos do formulário para gravação (ignora linhas vazias).
     * Devolve [bancos_limpos, num_baterias_total, proxima_troca_mais_proxima].
     *
     * @param  array<int, array<string, mixed>>  $bancos
     * @return array{0: list<array<string, mixed>>, 1: ?int, 2: ?string}
     */
    public static function normalizarBancos(array $bancos): array
    {
        $limpos = [];
        $total = 0;
        $temBaterias = false;
        $proximas = [];

        foreach ($bancos as $b) {
            $ns = trim((string) ($b['numero_serie'] ?? ''));
            $modelo = trim((string) ($b['modelo'] ?? ''));
            $cap = trim((string) ($b['capacidade'] ?? ''));
            $nbat = ($b['num_baterias'] ?? '') !== '' ? (int) $b['num_baterias'] : null;
            $dataInst = ($b['data_instalacao'] ?? '') ?: null;
            $proxima = ($b['proxima_troca'] ?? '') ?: null;

            if ($ns === '' && $modelo === '' && $cap === '' && $nbat === null && ! $dataInst && ! $proxima) {
                continue; // linha totalmente vazia
            }

            $limpos[] = array_filter([
                'numero_serie' => $ns ?: null,
                'modelo' => $modelo ?: null,
                'capacidade' => $cap ?: null,
                'num_baterias' => $nbat,
                'data_instalacao' => $dataInst,
                'proxima_troca' => $proxima,
            ], fn ($v) => $v !== null);

            if ($nbat !== null) {
                $total += $nbat;
                $temBaterias = true;
            }
            if ($proxima) {
                $proximas[] = $proxima;
            }
        }

        // min() de datas 'Y-m-d' (string) = a cronologicamente mais próxima.
        return [$limpos, $temBaterias ? $total : null, $proximas ? min($proximas) : null];
    }
}
