<?php

namespace App\Livewire\Despesas;

use App\Enums\EstadoDespesa;
use App\Livewire\Concerns\AcessoDespesas;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\MemoriaFornecedor;
use App\Models\RegistoDespesa;
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
// linha é UMA despesa: dia (escrito à mão), descrição (local · serviço), "o que é"
// (detalhe), tipo (categoria da folha), valor e os RECIBOS anexados à própria linha.
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

    // Linhas: cada uma = uma despesa. 'dia' é escolhido no calendário (nasce VAZIO — nenhum
    // dia pré-selecionado); 'despesa_id' liga à despesa existente (edição — preserva os recibos).
    /** @var array<int, array{despesa_id: ?int, dia: string, descricao: string, detalhe: string, categoria: string, refeicao_tipo: string, pago_por: string, valor: string}> */
    public array $linhas = [];

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
    /** @var array<int, array{estado: string, data?: string, total?: string, nif?: string, serie?: ?string, intermedia?: bool}> */
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
                'pago_por' => $d->pago_por ?? '',
                'valor' => (string) $d->valor,
            ])->values()->all() ?: [$this->linhaVazia()];

            return;
        }

        $this->linhas = [$this->linhaVazia()];
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
        if ($lido === null) {
            $this->qrLido[$linha] = ['estado' => trim($texto) === '' ? 'sem_qr' : 'invalido'];

            return;
        }

        if (trim((string) ($this->linhas[$linha]['dia'] ?? '')) === '') {
            $this->linhas[$linha]['dia'] = $lido['data'];
        }

        $valor = trim((string) ($this->linhas[$linha]['valor'] ?? ''));
        if (($valor === '' || (float) $valor == 0.0) && (float) $lido['total'] > 0) {
            $this->linhas[$linha]['valor'] = $lido['total'];
        }

        $this->qrLido[$linha] = ['estado' => 'lido'] + $lido;
        $this->sugerir($linha);
    }

    // O TEXTO do talão, lido por OCR no telemóvel (uns segundos depois do QR): dá a descrição
    // (loja e terra), o tipo e a hora (almoço/jantar). Só preenche o que está vazio.
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

        $this->preencher($linha, 'descricao', $memoria['descricao'] ?? $talao['descricao'] ?? null);

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

    private const REGRAS_RECIBO = ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000'];

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

        $this->validate(["recibosLinhaUpload.$linha" => ['array'], "recibosLinhaUpload.$linha.*" => self::REGRAS_RECIBO]);

        foreach ($ficheiros as $f) {
            $this->recibosPendentes[$linha][] = $f;
        }
        unset($this->recibosLinhaUpload[$linha]);
    }

    // "Digitalizar" (scanner JS): a imagem chega já com o filtro; junta à linha ativa.
    public function updatedReciboDigitalizado(): void
    {
        $this->validate(['reciboDigitalizado' => self::REGRAS_RECIBO]);
        $linha = max(0, min($this->linhaDigitalizacao, count($this->linhas) - 1));
        $this->recibosPendentes[$linha][] = $this->reciboDigitalizado;
        $this->reciboDigitalizado = null;
    }

    public function removerReciboPendente(int $linha, int $indice): void
    {
        unset($this->recibosPendentes[$linha][$indice]);
        $this->recibosPendentes[$linha] = array_values($this->recibosPendentes[$linha] ?? []);
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
            'recibosPendentes.*.*' => self::REGRAS_RECIBO,
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'linhas' => ['array', 'max:31'],
            'linhas.*.dia' => ['nullable', 'date'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:255'],
            'linhas.*.detalhe' => ['nullable', 'string', 'max:255'],
            'linhas.*.categoria' => ['nullable', Rule::in(array_merge([''], Despesa::CATEGORIAS))],
            'linhas.*.refeicao_tipo' => ['nullable', 'in:A,J'],
            'linhas.*.pago_por' => ['nullable', Rule::in(array_merge([''], array_keys(Despesa::PAGO_POR)))],
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
                $this->addError("linhas.$n.descricao", 'Indique a descrição (local · serviço) na linha '.($n + 1).'.');

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

        $cabecalho = [
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
        ];

        if ($this->registoId) {
            $registo = RegistoDespesa::findOrFail($this->registoId);
            abort_unless($registo->podeSerEditado(), 403, 'Despesa aprovada — não pode ser alterada.');
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
            $qr = $this->qrLido[$n] ?? [];
            if (($qr['estado'] ?? null) === 'lido' && isset($qr['nif'])) {
                MemoriaFornecedor::aprender($qr['nif'], $qr['serie'] ?? null, $lancamento['descricao'], $lancamento['categoria']);
            }

            // Recibos pendentes desta linha → object storage + metadados na despesa da linha.
            foreach ($this->recibosPendentes[$n] ?? [] as $ficheiro) {
                $key = $ficheiro->store('anexos/despesas/'.$despesa->id);
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

        return view('livewire.despesas.editor', [
            'total' => $total,
            'recibosPorDespesa' => $recibosPorDespesa,
        ]);
    }
}
