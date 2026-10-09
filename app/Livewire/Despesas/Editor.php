<?php

namespace App\Livewire\Despesas;

use App\Enums\EstadoDespesa;
use App\Livewire\Concerns\AcessoDespesas;
use App\Models\Anexo;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\LevantamentoDespesa;
use App\Models\MemoriaFornecedor;
use App\Models\RegistoDespesa;
use App\Rules\ImagemParaPdf;
use App\Services\Auditor;
use App\Services\Despesas\FluxoAprovacaoDespesas;
use App\Services\Despesas\LeitorTalao;
use App\Services\Despesas\QrFatura;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

// REGISTO de despesas: cabeçalho (colaborador, matrícula, departamento) + linhas — cada
// linha é UMA despesa: dia (escrito à mão), descrição (o CLIENTE — com pesquisa), "o que é"
// (detalhe: o restaurante/posto que o recibo diz), tipo (categoria da folha), valor e os RECIBOS anexados à própria linha.
// O registo aparece na listagem como uma só entrada e tem PDF transferível.
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    use AcessoDespesas;
    use WithFileUploads;

    // #[Locked]: definido apenas no mount (rota) — um payload forjado a apontar o editor a
    // outro registo a meio da sessão é recusado (15.ª revisão de segurança; defesa em
    // profundidade — a equipa já pode abrir qualquer registo pela rota, mas sempre às claras).
    #[\Livewire\Attributes\Locked]
    public ?int $registoId = null;

    // Cabeçalho da folha (como na folha impressa da empresa).
    public string $matricula = '';

    public string $departamento = '';

    // A matrícula veio da última despesa (registo novo) — só para mostrar a nota por baixo.
    #[\Livewire\Attributes\Locked]
    public bool $matriculaSugerida = false;

    // Linhas: cada uma = uma despesa. 'dia' é escolhido no calendário (nasce VAZIO — nenhum
    // dia pré-selecionado); 'despesa_id' liga à despesa existente (edição — preserva os recibos).
    /** @var array<int, array{despesa_id: ?int, dia: string, descricao: string, detalhe: string, categoria: string, refeicao_tipo: string, pago_por: string, valor: string}> */
    public array $linhas = [];

    // Levantamentos de dinheiro do cartão do técnico (set. 2026): dia, valor e o talão do
    // multibanco (obrigatório). 'levantamento_id' liga ao gravado (edição — preserva o talão);
    // vem do browser, por isso só se usa filtrado aos levantamentos DESTE registo.
    /** @var array<int, array{levantamento_id: ?int, dia: string, valor: string}> */
    public array $levantamentos = [];

    // Talões PENDENTES por levantamento e o alvo de upload (câmara/galeria), como nos recibos.
    /** @var array<int, array<int, TemporaryUploadedFile>> */
    public array $taloesPendentes = [];

    /** @var array<int, mixed> */
    public array $talaoLevantamentoUpload = [];

    // "Digitalizar" (scanner JS) do talão: a imagem chega já com o filtro; junta ao levantamento ativo.
    public $talaoDigitalizado = null;

    public int $levantamentoDigitalizacao = 0; // levantamento a que o scanner está a anexar

    // Recibos PENDENTES por linha (gravam-se com a despesa dessa linha ao guardar).
    /** @var array<int, array<int, TemporaryUploadedFile>> */
    public array $recibosPendentes = [];

    // Alvos de upload: por linha (câmara/galeria) e o do scanner (JS), com a linha ativa.
    /** @var array<int, mixed> */
    public array $recibosLinhaUpload = [];

    public $reciboDigitalizado = null;

    public int $linhaDigitalizacao = 0; // linha a que o scanner está a anexar

    // O que o QR code do recibo de cada linha deu — só para mostrar por baixo do recibo, não se
    // grava. #[Locked]: escreve-o apenas o servidor, no lerQr().
    // Com vários recibos na mesma linha, «leituras» guarda cada um (total = a soma; data = a do
    // mais antigo); nif/série/intermédia são os do primeiro.
    /** @var array<int, array{estado: string, data?: string, total?: string, nif?: string, serie?: ?string, intermedia?: bool, documento?: ?string, leituras?: list<array<string, mixed>>}> */
    #[\Livewire\Attributes\Locked]
    public array $qrLido = [];

    // O que o TEXTO do talão (OCR no telemóvel) deu, por linha — já interpretado (lerTalao()).
    /** @var array<int, array{descricao: ?string, categoria: ?string, hora: ?string}> */
    #[\Livewire\Attributes\Locked]
    public array $talaoLido = [];

    // Valores que o recibo pôs na linha, por campo. Um campo ainda igual ao que lá se pôs não foi
    // mexido pela pessoa — uma leitura melhor (o texto do talão chega uns segundos depois do QR)
    // pode trocá-lo. O que a pessoa escreveu nunca se toca.
    /** @var array<int, array<string, string>> */
    #[\Livewire\Attributes\Locked]
    public array $autoPreenchido = [];

    private function linhaVazia(): array
    {
        return [
            'despesa_id' => null,
            'dia' => '',
            'descricao' => '',
            'detalhe' => '',
            'categoria' => '',
            'refeicao_tipo' => '',
            'pago_por' => '', // nasce vazio, como o Tipo: quem lança tem de escolher
            'cartao_forma' => '', // com o Cartão Técnico: 'multibanco' ou 'dinheiro' (levantado)
            'valor' => '',
        ];
    }

    public function mount(?RegistoDespesa $registo = null): void
    {
        if ($registo && $registo->exists) {
            // Aprovada = fechada: ninguém edita (a aprovação deixava de valer). Abre a ficha.
            if (! $registo->podeSerEditado()) {
                $this->linhas = [$this->linhaVazia()];
                session()->flash('erro', 'Esta despesa já foi aprovada e não pode ser alterada.');
                $this->redirectRoute('despesas.registo.ficha', $registo);

                return;
            }

            $this->registoId = $registo->id;
            $this->matricula = $registo->matricula ?? '';
            $this->departamento = $registo->departamento ?? '';

            $this->linhas = $registo->linhasOrdenadas()->map(fn (Despesa $d) => [
                'despesa_id' => $d->id,
                'dia' => $d->data->toDateString(),
                'descricao' => $d->descricao,
                'detalhe' => $d->detalhe ?? '',
                'categoria' => in_array($d->categoria, Despesa::CATEGORIAS, true) ? $d->categoria : 'Outras despesas',
                'refeicao_tipo' => $d->refeicao_tipo ?? '',
                // «Dinheiro levantado» grava-se à parte; no ecrã é Cartão Técnico + dinheiro.
                'pago_por' => $d->pago_por === Despesa::DINHEIRO_LEVANTADO ? 'cartao_tecnico' : ($d->pago_por ?? ''),
                'cartao_forma' => match ($d->pago_por) {
                    Despesa::DINHEIRO_LEVANTADO => 'dinheiro',
                    'cartao_tecnico' => 'multibanco',
                    default => '',
                },
                'valor' => (string) $d->valor,
            ])->values()->all() ?: [$this->linhaVazia()];

            $this->levantamentos = $registo->levantamentos()->get()->map(fn (LevantamentoDespesa $l) => [
                'levantamento_id' => $l->id,
                'dia' => $l->data->toDateString(),
                'valor' => (string) $l->valor,
            ])->values()->all();

            return;
        }

        $this->linhas = [$this->linhaVazia()];

        // Registo novo: o departamento nasce "IFE" e a matrícula com a da última despesa desta
        // pessoa (quase sempre a mesma viatura) — ambos editáveis, para quando vai noutra.
        $this->departamento = 'IFE';
        $this->matricula = (string) RegistoDespesa::where('criado_por', auth()->id())
            ->whereNotNull('matricula')->where('matricula', '!=', '')
            ->latest('id')->value('matricula');
        $this->matriculaSugerida = $this->matricula !== '';
    }

    /**
     * Pesquisa de clientes para a DESCRIÇÃO de uma linha (nome sem acentos ou NIF), chamada pelo
     * browser enquanto se escreve. Poucos resultados e só com 2+ letras — nunca a lista toda.
     *
     * @return list<array{id: int, nome: string, nif: ?string}>
     */
    public function procurarClientes(string $texto): array
    {
        $texto = mb_substr(trim($texto), 0, 100);
        if (mb_strlen($texto) < 2) {
            return [];
        }

        $semAcentos = fn (string $v) => str_replace(
            ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'],
            ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'],
            mb_strtolower($v),
        );
        $like = fn (string $v) => '%'.addcslashes($v, '%_\\').'%';

        return Cliente::query()
            ->where('ativo', true)
            ->where(fn ($q) => $q
                ->whereRaw("translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu') like ?", [$like($semAcentos($texto))])
                ->orWhere('nif', 'ilike', $like($texto)))
            ->orderBy('nome')
            ->limit(8)
            ->get(['id', 'nome', 'nif'])
            ->map(fn (Cliente $c) => ['id' => $c->id, 'nome' => $c->nome, 'nif' => trim((string) $c->nif) ?: null]) // o NIF do PHC vem com espaços
            ->all();
    }

    // --- Levantamentos do cartão ---------------------------------------------------------

    public function adicionarLevantamento(): void
    {
        if (count($this->levantamentos) < 10) {
            $this->levantamentos[] = ['levantamento_id' => null, 'dia' => '', 'valor' => ''];
        }
    }

    // Tira da grelha; um levantamento já gravado (e o talão) só se apaga ao guardar.
    public function removerLevantamento(int $indice): void
    {
        unset($this->levantamentos[$indice]);
        $this->levantamentos = array_values($this->levantamentos);
        $this->taloesPendentes = $this->semLinha($this->taloesPendentes, $indice);
    }

    // Cartão Técnico → aparece a escolha «Multibanco / Dinheiro levantado» (nasce Multibanco, o
    // caso de todos os dias). Dinheiro levantado → a secção dos levantamentos aparece (ver
    // mostrarLevantamentos()) já com um levantamento por preencher.
    public function updatedLinhas($valor, $chave): void
    {
        $partes = explode('.', (string) $chave);
        $n = (int) $partes[0];
        $campo = $partes[1] ?? '';
        if (! array_key_exists($n, $this->linhas)) {
            return;
        }

        if ($campo === 'pago_por') {
            $this->linhas[$n]['cartao_forma'] = $valor === 'cartao_tecnico'
                ? (($this->linhas[$n]['cartao_forma'] ?? '') ?: 'multibanco')
                : '';
        }

        if ($this->pagaComDinheiro($this->linhas[$n]) && $this->levantamentos === []) {
            $this->adicionarLevantamento();
        }
    }

    // A linha foi paga com dinheiro levantado do cartão do técnico?
    private function pagaComDinheiro(array $linha): bool
    {
        return ($linha['pago_por'] ?? '') === 'cartao_tecnico' && ($linha['cartao_forma'] ?? '') === 'dinheiro';
    }

    // A secção «Levantamentos do cartão» só aparece com uma linha paga com «Dinheiro levantado»
    // (pedido da equipa, set. 2026) — ou se algum levantamento já tem dados, para nunca esconder
    // o que está gravado ou escrito.
    private function mostrarLevantamentos(): bool
    {
        return collect($this->linhas)->contains(fn ($l) => $this->pagaComDinheiro($l))
            || collect($this->levantamentos)->keys()->contains(fn ($i) => ! empty($this->levantamentos[$i]['levantamento_id'])
                || trim((string) ($this->levantamentos[$i]['dia'] ?? '')) !== ''
                || trim((string) ($this->levantamentos[$i]['valor'] ?? '')) !== ''
                || ($this->taloesPendentes[$i] ?? []) !== []);
    }

    // Câmara / galeria do talão de um levantamento: valida e junta aos pendentes dele.
    public function updatedTalaoLevantamentoUpload($valor, $chave): void
    {
        $indice = (int) explode('.', (string) $chave)[0];
        if (! is_array($this->talaoLevantamentoUpload[$indice] ?? null)) {
            $this->talaoLevantamentoUpload[$indice] = array_values(array_filter([$this->talaoLevantamentoUpload[$indice] ?? null]));
        }
        $ficheiros = $this->talaoLevantamentoUpload[$indice];

        $this->validate(["talaoLevantamentoUpload.$indice" => ['array'], "talaoLevantamentoUpload.$indice.*" => self::regrasRecibo()]);

        foreach ($ficheiros as $f) {
            $this->taloesPendentes[$indice][] = $f;
        }
        unset($this->talaoLevantamentoUpload[$indice]);
    }

    public function updatedTalaoDigitalizado(): void
    {
        $this->validate(['talaoDigitalizado' => self::regrasRecibo()]);
        if ($this->levantamentos === []) {
            $this->talaoDigitalizado = null;

            return;
        }
        $indice = max(0, min($this->levantamentoDigitalizacao, count($this->levantamentos) - 1));
        $this->taloesPendentes[$indice][] = $this->talaoDigitalizado;
        $this->talaoDigitalizado = null;
    }

    public function removerTalaoPendente(int $indice, int $ficheiro): void
    {
        unset($this->taloesPendentes[$indice][$ficheiro]);
        $this->taloesPendentes[$indice] = array_values($this->taloesPendentes[$indice] ?? []);
    }

    // Remove um talão JÁ GRAVADO — só de levantamentos DESTE registo, e nunca numa aprovada.
    public function removerTalaoGravado(int $anexoId): void
    {
        abort_unless($this->registoId !== null, 404);
        $registo = RegistoDespesa::findOrFail($this->registoId);
        abort_unless($registo->podeSerEditado(), 403, 'Despesa aprovada — não pode ser alterada.');
        $anexo = Anexo::whereKey($anexoId)
            ->where('anexavel_type', LevantamentoDespesa::class)
            ->whereIn('anexavel_id', $registo->levantamentos()->pluck('id'))
            ->firstOrFail();
        Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();

        Auditor::registar('talao_levantamento_removido', $registo, ['levantamento_id' => $anexo->anexavel_id, 'ficheiro' => $anexo->nome_ficheiro]);
    }

    public function adicionarLinha(): void
    {
        if (count($this->linhas) < 31) {
            $this->linhas[] = $this->linhaVazia();
        }
    }

    public function removerLinha(int $indice): void
    {
        if (count($this->linhas) <= 1) {
            return; // fica sempre pelo menos uma linha
        }
        unset($this->linhas[$indice]);
        $this->linhas = array_values($this->linhas);
        $this->recibosPendentes = $this->semLinha($this->recibosPendentes, $indice);
        $this->qrLido = $this->semLinha($this->qrLido, $indice);
        $this->talaoLido = $this->semLinha($this->talaoLido, $indice);
        $this->autoPreenchido = $this->semLinha($this->autoPreenchido, $indice);
    }

    // Tira a linha $indice de um array indexado por linha e puxa as seguintes uma casa para trás.
    // Antes fazia-se array_values(), que desalinhava tudo quando havia um buraco: sem recibos
    // na linha 1 e com recibos na 3, remover a 1.ª punha os recibos da 3 na 2 (set. 2026).
    private function semLinha(array $porLinha, int $indice): array
    {
        $novo = [];
        foreach ($porLinha as $n => $valor) {
            if ($n < $indice) {
                $novo[$n] = $valor;
            } elseif ($n > $indice) {
                $novo[$n - 1] = $valor;
            }
        }

        return $novo;
    }

    // Recibo com QR code (faturas portuguesas): o telemóvel lê o QR da fotografia e manda o
    // texto; aqui valida-se como fatura e preenche-se o DIA e o VALOR da linha — só os que
    // estiverem vazios, para nunca apagar o que a pessoa já escreveu. Com o NIF e a série do QR
    // vêm também a descrição e o tipo da memória de fornecedores (sugerir()). Texto vazio = não
    // havia QR legível na foto.
    public function lerQr(int $linha, string $texto): void
    {
        if (! array_key_exists($linha, $this->linhas)) {
            return;
        }

        $lido = QrFatura::ler($texto);
        $anteriores = ($this->qrLido[$linha]['estado'] ?? null) === 'lido' ? ($this->qrLido[$linha]['leituras'] ?? []) : [];
        if ($lido === null) {
            // Um recibo sem QR não apaga o que os outros recibos da linha já deram.
            if ($anteriores === []) {
                $this->qrLido[$linha] = ['estado' => trim($texto) === '' ? 'sem_qr' : 'invalido'];
            }

            return;
        }

        // O mesmo talão fotografado outra vez não conta duas vezes.
        if ($lido['documento'] !== null && in_array($lido['documento'], array_column($anteriores, 'documento'), true)) {
            return;
        }

        // Vários recibos na mesma linha (out. 2026): o valor é a SOMA de todos e o dia o do mais
        // antigo. Antes ficava o do primeiro e os outros só davam o aviso «diferente».
        $leituras = [...$anteriores, $lido];
        $total = number_format(array_sum(array_map(fn ($l) => (float) $l['total'], $leituras)), 2, '.', '');
        $dia = min(array_column($leituras, 'data'));

        $this->preencher($linha, 'dia', $dia);
        // O valor: vazio ou a zero, ou ainda o que o QR lá pôs (a pessoa não lhe mexeu).
        $valor = trim((string) ($this->linhas[$linha]['valor'] ?? ''));
        $posto = $this->autoPreenchido[$linha]['valor'] ?? null;
        if (($valor === '' || (float) $valor == 0.0 || ($posto !== null && (float) $valor == (float) $posto)) && (float) $total > 0) {
            $this->linhas[$linha]['valor'] = $total;
            $this->autoPreenchido[$linha]['valor'] = $total;
        }

        $this->qrLido[$linha] = ['estado' => 'lido', 'leituras' => $leituras, 'data' => $dia, 'total' => $total] + $leituras[0];
        $this->sugerir($linha);
    }

    // O TEXTO do talão, lido por OCR no telemóvel (uns segundos depois do QR): dá a loja e a terra
    // (vão para o «o que é»), o tipo e a hora (almoço/jantar). Só preenche o que está vazio.
    public function lerTalao(int $linha, string $texto): void
    {
        if (! array_key_exists($linha, $this->linhas) || trim($texto) === '') {
            return;
        }

        $this->talaoLido[$linha] = LeitorTalao::ler($texto);
        $this->sugerir($linha);
    }

    // Junta o que se sabe do recibo da linha — memória de fornecedores (o que uma pessoa já
    // confirmou) > texto do talão > taxa de IVA do QR — e preenche os campos vazios (ou ainda
    // com o que o próprio recibo lá pôs).
    private function sugerir(int $linha): void
    {
        $qr = ($this->qrLido[$linha]['estado'] ?? null) === 'lido' ? $this->qrLido[$linha] : null;
        $talao = $this->talaoLido[$linha] ?? [];
        $memoria = $qr ? MemoriaFornecedor::sugestao($qr['nif'], $qr['serie'] ?? null) : ['descricao' => null, 'categoria' => null];

        // A loja/restaurante vai para o «o que é»: a DESCRIÇÃO é o cliente, que só a pessoa sabe
        // (pedido da equipa, set. 2026 — antes o recibo punha lá o restaurante).
        $this->preencher($linha, 'detalhe', $memoria['descricao'] ?? $talao['descricao'] ?? null);

        $categoria = $memoria['categoria'] ?? $talao['categoria'] ?? (($qr['intermedia'] ?? false) ? 'Refeições' : null);
        if ($categoria !== null && in_array($categoria, Despesa::CATEGORIAS, true)) {
            $this->preencher($linha, 'categoria', $categoria);
        }

        if (($this->linhas[$linha]['categoria'] ?? '') === 'Refeições') {
            $this->preencher($linha, 'refeicao_tipo', LeitorTalao::refeicao($talao['hora'] ?? null));
        }
    }

    private function preencher(int $linha, string $campo, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $atual = (string) ($this->linhas[$linha][$campo] ?? '');
        $posto = $this->autoPreenchido[$linha][$campo] ?? null;
        if ($atual === '' || $atual === $posto) {
            $this->linhas[$linha][$campo] = $valor;
            $this->autoPreenchido[$linha][$campo] = $valor;
        }
    }

    // + ImagemParaPdf: fora do JPEG, no máximo 25 megapíxeis (o PDF descodifica-os — 27.ª revisão).
    /** Resumo do registo para a auditoria das alterações (o suficiente para ver o que mudou). */
    private static function resumoParaAuditoria(RegistoDespesa $registo): array
    {
        return [
            'total' => round($registo->total(), 2),
            'matricula' => $registo->matricula,
            'linhas' => $registo->linhasOrdenadas()->map(fn (Despesa $d) => [
                'dia' => $d->data->toDateString(),
                'descricao' => $d->descricao,
                'categoria' => $d->categoria,
                'valor' => (float) $d->valor,
                'pago_por' => $d->pago_por,
                'recibos' => $d->anexos->count(),
            ])->values()->all(),
            'levantamentos' => $registo->levantamentos()->withCount('anexos')->get()
                ->map(fn ($l) => ['dia' => $l->data->toDateString(), 'valor' => (float) $l->valor, 'taloes' => $l->anexos_count])->values()->all(),
        ];
    }

    private static function regrasRecibo(): array
    {
        return ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000', new ImagemParaPdf];
    }

    // Câmara nativa / galeria de uma LINHA: valida e junta aos pendentes dessa linha.
    public function updatedRecibosLinhaUpload($valor, $chave): void
    {
        // $chave é o índice da linha ("3") ou um sub-índice ("3.0") — interessa a linha.
        $linha = (int) explode('.', (string) $chave)[0];

        // «Tirar foto» não tem `multiple` (tirado a 31/07: no iPhone partia o «Repetir») e manda
        // UM ficheiro; a galeria manda uma lista. Põe-se sempre em lista ANTES de validar — a
        // regra 'array' recusava a foto única com «The recibos linha upload.0 field must be an
        // array», e «Tirar foto» não gravava recibo nenhum desde 05/08 (set. 2026).
        if (! is_array($this->recibosLinhaUpload[$linha] ?? null)) {
            $this->recibosLinhaUpload[$linha] = array_values(array_filter([$this->recibosLinhaUpload[$linha] ?? null]));
        }
        $ficheiros = $this->recibosLinhaUpload[$linha];

        $this->validate(["recibosLinhaUpload.$linha" => ['array'], "recibosLinhaUpload.$linha.*" => self::regrasRecibo()]);

        foreach ($ficheiros as $f) {
            $this->recibosPendentes[$linha][] = $f;
        }
        unset($this->recibosLinhaUpload[$linha]);
    }

    // "Digitalizar" (scanner JS): a imagem chega já com o filtro; junta à linha ativa.
    public function updatedReciboDigitalizado(): void
    {
        $this->validate(['reciboDigitalizado' => self::regrasRecibo()]);
        $linha = max(0, min($this->linhaDigitalizacao, count($this->linhas) - 1));
        $this->recibosPendentes[$linha][] = $this->reciboDigitalizado;
        $this->reciboDigitalizado = null;
    }

    public function removerReciboPendente(int $linha, int $indice): void
    {
        unset($this->recibosPendentes[$linha][$indice]);
        $this->recibosPendentes[$linha] = array_values($this->recibosPendentes[$linha] ?? []);
        $this->esquecerQr($linha);
    }

    // Tirar um recibo da linha (out. 2026): o dia e o valor que o QR lá pôs ficavam, e a foto
    // certa tirada a seguir já não os trocava (só preenche campos vazios) — a linha ficava com o
    // valor do recibo errado. Agora, o que o QR pôs e a pessoa não mexeu sai com o recibo, e as
    // leituras da linha esquecem-se: os recibos que ficam leem-se outra vez ao fotografá-los, ou
    // escreve-se o valor à mão. O que a pessoa escreveu nunca se toca.
    private function esquecerQr(int $linha): void
    {
        foreach (['dia', 'valor'] as $campo) {
            $posto = $this->autoPreenchido[$linha][$campo] ?? null;
            $atual = (string) ($this->linhas[$linha][$campo] ?? '');
            if ($posto !== null && ($campo === 'valor' ? $atual !== '' && (float) $atual == (float) $posto : $atual === $posto)) {
                $this->linhas[$linha][$campo] = '';
            }
            unset($this->autoPreenchido[$linha][$campo]);
        }
        unset($this->qrLido[$linha]);
    }

    // Remove um recibo JÁ GRAVADO (da despesa da linha) — apaga o ficheiro e os metadados.
    public function removerReciboGravado(int $anexoId): void
    {
        abort_unless($this->registoId !== null, 404);
        $registo = RegistoDespesa::findOrFail($this->registoId);
        // Aprovada = fechada, também para os recibos: um editor aberto desde antes da aprovação
        // ainda chamava isto e apagava o comprovativo de vez (25.ª revisão de segurança).
        abort_unless($registo->podeSerEditado(), 403, 'Despesa aprovada — não pode ser alterada.');
        // Só recibos de linhas DESTE registo.
        $anexo = Anexo::whereKey($anexoId)
            ->where('anexavel_type', Despesa::class)
            ->whereIn('anexavel_id', $registo->despesas()->pluck('id'))
            ->firstOrFail();
        Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();

        $linha = array_search($anexo->anexavel_id, array_map(fn ($l) => (int) ($l['despesa_id'] ?? 0), $this->linhas), true);
        if ($linha !== false) {
            $this->esquecerQr($linha);
        }

        // O ficheiro do recibo desaparece de vez — fica quem o apagou e de que despesa (revisão de 16/09).
        Auditor::registar('recibo_removido', $registo, ['despesa_id' => $anexo->anexavel_id, 'ficheiro' => $anexo->nome_ficheiro]);
    }

    public function guardar()
    {
        $this->validate([
            // Recibos pendentes REVALIDADOS aqui (22.ª revisão de segurança): a validação de
            // «é imagem» corria só ao adicionar, mas $recibosPendentes é propriedade pública —
            // um ficheiro temporário de outro tipo (ex.: HTML) podia ser posto aqui diretamente
            // e gravado como recibo, e depois servido inline a quem o abrisse (stored XSS).
            'recibosPendentes' => ['array'],
            'recibosPendentes.*' => ['array'],
            'recibosPendentes.*.*' => self::regrasRecibo(),
            'taloesPendentes' => ['array'],
            'taloesPendentes.*' => ['array'],
            'taloesPendentes.*.*' => self::regrasRecibo(),
            'levantamentos' => ['array', 'max:10'],
            'levantamentos.*.dia' => ['nullable', 'date'],
            'levantamentos.*.valor' => ['nullable', 'numeric', 'min:0'],
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'linhas' => ['array', 'max:31'],
            'linhas.*.dia' => ['nullable', 'date'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:255'],
            'linhas.*.detalhe' => ['nullable', 'string', 'max:255'],
            'linhas.*.categoria' => ['nullable', Rule::in(array_merge([''], Despesa::CATEGORIAS))],
            'linhas.*.refeicao_tipo' => ['nullable', 'in:A,J'],
            'linhas.*.pago_por' => ['nullable', Rule::in(array_merge([''], array_keys(Despesa::PAGO_POR)))],
            'linhas.*.cartao_forma' => ['nullable', 'in:multibanco,dinheiro'],
            'linhas.*.valor' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Valida e normaliza cada linha preenchida (valor > 0). Linhas em branco são ignoradas.
        $lancamentos = [];
        foreach ($this->linhas as $n => $linha) {
            $valor = trim((string) ($linha['valor'] ?? ''));
            $temRecibos = ($this->recibosPendentes[$n] ?? []) !== [];
            if (($valor === '' || (float) $valor == 0.0) && ! $temRecibos && trim((string) ($linha['descricao'] ?? '')) === '') {
                continue; // linha em branco
            }

            if ($valor === '' || (float) $valor <= 0) {
                $this->addError("linhas.$n.valor", 'Indique o valor da despesa (linha '.($n + 1).').');

                return;
            }

            // Dia OBRIGATÓRIO — escolhido no calendário (nasce vazio, sem pré-seleção).
            $data = trim((string) ($linha['dia'] ?? ''));
            if ($data === '') {
                $this->addError("linhas.$n.dia", 'Escolha o dia no calendário (linha '.($n + 1).').');

                return;
            }
            $data = Carbon::parse($data)->toDateString();

            $descricao = trim((string) ($linha['descricao'] ?? ''));
            if ($descricao === '') {
                $this->addError("linhas.$n.descricao", 'Indique o cliente (descrição) na linha '.($n + 1).'.');

                return;
            }

            $categoria = (string) ($linha['categoria'] ?? '');
            if (! in_array($categoria, Despesa::CATEGORIAS, true)) {
                $this->addError("linhas.$n.categoria", 'Escolha o tipo de despesa na linha '.($n + 1).'.');

                return;
            }

            // Nota a) da folha: refeições exigem A (almoço) ou J (jantar).
            $refeicaoTipo = (string) ($linha['refeicao_tipo'] ?? '');
            if ($categoria === 'Refeições' && ! in_array($refeicaoTipo, ['A', 'J'], true)) {
                $this->addError("linhas.$n.refeicao_tipo", 'Nas refeições, indique A (almoço) ou J (jantar) — linha '.($n + 1).'.');

                return;
            }

            // Quem pagou: obrigatório — é o que diz ao financeiro o que há a reembolsar.
            $pagoPor = (string) ($linha['pago_por'] ?? '');
            if (! array_key_exists($pagoPor, Despesa::PAGO_POR)) {
                $this->addError("linhas.$n.pago_por", 'Indique quem pagou a despesa na linha '.($n + 1).'.');

                return;
            }

            // Cartão Técnico: multibanco ou dinheiro levantado (este grava-se com a chave própria).
            if ($pagoPor === 'cartao_tecnico') {
                $forma = (string) ($linha['cartao_forma'] ?? '');
                if (! in_array($forma, ['multibanco', 'dinheiro'], true)) {
                    $this->addError("linhas.$n.cartao_forma", 'Indique se o Cartão Técnico foi no multibanco ou com dinheiro levantado — linha '.($n + 1).'.');

                    return;
                }
                if ($forma === 'dinheiro') {
                    $pagoPor = Despesa::DINHEIRO_LEVANTADO;
                }
            }

            $lancamentos[$n] = [
                'despesa_id' => $linha['despesa_id'] ?? null,
                'data' => $data,
                'descricao' => $descricao,
                'detalhe' => trim((string) ($linha['detalhe'] ?? '')) ?: null,
                'categoria' => $categoria,
                'valor' => (float) $valor,
                'refeicao_tipo' => $categoria === 'Refeições' ? $refeicaoTipo : null,
                'pago_por' => $pagoPor,
            ];
        }

        if ($lancamentos === []) {
            $this->addError('linhas', 'Preencha pelo menos uma linha com o valor da despesa.');

            return;
        }

        // Recibo OBRIGATÓRIO em cada linha (processo de validação): sem a imagem do recibo
        // não há despesa — pendente (acabado de anexar) ou já gravado na despesa da linha.
        $comReciboGravado = $this->registoId
            ? Anexo::where('anexavel_type', Despesa::class)
                ->whereIn('anexavel_id', array_filter(array_column($lancamentos, 'despesa_id')))
                ->pluck('anexavel_id')->map(fn ($id) => (int) $id)->all()
            : [];
        foreach ($lancamentos as $n => $l) {
            $temPendente = ($this->recibosPendentes[$n] ?? []) !== [];
            $temGravado = $l['despesa_id'] && in_array((int) $l['despesa_id'], $comReciboGravado, true);
            if (! $temPendente && ! $temGravado) {
                $this->addError("linhas.$n.recibos", 'Anexe o recibo (fotografia ou digitalização) na linha '.($n + 1).' — é obrigatório.');

                return;
            }
        }

        // Levantamentos: dia, valor > 0 e o talão do multibanco (OBRIGATÓRIO, como os recibos).
        // Um novo em branco é ignorado; um já gravado esvaziado é erro (remove-se com o ×).
        $taloesGravados = $this->registoId
            ? Anexo::where('anexavel_type', LevantamentoDespesa::class)
                ->whereIn('anexavel_id', RegistoDespesa::findOrFail($this->registoId)->levantamentos()->pluck('id'))
                ->pluck('anexavel_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $levantamentos = [];
        foreach ($this->levantamentos as $i => $l) {
            $dia = trim((string) ($l['dia'] ?? ''));
            $valor = trim((string) ($l['valor'] ?? ''));
            $id = isset($l['levantamento_id']) && $l['levantamento_id'] ? (int) $l['levantamento_id'] : null;
            $temPendente = ($this->taloesPendentes[$i] ?? []) !== [];
            if (! $id && $dia === '' && $valor === '' && ! $temPendente) {
                continue;
            }
            if ($dia === '') {
                $this->addError("levantamentos.$i.dia", 'Escolha o dia do levantamento '.($i + 1).'.');

                return;
            }
            if ($valor === '' || (float) $valor <= 0) {
                $this->addError("levantamentos.$i.valor", 'Indique o valor levantado (levantamento '.($i + 1).').');

                return;
            }
            if (! $temPendente && ! ($id && in_array($id, $taloesGravados, true))) {
                $this->addError("levantamentos.$i.talao", 'Anexe o talão do multibanco no levantamento '.($i + 1).' — é obrigatório.');

                return;
            }
            $levantamentos[$i] = ['levantamento_id' => $id, 'data' => Carbon::parse($dia)->toDateString(), 'valor' => (float) $valor];
        }

        $cabecalho = [
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
        ];

        $antes = null;
        if ($this->registoId) {
            $registo = RegistoDespesa::findOrFail($this->registoId);
            abort_unless($registo->podeSerEditado(), 403, 'Despesa aprovada — não pode ser alterada.');
            $antes = self::resumoParaAuditoria($registo);
            $registo->update($cabecalho);
        } else {
            $registo = RegistoDespesa::create($cabecalho + ['criado_por' => auth()->id()]);
        }

        // Sincroniza por despesa_id: atualiza as existentes (preserva os recibos da linha),
        // cria as novas e apaga as removidas da grelha.
        $mantidas = [];
        foreach ($lancamentos as $n => $lancamento) {
            $despesaId = $lancamento['despesa_id'];
            unset($lancamento['despesa_id']);

            if ($despesaId && ($despesa = $registo->despesas()->whereKey($despesaId)->first())) {
                $despesa->update($lancamento);
            } else {
                $despesa = $registo->despesas()->create($lancamento + ['faturavel' => false, 'criado_por' => auth()->id()]);
            }
            $mantidas[] = $despesa->id;

            // Memória de fornecedores: o que ficou nesta linha é o que se sugere da próxima vez
            // que aparecer um talão deste vendedor (NIF e série vêm do QR lido nesta edição).
            // Com recibos de vendedores diferentes na mesma linha não se aprende nada (não se sabe
            // a qual deles pertence o «o que é» que ficou).
            $qr = $this->qrLido[$n] ?? [];
            if (($qr['estado'] ?? null) === 'lido' && isset($qr['nif'])
                && count(array_unique(array_column($qr['leituras'] ?? [$qr], 'nif'))) === 1) {
                MemoriaFornecedor::aprender($qr['nif'], $qr['serie'] ?? null, trim((string) $lancamento['detalhe']) ?: null, $lancamento['categoria']);
            }

            // Recibos pendentes desta linha → object storage + metadados na despesa da linha.
            foreach ($this->recibosPendentes[$n] ?? [] as $ficheiro) {
                // No disco da APLICAÇÃO: sem 'disk', o Livewire grava no disco temporário do upload.
                $key = $ficheiro->store('anexos/despesas/'.$despesa->id, ['disk' => config('filesystems.default')]);
                $despesa->anexos()->create([
                    'nome_ficheiro' => $ficheiro->getClientOriginalName() ?: 'recibo.jpg',
                    'storage_key' => $key,
                    'mime' => $ficheiro->getMimeType(),
                    'tamanho' => $ficheiro->getSize(),
                    'criado_por' => auth()->id(),
                ]);
            }
        }

        $registo->despesas()->whereNotIn('id', $mantidas)->delete(); // linhas removidas da grelha

        // Levantamentos: o mesmo sincronismo por id (só os DESTE registo), com o talão.
        $mantidos = [];
        foreach ($levantamentos as $i => $dados) {
            $id = $dados['levantamento_id'];
            unset($dados['levantamento_id']);

            if ($id && ($levantamento = $registo->levantamentos()->whereKey($id)->first())) {
                $levantamento->update($dados);
            } else {
                $levantamento = $registo->levantamentos()->create($dados);
            }
            $mantidos[] = $levantamento->id;

            foreach ($this->taloesPendentes[$i] ?? [] as $ficheiro) {
                $levantamento->anexos()->create([
                    'nome_ficheiro' => $ficheiro->getClientOriginalName() ?: 'talao.jpg',
                    'storage_key' => $ficheiro->store('anexos/levantamentos/'.$levantamento->id, ['disk' => config('filesystems.default')]), // (disco: ver os recibos acima)
                    'mime' => $ficheiro->getMimeType(),
                    'tamanho' => $ficheiro->getSize(),
                    'criado_por' => auth()->id(),
                ]);
            }
        }
        // Removidos da grelha: vão com o talão (ficheiro e metadados).
        foreach ($registo->levantamentos()->whereNotIn('id', $mantidos)->with('anexos')->get() as $removido) {
            foreach ($removido->anexos as $anexo) {
                Storage::disk()->delete($anexo->storage_key);
                $anexo->delete();
            }
            $removido->delete();
            Auditor::registar('levantamento_removido', $registo, ['levantamento_id' => $removido->id, 'valor' => (float) $removido->valor]);
        }

        // Alteração de um registo que já existia fica na AUDITORIA — quem, e o antes/depois (linhas,
        // valores, quem pagou, levantamentos). Antes não ficava rasto nenhum: uma despesa pendente
        // podia ser mudada depois de submetida sem ninguém saber (27.ª revisão de segurança).
        if ($antes !== null) {
            $depois = self::resumoParaAuditoria($registo->fresh());
            if ($depois !== $antes) {
                Auditor::registar('registo_despesas_alterado', $registo, ['estado' => $registo->fresh()->estado->value, 'antes' => $antes, 'depois' => $depois]);
            }
        }

        // Processo de validação: registo novo → submete (pendente + email a quem criou, ao
        // aprovador e ao financeiro); rejeitado e corrigido → volta a submeter; pendente
        // editado → continua pendente, sem novo email.
        $fluxo = app(FluxoAprovacaoDespesas::class);
        if (! $this->registoId) {
            $fluxo->submeter($registo->fresh());
            session()->flash('sucesso', 'Registo de despesas guardado — enviado para aprovação (email ao aprovador e ao financeiro).');
        } elseif ($registo->fresh()->estado === EstadoDespesa::Rejeitada) {
            $fluxo->submeter($registo->fresh(), reenvio: true);
            session()->flash('sucesso', 'Registo corrigido — volta a aguardar aprovação (email enviado).');
        } else {
            session()->flash('sucesso', 'Registo de despesas atualizado.');
        }

        return redirect()->route('despesas');
    }

    public function render()
    {
        $total = collect($this->linhas)
            ->map(fn ($l) => is_numeric($l['valor'] ?? null) ? (float) $l['valor'] : 0.0)
            ->sum();

        // Recibos gravados por despesa_id (edição) — mostrados na linha respetiva.
        $recibosPorDespesa = $this->registoId
            ? Anexo::where('anexavel_type', Despesa::class)
                ->whereIn('anexavel_id', RegistoDespesa::findOrFail($this->registoId)->despesas()->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('anexavel_id')
            : collect();

        // Contas do dinheiro levantado, ao vivo: levantado vs. linhas «Dinheiro levantado».
        $soma = fn (array $itens) => collect($itens)->sum(fn ($l) => is_numeric($l['valor'] ?? null) ? (float) $l['valor'] : 0.0);
        $contasDinheiro = RegistoDespesa::contas(
            $soma($this->levantamentos),
            $soma(array_filter($this->linhas, fn ($l) => $this->pagaComDinheiro($l))),
        );

        // Talões gravados por levantamento_id (edição).
        $taloesPorLevantamento = $this->registoId
            ? Anexo::where('anexavel_type', LevantamentoDespesa::class)
                ->whereIn('anexavel_id', RegistoDespesa::findOrFail($this->registoId)->levantamentos()->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('anexavel_id')
            : collect();

        return view('livewire.despesas.editor', [
            'total' => $total,
            'recibosPorDespesa' => $recibosPorDespesa,
            'contasDinheiro' => $contasDinheiro,
            'mostrarLevantamentos' => $this->mostrarLevantamentos(),
            'taloesPorLevantamento' => $taloesPorLevantamento,
        ]);
    }
}
