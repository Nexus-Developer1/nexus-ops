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
        'descarga_curva', // curva do ficheiro do teste de descarga (battest.txt)
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
        'recomendacao', 'prioridade',
        'sadei',
        'assinatura_cliente_key', 'assinatura_cliente_nome',
        'assinatura_tecnico_key', 'assinatura_tecnico_nome', 'assinado_em',
    ];

    /** Tamanho máximo do PNG de assinatura (data URI decodificado). */
    public const ASSINATURA_MAX_BYTES = 300 * 1024;

    /** Limites de dimensão do PNG de assinatura (anti bomba de descompressão). */
    public const ASSINATURA_MAX_LADO = 4000;

    public const ASSINATURA_MAX_PIXEIS = 4_000_000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bypass_externo' => 'boolean',
            'modulos' => 'array',
            'bancos_bateria' => 'array',
            'verificacoes' => 'array',
            'teste_descarga' => 'array',
            'descarga_curva' => 'array',
            'sadei' => 'array',
            'assinado_em' => 'datetime',
        ];
    }

    /**
     * Valida e decodifica um data URI de assinatura (PNG desenhado no ecrã). Devolve os bytes
     * do PNG ou null se não for um PNG válido/dentro do tamanho — nunca se confia na string
     * que vem do browser (é prop pública) nem se guarda o data URI em cru.
     */
    public static function pngDeAssinatura(?string $dataUri): ?string
    {
        if (! is_string($dataUri) || ! str_starts_with($dataUri, 'data:image/png;base64,')) {
            return null;
        }

        // Tamanho verificado ANTES de descodificar: com post_max_size de 60M, um data URI
        // gigante fazia o servidor gastar centenas de MB só para depois rejeitar.
        if (strlen($dataUri) > self::ASSINATURA_MAX_BYTES * 2) {
            return null;
        }

        $bytes = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::ASSINATURA_MAX_BYTES) {
            return null;
        }

        // Confirma que é mesmo um PNG (e não outro formato/lixo com o prefixo certo).
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[2] !== IMAGETYPE_PNG) {
            return null;
        }

        // DIMENSÕES: 300 KB de PNG chegam para 20000×20000 px (uniforme comprime >1000:1) —
        // uma "bomba de descompressão" que rebentava a memória/CPU do worker ao gerar o PDF
        // (o DomPDF descodifica a imagem toda). Uma assinatura real cabe folgadamente aqui.
        // 14.ª revisão de segurança.
        if ($info[0] > self::ASSINATURA_MAX_LADO || $info[1] > self::ASSINATURA_MAX_LADO
            || ($info[0] * $info[1]) > self::ASSINATURA_MAX_PIXEIS) {
            return null;
        }

        return $bytes;
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

    // --- Ficha de Verificações SADEI (equipamentos tipo "incendio") ------------------
    // Espelho da folha oficial Nexus 2024 (deteção/extinção de incêndio). Cada secção é
    // chave => rótulo; o estado é ''|'ok'|'ko' (central, cilindros, final) ou
    // ''|'ok'|'ko'|'na' (restantes). Partilhadas pelo formulário e pelo PDF.

    /** Central de deteção e extinção (OK/KO + nota). */
    public const SADEI_CENTRAL = [
        'limpeza' => 'Limpeza',
        'bezouro' => 'Bezouro interno',
        'baterias' => 'Baterias',
        'botoes' => 'Botões',
        'leds' => 'Leds',
        'ligacoes' => 'Verificação das ligações',
        'botoneira_ativacao' => 'Botoneira ativação',
        'botoneira_inibicao' => 'Botoneira inibição',
    ];

    /** Sistema de deteção (OK/KO/N\A + nota). */
    public const SADEI_DETECAO = [
        'aspiracao' => 'Aspiração',
        'detecao' => 'Detecção',
    ];

    /** Sistema de aspiração (OK/KO/N\A + nota). */
    public const SADEI_ASPIRACAO = [
        'sem_avarias' => 'Equipamento sem avarias',
        'sem_alarmes' => 'Equipamento sem alarmes',
        'rele_zona1' => 'Relé zona 1',
        'rele_zona2' => 'Relé zona 2',
        'diagnostico_software' => 'Diagnóstico por software',
        'pontos_aspiracao' => 'Pontos de aspiração',
        'filtro' => 'Filtro',
        'ligacoes' => 'Ligações',
    ];

    /** Sistema por sensores (OK/KO/N\A + nota). */
    public const SADEI_SENSORES = [
        'opticos_fumo' => 'Óticos fumo',
        'termo_velocimetricos' => 'Termo-velocimétricos',
        'multicriterio' => 'Multicritério',
        'outro' => 'Outro',
    ];

    /** Verificação trimestral (OK/KO/N\A; inibir o sistema antes de iniciar). */
    public const SADEI_TRIMESTRAL = [
        'acessos' => 'Acesso livre e sem obstruções às áreas de risco, botoneiras, comandos manuais e cilindros difusores',
        'inspecao_cilindros' => 'Inspeção geral a todos os cilindros, com eventual reaperto das mangueiras (disparo e pilotagem)',
        'pressao_cilindros' => 'Pressão interna dos cilindros',
        'pressao_piloto' => 'Pressão do cilindro piloto (ou sparklet) de N2, caso exista',
        'livro_registos' => 'Entradas no livro de registos de ocorrências verificadas e ações necessárias tomadas',
        'mudancas_estruturais' => 'Mudanças estruturais/ocupacionais que possam ter afetado a localização de sensores e difusores',
    ];

    /** Verificação semestral (OK/KO/N\A; inibir o sistema antes de iniciar). */
    public const SADEI_SEMESTRAL = [
        'rotinas_trimestrais' => 'Inspeção e rotinas de testes da verificação trimestral executadas',
        'teste_sensores' => 'Operado ≥1 sensor em locais distintos (central recebe/exibe o sinal, soa o alarme e aciona avisos, com o disparo bloqueado)',
        'monitorizacao_anomalias' => 'Funções de monitorização de anomalias da central de extinção',
        'comando_distancia' => 'Central opera comandos à distância (simulação da ordem de extinção)',
        'fixacao' => 'Fixação correta de tubagens, cilindros e todos os cabos',
        'estado_tubagem' => 'Estado geral da tubagem e difusores sem alterações face ao projeto inicial',
        'local_limpo' => 'Local de armazenamento limpo e desobstruído (acesso a manómetros, válvulas, cilindros)',
        'pintura' => 'Estado da pintura dos cilindros e tubagem',
        'acesso_atuacao_manual' => 'Fácil acessibilidade aos sistemas de atuação manual',
        'selos' => 'Estado dos selos de segurança nos comandos manuais',
        'instrucoes' => 'Instruções para atuação manual existentes, legíveis e resistentes',
        'linha_pilotagem' => 'Linha de pilotagem pneumática protegida de danos mecânicos, caso exista',
        'mangueiras' => 'Mangueiras sem tensão',
        'valvulas_antirretorno' => 'Válvulas anti-retorno com a direção de fluxo correta (pilotagem e descarga)',
        'restritores' => 'Restritores corretos no coletor de descarga (~60 bar — só gases inertes)',
        'pesagem' => 'Sistema de pesagem indica "carga correta" e testado manualmente, caso exista',
        'continuidade_eletrica' => 'Continuidade no sistema elétrico de alimentação',
        'sensor_fluxo' => 'Funcionamento do sensor de fluxo, caso exista',
    ];

    /** Verificação anual (OK/KO/N\A; inibir o sistema antes de iniciar). */
    public const SADEI_ANUAL = [
        'rotinas_anteriores' => 'Inspeção e rotinas de testes trimestrais e semestrais executadas',
        'teste_cada_sensor' => 'Funcionamento de cada sensor e comando manual (recomendações do fabricante)',
        'inspecao_visual' => 'Inspeção visual: mudanças estruturais/ocupacionais; espaço desimpedido à volta de cada sensor/difusor e acesso ao comando manual',
        'baterias' => 'Baterias examinadas e testadas (substituídas nos intervalos do fabricante)',
        'valvulas_direcionais' => 'Válvulas direcionais (caso existam)',
        'vd_abertura_manual' => '— abertura e fecho manual realizados',
        'vd_ligacoes' => '— ligações nos comandos elétricos e manuais',
        'vd_abertura_pilotagem' => '— abertura com pressão na linha de pilotagem de disparo',
        'vd_fecho_pos_teste' => '— em posição fechada após os testes',
        'pesagem_co2' => 'Sistemas de CO2 sem pesagem automática: pesagem manual efetuada',
    ];

    /** Linhas INICIAIS das grelhas de cilindros (agente extintor) e piloto — o técnico pode
     *  acrescentar conforme a quantidade instalada no cliente, até SADEI_MAX_LINHAS_GRELHA. */
    public const SADEI_CILINDROS_LINHAS = 4;

    public const SADEI_PILOTO_LINHAS = 2;

    public const SADEI_MAX_LINHAS_GRELHA = 50;

    /** Colunas das grelhas de cilindros/piloto (chave => rótulo). */
    public const SADEI_COLS_CILINDRO = [
        'identificacao' => 'Identificação', 'pressao' => 'Pressão', 'data_carga' => 'Data carga',
        'qt_agente' => 'QT agente', 'peso_total' => 'Peso total',
    ];

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

        // Curva do teste importada do ficheiro do carregador (preferida pelo gráfico).
        $ficha['descarga_curva'] = [];

        foreach (self::OKNOK as $c) {
            $ficha[$c] = '';
        }
        $ficha['notas_finais'] = '';

        // Recomendações e próximos passos — por equipamento (prioridade Baixa/Normal/Alta).
        $ficha['recomendacao'] = '';
        $ficha['prioridade'] = 'Normal';

        // Bloco SADEI (só é mostrado/preenchido em equipamentos tipo "incendio"; nos
        // restantes fica vazio e grava null). Estar sempre presente simplifica o binding.
        $ficha['sadei'] = self::sadeiVazia();

        // Assinaturas (SADEI): data URI desenhado no ecrã + nome de quem assina. O data URI
        // só vive no formulário — ao gravar vira ficheiro no storage (ver Novo::persistirFichas).
        $ficha['assinatura_cliente'] = '';
        $ficha['assinatura_cliente_nome'] = '';
        $ficha['assinatura_tecnico'] = '';
        $ficha['assinatura_tecnico_nome'] = '';

        return $ficha;
    }

    /**
     * Estrutura vazia do bloco SADEI (formulário) — espelho da folha oficial.
     *
     * @return array<string, mixed>
     */
    private static function sadeiVazia(): array
    {
        $comNota = static fn (array $itens) => array_map(static fn () => ['estado' => '', 'nota' => ''], $itens);
        $soEstado = static fn (array $itens) => array_map(static fn () => ['estado' => ''], $itens);
        $linhaCilindro = array_fill_keys(array_keys(self::SADEI_COLS_CILINDRO), '') + ['estado' => ''];

        return [
            'tipo_manutencao' => '',                       // trimestral|semestral|anual
            'central' => $comNota(self::SADEI_CENTRAL),
            'detecao' => $comNota(self::SADEI_DETECAO),
            'aspiracao' => $comNota(self::SADEI_ASPIRACAO),
            'sensores' => $comNota(self::SADEI_SENSORES),
            'num_sensores' => '',
            'trimestral' => $soEstado(self::SADEI_TRIMESTRAL),
            'semestral' => $soEstado(self::SADEI_SEMESTRAL),
            'anual' => $soEstado(self::SADEI_ANUAL),
            'tipo_agente' => '',
            'cilindros' => array_fill(0, self::SADEI_CILINDROS_LINHAS, $linhaCilindro),
            'tipo_piloto' => '',
            'piloto' => array_fill(0, self::SADEI_PILOTO_LINHAS, $linhaCilindro),
            'final_automatico' => '',                      // ok|ko — "em automático, selenoide colocada, a funcionar"
        ];
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
        foreach (['marca', 'modelo', 'serie', 'baterias', 'config_tipo', 'notas_finais', 'recomendacao'] as $c) {
            $base[$c] = (string) ($this->{$c} ?? '');
        }
        foreach (self::OKNOK as $c) {
            $base[$c] = (string) ($this->{$c} ?? '');
        }
        $base['prioridade'] = ($this->prioridade ?: null) ?? 'Normal';
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

        $base['descarga_curva'] = $this->descarga_curva ?? [];

        // Assinaturas: só os nomes voltam ao formulário; a imagem já gravada é mostrada a
        // partir do storage (não se reenvia o PNG para o browser a cada render).
        $base['assinatura_cliente_nome'] = (string) ($this->assinatura_cliente_nome ?? '');
        $base['assinatura_tecnico_nome'] = (string) ($this->assinatura_tecnico_nome ?? '');

        // SADEI: preenche o esqueleto com o que estiver gravado (strings para o binding).
        $g = $this->sadei ?? [];
        foreach (['tipo_manutencao', 'num_sensores', 'tipo_agente', 'tipo_piloto', 'final_automatico'] as $c) {
            $base['sadei'][$c] = (string) ($g[$c] ?? '');
        }
        foreach (['central' => self::SADEI_CENTRAL, 'detecao' => self::SADEI_DETECAO, 'aspiracao' => self::SADEI_ASPIRACAO, 'sensores' => self::SADEI_SENSORES] as $sec => $itens) {
            foreach (array_keys($itens) as $k) {
                $base['sadei'][$sec][$k] = [
                    'estado' => (string) ($g[$sec][$k]['estado'] ?? ''),
                    'nota' => (string) ($g[$sec][$k]['nota'] ?? ''),
                ];
            }
        }
        foreach (['trimestral' => self::SADEI_TRIMESTRAL, 'semestral' => self::SADEI_SEMESTRAL, 'anual' => self::SADEI_ANUAL] as $sec => $itens) {
            foreach (array_keys($itens) as $k) {
                $base['sadei'][$sec][$k] = ['estado' => (string) ($g[$sec][$k]['estado'] ?? '')];
            }
        }
        foreach (['cilindros' => self::SADEI_CILINDROS_LINHAS, 'piloto' => self::SADEI_PILOTO_LINHAS] as $grelha => $n) {
            // Grelhas dinâmicas: mostra TODAS as linhas gravadas (no mínimo as iniciais), até ao teto.
            $gravadas = is_array($g[$grelha] ?? null) ? count($g[$grelha]) : 0;
            $total = min(self::SADEI_MAX_LINHAS_GRELHA, max($n, $gravadas));
            for ($i = 0; $i < $total; $i++) {
                foreach ([...array_keys(self::SADEI_COLS_CILINDRO), 'estado'] as $col) {
                    $base['sadei'][$grelha][$i][$col] = (string) ($g[$grelha][$i][$col] ?? '');
                }
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
        // Só escalares (payload forjado com arrays rebentava com "Array to string"); strings
        // truncadas a 2000 chars (sem limite, notas forjadas de MBs inchavam a BD e o PDF).
        $limpar = static function ($v) {
            if ($v === null || ! is_scalar($v)) {
                return null;
            }
            $v = is_string($v) ? trim($v) : $v;

            return $v === '' ? null : (is_string($v) ? mb_substr($v, 0, 2000) : $v);
        };

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

        // Recomendação: a prioridade só faz sentido quando há recomendação preenchida.
        $attrs['recomendacao'] = $limpar($dados['recomendacao'] ?? null);
        $attrs['prioridade'] = $attrs['recomendacao'] !== null ? ($dados['prioridade'] ?: 'Normal') : null;

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

        // Curva importada do ficheiro: whitelist {t, p, n}, teto de pontos (payload forjado
        // não incha a BD) — vazia grava null.
        $curva = [];
        foreach (array_slice(array_values(is_array($dados['descarga_curva'] ?? null) ? $dados['descarga_curva'] : []), 0, 1200) as $a) {
            if (! is_array($a) || ! is_numeric($a['p'] ?? null) || ! is_numeric($a['n'] ?? null)) {
                continue;
            }
            $curva[] = ['t' => mb_substr(trim((string) ($a['t'] ?? '')), 0, 12), 'p' => (float) $a['p'], 'n' => (float) $a['n']];
        }
        $attrs['descarga_curva'] = $curva === [] ? null : $curva;

        // Nomes de quem assina (a imagem é gravada no storage pelo componente).
        $attrs['assinatura_cliente_nome'] = $limpar($dados['assinatura_cliente_nome'] ?? null);
        $attrs['assinatura_tecnico_nome'] = $limpar($dados['assinatura_tecnico_nome'] ?? null);

        // SADEI: normaliza contra o esqueleto (só chaves conhecidas; estados whitelisted);
        // se nada estiver preenchido, grava null — as fichas UPS ficam sempre a null.
        // is_array: um payload forjado com sadei="x" (string) rebentava com TypeError.
        $attrs['sadei'] = is_array($dados['sadei'] ?? null) ? self::sadeiAtributos($dados['sadei']) : null;

        return $attrs;
    }

    /**
     * Normaliza o bloco SADEI do formulário: whitelist de chaves e estados, vazios → null,
     * grelhas sem linhas vazias. Devolve null quando não há conteúdo nenhum.
     *
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>|null
     */
    private static function sadeiAtributos(array $g): ?array
    {
        // Só escalares + truncagem (ver o $limpar de atributosDeFormulario — mesmas razões).
        $limpar = static fn ($v) => $v === null || ! is_scalar($v) || trim((string) $v) === ''
            ? null
            : mb_substr(trim((string) $v), 0, 2000);
        $estado = static fn ($v, array $validos) => in_array($v, $validos, true) ? $v : null;
        // Filtro estrito (só descarta null): array_filter por omissão descartava valores "0".
        $temAlgum = static fn (array $a) => array_filter($a, static fn ($v) => $v !== null) !== [];

        $out = [
            'tipo_manutencao' => $estado($g['tipo_manutencao'] ?? null, ['trimestral', 'semestral', 'anual']),
            'num_sensores' => $limpar($g['num_sensores'] ?? null),
            'tipo_agente' => $limpar($g['tipo_agente'] ?? null),
            'tipo_piloto' => $limpar($g['tipo_piloto'] ?? null),
            'final_automatico' => $estado($g['final_automatico'] ?? null, ['ok', 'ko']),
        ];
        $tem = $temAlgum($out);

        foreach (['central' => [self::SADEI_CENTRAL, ['ok', 'ko']], 'detecao' => [self::SADEI_DETECAO, ['ok', 'ko', 'na']], 'aspiracao' => [self::SADEI_ASPIRACAO, ['ok', 'ko', 'na']], 'sensores' => [self::SADEI_SENSORES, ['ok', 'ko', 'na']]] as $sec => [$itens, $validos]) {
            foreach (array_keys($itens) as $k) {
                $out[$sec][$k] = [
                    'estado' => $estado($g[$sec][$k]['estado'] ?? null, $validos),
                    'nota' => $limpar($g[$sec][$k]['nota'] ?? null),
                ];
                $tem = $tem || $out[$sec][$k]['estado'] !== null || $out[$sec][$k]['nota'] !== null;
            }
        }
        foreach (['trimestral' => self::SADEI_TRIMESTRAL, 'semestral' => self::SADEI_SEMESTRAL, 'anual' => self::SADEI_ANUAL] as $sec => $itens) {
            foreach (array_keys($itens) as $k) {
                $out[$sec][$k] = ['estado' => $estado($g[$sec][$k]['estado'] ?? null, ['ok', 'ko', 'na'])];
                $tem = $tem || $out[$sec][$k]['estado'] !== null;
            }
        }
        foreach (['cilindros', 'piloto'] as $grelha) {
            $linhas = [];
            // Grelhas dinâmicas: aceita as linhas que vierem (teto contra payloads forjados).
            $entrada = array_slice(array_values(is_array($g[$grelha] ?? null) ? $g[$grelha] : []), 0, self::SADEI_MAX_LINHAS_GRELHA);
            foreach ($entrada as $linhaIn) {
                $linhaIn = is_array($linhaIn) ? $linhaIn : [];
                $linha = [];
                foreach (array_keys(self::SADEI_COLS_CILINDRO) as $col) {
                    $linha[$col] = $limpar($linhaIn[$col] ?? null);
                }
                $linha['estado'] = $estado($linhaIn['estado'] ?? null, ['ok', 'ko']);
                if ($temAlgum($linha)) {
                    $linhas[] = $linha;
                }
            }
            $out[$grelha] = $linhas;
            $tem = $tem || $linhas !== [];
        }

        return $tem ? $out : null;
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
        // Uma recomendação (mesmo sem medições) é conteúdo — a ficha deve persistir para a guardar.
        if (($attrs['recomendacao'] ?? null) !== null) {
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

        // Curva do teste importada do ficheiro também é conteúdo real.
        if (($attrs['descarga_curva'] ?? null) !== null) {
            return true;
        }

        // Bloco SADEI (equipamentos de incêndio): sadeiAtributos devolve null quando vazio.
        if (($attrs['sadei'] ?? null) !== null) {
            return true;
        }

        // Assinaturas (nome de quem assina) também são conteúdo.
        if (($attrs['assinatura_cliente_nome'] ?? null) !== null || ($attrs['assinatura_tecnico_nome'] ?? null) !== null) {
            return true;
        }

        return false;
    }
}
