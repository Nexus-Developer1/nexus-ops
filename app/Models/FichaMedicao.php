<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ficha de medições de uma UPS numa intervenção. Uma por (intervenção, equipamento).
// Todos os campos são opcionais — preenchem-se durante a visita. As constantes descrevem
// a estrutura do formulário e são partilhadas pelo componente Blade <x-relatorios.ficha-ups>
// e pelo componente Livewire Relatorios\Novo, para não duplicar as listas de campos.
class FichaMedicao extends Model
{
    protected $table = 'fichas_medicao';

    /** @var list<string> */
    protected $fillable = [
        'intervencao_id', 'equipamento_id', 'tipo_equipamento',
        'marca', 'modelo', 'serie', 'baterias',
        'config_tipo', 'bypass_externo', 'modulos', 'bancos_bateria',
        've_ln_l1', 've_ln_l2', 've_ln_l3',
        've_ll_l1l2', 've_ll_l1l3', 've_ll_l2l3',
        'carga_l1', 'carga_l2', 'carga_l3',
        'frequencia',
        'vs_ln_l1', 'vs_ln_l2', 'vs_ln_l3',
        'vs_ll_l1l2', 'vs_ll_l1l3', 'vs_ll_l2l3',
        'is_l1', 'is_l2', 'is_l3',
        'ispico_l1', 'ispico_l2', 'ispico_l3',
        'vbat_pos', 'vbat_neg', 'temperatura',
        'verificacoes', 'teste_descarga', 'baterias_funcionamento',
        'carga_a_funcionar', 'ups_modo_normal', 'notas_finais',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bypass_externo' => 'boolean',
            'modulos' => 'array',
            'bancos_bateria' => 'array',
            'verificacoes' => 'array',
            'teste_descarga' => 'array',
        ];
    }

    // --- Metadados da estrutura (partilhados com a UI) -------------------------------

    /** Colunas elétricas decimais (usadas para limpar vazios → null ao gravar). */
    public const DECIMAIS = [
        've_ln_l1', 've_ln_l2', 've_ln_l3',
        've_ll_l1l2', 've_ll_l1l3', 've_ll_l2l3',
        'carga_l1', 'carga_l2', 'carga_l3',
        'frequencia',
        'vs_ln_l1', 'vs_ln_l2', 'vs_ln_l3',
        'vs_ll_l1l2', 'vs_ll_l1l3', 'vs_ll_l2l3',
        'is_l1', 'is_l2', 'is_l3',
        'ispico_l1', 'ispico_l2', 'ispico_l3',
        'vbat_pos', 'vbat_neg', 'temperatura',
    ];

    /** Campos OK/NOK (guardados como 'ok'|'nok'|null). */
    public const OKNOK = ['baterias_funcionamento', 'carga_a_funcionar', 'ups_modo_normal'];

    /** As 9 verificações (chave => rótulo). */
    public const VERIFICACOES = [
        'acessibilidade' => 'Acessibilidade à manutenção',
        'sala' => 'Condições da sala',
        'ar_condicionado' => 'Ar condicionado',
        'limpeza' => 'Limpeza ao equipamento',
        'ventiladores' => 'Ventiladores',
        'ligacoes' => 'Aperto/estado das ligações',
        'tensao_entrada_saida' => 'Tensão de entrada e saída',
        'simulacao_falha_rede' => 'Simulação de falha de rede',
        'teste_baterias' => 'Teste às baterias',
    ];

    /** Linhas do teste de descarga (chave => rótulo). */
    public const LINHAS_DESCARGA = [
        'inicio' => 'Início', '1' => '1 min', '2' => '2 min', '3' => '3 min',
        '5' => '5 min', '7' => '7 min', '10' => '10 min', '15' => '15 min', '20' => '20 min',
    ];

    /** Colunas do teste de descarga (chave => rótulo). */
    public const COLS_DESCARGA = [
        'vbat_pos' => 'Vbat +', 'vbat_neg' => 'Vbat −', 'aut_pct' => 'Autonomia %', 'aut_min' => 'Autonomia min',
    ];

    /** Nº máximo de linhas nas grelhas de módulos e bancos de bateria. */
    public const MAX_LINHAS = 4;

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    // --- Estado do formulário (array editável em Livewire) ---------------------------

    /**
     * Estrutura vazia da ficha para binding no formulário, opcionalmente pré-preenchida
     * com os dados do equipamento (marca/modelo/série/baterias).
     *
     * @return array<string, mixed>
     */
    public static function estruturaVazia(?Equipamento $equipamento = null): array
    {
        $ficha = [];

        foreach (self::DECIMAIS as $c) {
            $ficha[$c] = '';
        }

        $ficha['marca'] = $equipamento?->fabricante ?? '';
        $ficha['modelo'] = $equipamento?->modelo ?? '';
        $ficha['serie'] = $equipamento?->numero_serie ?? '';
        $ficha['baterias'] = (string) ($equipamento?->atributos['num_baterias'] ?? '');

        $ficha['config_tipo'] = '';
        $ficha['bypass_externo'] = false;

        $ficha['modulos'] = array_fill(0, self::MAX_LINHAS, ['modelo' => '', 'sn' => '']);
        $ficha['bancos_bateria'] = array_fill(0, self::MAX_LINHAS, ['modelo' => '', 'sn' => '']);

        $ficha['verificacoes'] = [];
        foreach (array_keys(self::VERIFICACOES) as $k) {
            $ficha['verificacoes'][$k] = ['estado' => '', 'nota' => ''];
        }

        $ficha['teste_descarga'] = [];
        foreach (array_keys(self::LINHAS_DESCARGA) as $linha) {
            $ficha['teste_descarga'][$linha] = [];
            foreach (array_keys(self::COLS_DESCARGA) as $col) {
                $ficha['teste_descarga'][$linha][$col] = '';
            }
        }

        foreach (self::OKNOK as $c) {
            $ficha[$c] = '';
        }
        $ficha['notas_finais'] = '';

        return $ficha;
    }

    /**
     * Converte um registo persistido para a estrutura do formulário (para edição),
     * garantindo todas as chaves via merge sobre a estrutura vazia.
     *
     * @return array<string, mixed>
     */
    public function paraFormulario(): array
    {
        $base = self::estruturaVazia();

        foreach (self::DECIMAIS as $c) {
            $base[$c] = $this->{$c} === null ? '' : (string) $this->{$c};
        }
        foreach (['marca', 'modelo', 'serie', 'baterias', 'config_tipo', 'notas_finais'] as $c) {
            $base[$c] = (string) ($this->{$c} ?? '');
        }
        foreach (self::OKNOK as $c) {
            $base[$c] = (string) ($this->{$c} ?? '');
        }
        $base['bypass_externo'] = (bool) $this->bypass_externo;

        // Grelhas: preenche as linhas existentes, mantendo MAX_LINHAS slots.
        foreach (['modulos', 'bancos_bateria'] as $grelha) {
            $linhas = $this->{$grelha} ?? [];
            for ($i = 0; $i < self::MAX_LINHAS; $i++) {
                $base[$grelha][$i] = [
                    'modelo' => (string) ($linhas[$i]['modelo'] ?? ''),
                    'sn' => (string) ($linhas[$i]['sn'] ?? ''),
                ];
            }
        }

        foreach (array_keys(self::VERIFICACOES) as $k) {
            $base['verificacoes'][$k] = [
                'estado' => (string) ($this->verificacoes[$k]['estado'] ?? ''),
                'nota' => (string) ($this->verificacoes[$k]['nota'] ?? ''),
            ];
        }

        foreach (array_keys(self::LINHAS_DESCARGA) as $linha) {
            foreach (array_keys(self::COLS_DESCARGA) as $col) {
                $base['teste_descarga'][$linha][$col] = (string) ($this->teste_descarga[$linha][$col] ?? '');
            }
        }

        return $base;
    }

    /**
     * Normaliza a estrutura do formulário em atributos gravaveis: decimais/OK-NOK vazios
     * → null, grelhas sem linhas vazias, verificações e teste de descarga como jsonb.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public static function atributosDeFormulario(array $dados): array
    {
        $limpar = static fn ($v) => (is_string($v) && trim($v) === '') || $v === null ? null : $v;

        $attrs = [];

        foreach (self::DECIMAIS as $c) {
            $attrs[$c] = $limpar($dados[$c] ?? null);
        }
        foreach (['marca', 'modelo', 'serie', 'baterias', 'config_tipo', 'notas_finais'] as $c) {
            $attrs[$c] = $limpar($dados[$c] ?? null);
        }
        foreach (self::OKNOK as $c) {
            $attrs[$c] = $limpar($dados[$c] ?? null);
        }
        $attrs['bypass_externo'] = (bool) ($dados['bypass_externo'] ?? false);

        foreach (['modulos', 'bancos_bateria'] as $grelha) {
            $linhas = array_values(array_filter(
                $dados[$grelha] ?? [],
                static fn ($l) => trim((string) ($l['modelo'] ?? '')) !== '' || trim((string) ($l['sn'] ?? '')) !== ''
            ));
            $attrs[$grelha] = $linhas === [] ? null : $linhas;
        }

        $verificacoes = [];
        foreach (array_keys(self::VERIFICACOES) as $k) {
            $verificacoes[$k] = [
                'estado' => $limpar($dados['verificacoes'][$k]['estado'] ?? null),
                'nota' => $limpar($dados['verificacoes'][$k]['nota'] ?? null),
            ];
        }
        $attrs['verificacoes'] = $verificacoes;

        $descarga = [];
        foreach (array_keys(self::LINHAS_DESCARGA) as $linha) {
            foreach (array_keys(self::COLS_DESCARGA) as $col) {
                $descarga[$linha][$col] = $limpar($dados['teste_descarga'][$linha][$col] ?? null);
            }
        }
        $attrs['teste_descarga'] = $descarga;

        return $attrs;
    }

    /**
     * A ficha tem medições reais? A identificação (marca/modelo/série/baterias) é
     * auto-preenchida a partir do equipamento e NÃO conta — só valores introduzidos pelo
     * técnico (elétricos, configuração, verificações, teste de descarga, OK/NOK, notas).
     *
     * @param  array<string, mixed>  $attrs  saída de atributosDeFormulario()
     */
    public static function temConteudo(array $attrs): bool
    {
        foreach (self::DECIMAIS as $c) {
            if (($attrs[$c] ?? null) !== null) {
                return true;
            }
        }
        foreach (self::OKNOK as $c) {
            if (($attrs[$c] ?? null) !== null) {
                return true;
            }
        }
        if (($attrs['config_tipo'] ?? null) !== null || ($attrs['notas_finais'] ?? null) !== null) {
            return true;
        }
        if (! empty($attrs['bypass_externo']) || ! empty($attrs['modulos']) || ! empty($attrs['bancos_bateria'])) {
            return true;
        }
        foreach (($attrs['verificacoes'] ?? []) as $v) {
            if (($v['estado'] ?? null) !== null || ($v['nota'] ?? null) !== null) {
                return true;
            }
        }
        foreach (($attrs['teste_descarga'] ?? []) as $linha) {
            foreach ($linha as $val) {
                if ($val !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
