<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

// REGISTO de despesas: cabeçalho (colaborador, matrícula, departamento) + linhas — cada
// linha é UMA despesa: dia (escrito à mão), descrição (cliente - localidade), "o que é"
// (detalhe), tipo (categoria da folha), valor e os RECIBOS anexados à própria linha.
// O registo aparece na listagem como uma só entrada e tem PDF transferível.
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    use ApenasEquipa;
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
    /** @var array<int, array{despesa_id: ?int, dia: string, descricao: string, detalhe: string, categoria: string, refeicao_tipo: string, valor: string}> */
    public array $linhas = [];

    // Recibos PENDENTES por linha (gravam-se com a despesa dessa linha ao guardar).
    /** @var array<int, array<int, TemporaryUploadedFile>> */
    public array $recibosPendentes = [];

    // Alvos de upload: por linha (câmara/galeria) e o do scanner (JS), com a linha ativa.
    /** @var array<int, mixed> */
    public array $recibosLinhaUpload = [];

    public $reciboDigitalizado = null;

    public int $linhaDigitalizacao = 0; // linha a que o scanner está a anexar

    private function linhaVazia(): array
    {
        return [
            'despesa_id' => null,
            'dia' => '',
            'descricao' => '',
            'detalhe' => '',
            'categoria' => '',
            'refeicao_tipo' => '',
            'valor' => '',
        ];
    }

    public function mount(?RegistoDespesa $registo = null): void
    {
        if ($registo && $registo->exists) {
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
        unset($this->linhas[$indice], $this->recibosPendentes[$indice]);
        $this->linhas = array_values($this->linhas);
        $this->recibosPendentes = array_values($this->recibosPendentes + []);
    }

    private const REGRAS_RECIBO = ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000'];

    // Câmara nativa / galeria de uma LINHA: valida e junta aos pendentes dessa linha.
    public function updatedRecibosLinhaUpload($valor, $chave): void
    {
        // $chave é o índice da linha ("3") ou um sub-índice ("3.0") — interessa a linha.
        $linha = (int) explode('.', (string) $chave)[0];
        $ficheiros = is_array($this->recibosLinhaUpload[$linha] ?? null)
            ? $this->recibosLinhaUpload[$linha]
            : array_filter([$this->recibosLinhaUpload[$linha] ?? null]);

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
        // Só recibos de linhas DESTE registo.
        $anexo = Anexo::whereKey($anexoId)
            ->where('anexavel_type', Despesa::class)
            ->whereIn('anexavel_id', $registo->despesas()->pluck('id'))
            ->firstOrFail();
        Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();
    }

    public function guardar()
    {
        $this->validate([
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'linhas' => ['array', 'max:31'],
            'linhas.*.dia' => ['nullable', 'date'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:255'],
            'linhas.*.detalhe' => ['nullable', 'string', 'max:255'],
            'linhas.*.categoria' => ['nullable', Rule::in(array_merge([''], Despesa::CATEGORIAS))],
            'linhas.*.refeicao_tipo' => ['nullable', 'in:A,J'],
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
                $this->addError("linhas.$n.descricao", 'Indique a descrição (cliente - localidade) na linha '.($n + 1).'.');

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

            $lancamentos[$n] = [
                'despesa_id' => $linha['despesa_id'] ?? null,
                'data' => $data,
                'descricao' => $descricao,
                'detalhe' => trim((string) ($linha['detalhe'] ?? '')) ?: null,
                'categoria' => $categoria,
                'valor' => (float) $valor,
                'refeicao_tipo' => $categoria === 'Refeições' ? $refeicaoTipo : null,
            ];
        }

        if ($lancamentos === []) {
            $this->addError('linhas', 'Preencha pelo menos uma linha com o valor da despesa.');

            return;
        }

        $cabecalho = [
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
        ];

        if ($this->registoId) {
            $registo = RegistoDespesa::findOrFail($this->registoId);
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

        session()->flash('sucesso', $this->registoId ? 'Registo de despesas atualizado.' : 'Registo de despesas guardado.');

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
