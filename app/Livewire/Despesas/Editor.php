<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// REGISTO de despesas no LAYOUT da folha da empresa: cabeçalho (colaborador, matrícula,
// departamento) + grelha com VÁRIAS linhas (data + descrição + colunas fixas). O registo
// aparece na listagem como UMA só entrada e tem PDF transferível; por baixo, cada célula
// com valor é uma linha em `despesas` (mantém os KPIs por categoria).
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    use ApenasEquipa;
    use WithFileUploads;

    public ?int $registoId = null;

    // Cabeçalho da folha (como na folha impressa da empresa).
    public string $matricula = '';
    public string $departamento = '';

    // Linhas da grelha: data, descrição "(cliente - localidade)", valores por COLUNA
    // (índice → Despesa::CATEGORIAS) e o A/J das refeições (nota a) da folha).
    /** @var array<int, array{data: string, descricao: string, valores: array<int, string>, refeicao_tipo: string}> */
    public array $linhas = [];

    // Recibos: pendentes (gravam-se com o registo — funciona também na criação, antes de
    // existir id) + alvo dos uploads (câmara/galeria e o "Digitalizar" via JS).
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $recibos = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $recibosUpload = [];

    public $reciboDigitalizado = null; // upload único vindo do scanner (JS)

    private function linhaVazia(): array
    {
        return [
            'data' => now()->toDateString(),
            'descricao' => '',
            'valores' => array_fill(0, count(Despesa::CATEGORIAS), ''),
            'refeicao_tipo' => '',
        ];
    }

    public function mount(?RegistoDespesa $registo = null): void
    {
        if ($registo && $registo->exists) {
            $this->registoId = $registo->id;
            $this->matricula = $registo->matricula ?? '';
            $this->departamento = $registo->departamento ?? '';
            $this->linhas = $registo->linhas() ?: [$this->linhaVazia()];

            return;
        }

        $this->linhas = [$this->linhaVazia()];
    }

    public function adicionarLinha(): void
    {
        // Máx. 31 linhas (um mês de despesas de uma vez chega bem).
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
    }

    private const REGRAS_RECIBO = ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000'];

    // Câmara nativa / galeria: valida e junta aos pendentes (gravam-se com o registo).
    public function updatedRecibosUpload(): void
    {
        $this->validate(['recibosUpload.*' => self::REGRAS_RECIBO]);
        $this->recibos = array_merge($this->recibos, $this->recibosUpload);
        $this->recibosUpload = [];
    }

    // "Digitalizar": o scanner (JS) envia a imagem já com o filtro de documento aplicado.
    public function updatedReciboDigitalizado(): void
    {
        $this->validate(['reciboDigitalizado' => self::REGRAS_RECIBO]);
        $this->recibos[] = $this->reciboDigitalizado;
        $this->reciboDigitalizado = null;
    }

    public function removerReciboPendente(int $indice): void
    {
        unset($this->recibos[$indice]);
        $this->recibos = array_values($this->recibos);
    }

    // Remove um recibo JÁ GRAVADO (edição) — apaga o ficheiro e os metadados.
    public function removerReciboGravado(int $anexoId): void
    {
        abort_unless($this->registoId !== null, 404);
        $anexo = RegistoDespesa::findOrFail($this->registoId)->anexos()->whereKey($anexoId)->firstOrFail();
        \Illuminate\Support\Facades\Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();
    }

    public function guardar()
    {
        $this->validate([
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'linhas' => ['array', 'max:31'],
            'linhas.*.data' => ['required', 'date'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:255'],
            'linhas.*.valores' => ['array', 'max:' . count(Despesa::CATEGORIAS)],
            'linhas.*.valores.*' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.refeicao_tipo' => ['nullable', 'in:A,J'],
        ]);

        // Lançamentos: por linha, cada coluna com valor > 0 → uma despesa dessa categoria.
        // A categoria nunca vem do cliente: deriva do ÍNDICE da coluna (whitelist estrutural).
        $lancamentos = [];
        foreach ($this->linhas as $n => $linha) {
            $daLinha = collect(Despesa::CATEGORIAS)
                ->map(fn (string $cat, int $i) => ['categoria' => $cat, 'valor' => trim((string) ($linha['valores'][$i] ?? ''))])
                ->filter(fn (array $l) => $l['valor'] !== '' && (float) $l['valor'] > 0)
                ->values();

            if ($daLinha->isEmpty()) {
                continue; // linha em branco — ignorada
            }

            $descricao = trim((string) ($linha['descricao'] ?? ''));
            if ($descricao === '') {
                $this->addError("linhas.$n.descricao", 'Indique a descrição (cliente - localidade) na linha ' . ($n + 1) . '.');

                return;
            }

            // Nota a) da folha, imposta a sério: com valor em Refeições, A ou J é obrigatório.
            $temRefeicoes = $daLinha->contains(fn (array $l) => $l['categoria'] === 'Refeições');
            $refeicaoTipo = (string) ($linha['refeicao_tipo'] ?? '');
            if ($temRefeicoes && ! in_array($refeicaoTipo, ['A', 'J'], true)) {
                $this->addError("linhas.$n.refeicao_tipo", 'Nas refeições, indique A (almoço) ou J (jantar) — linha ' . ($n + 1) . '.');

                return;
            }

            foreach ($daLinha as $l) {
                $lancamentos[] = [
                    'data' => $linha['data'],
                    'descricao' => $descricao,
                    'categoria' => $l['categoria'],
                    'valor' => (float) $l['valor'],
                    // A/J só na despesa de Refeições; null nas restantes.
                    'refeicao_tipo' => $l['categoria'] === 'Refeições' ? $refeicaoTipo : null,
                ];
            }
        }

        if ($lancamentos === []) {
            $this->addError('linhas', 'Preencha pelo menos uma célula com o valor da despesa.');

            return;
        }

        $cabecalho = [
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
        ];

        if ($this->registoId) {
            $registo = RegistoDespesa::findOrFail($this->registoId);
            $registo->update($cabecalho);
            // As linhas são substituídas pelo que está na grelha (edição total do documento).
            $registo->despesas()->delete();
        } else {
            $registo = RegistoDespesa::create($cabecalho + ['criado_por' => auth()->id()]);
        }

        foreach ($lancamentos as $lancamento) {
            $registo->despesas()->create($lancamento + ['faturavel' => false, 'criado_por' => auth()->id()]);
        }

        session()->flash('sucesso', $this->registoId ? 'Registo de despesas atualizado.' : 'Registo de despesas guardado.');

        // Recibos pendentes → object storage + metadados anexados ao registo (CLAUDE.md §2).
        foreach ($this->recibos as $ficheiro) {
            $key = $ficheiro->store('anexos/despesas/' . $registo->id);
            $registo->anexos()->create([
                'nome_ficheiro' => $ficheiro->getClientOriginalName() ?: 'recibo.jpg',
                'storage_key' => $key,
                'mime' => $ficheiro->getMimeType(),
                'tamanho' => $ficheiro->getSize(),
                'criado_por' => auth()->id(),
            ]);
        }

        return redirect()->route('despesas');
    }

    public function render()
    {
        // Totais por coluna e total geral (rodapé da grelha, atualizam enquanto se escreve).
        $totais = array_fill(0, count(Despesa::CATEGORIAS), 0.0);
        foreach ($this->linhas as $linha) {
            foreach ($linha['valores'] ?? [] as $i => $v) {
                if (is_numeric($v)) {
                    $totais[$i] += (float) $v;
                }
            }
        }

        return view('livewire.despesas.editor', [
            'totais' => $totais,
            'total' => array_sum($totais),
            'recibosGravados' => $this->registoId
                ? RegistoDespesa::findOrFail($this->registoId)->anexos()->orderBy('id')->get()
                : collect(),
        ]);
    }
}
