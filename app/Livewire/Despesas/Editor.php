<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Despesa;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// Registo de despesa individual, no LAYOUT da folha da empresa: cabeçalho (colaborador,
// matrícula, departamento, data) + grelha com as colunas fixas — cada coluna com valor
// grava uma despesa dessa categoria — + recibos digitalizados. As ligações a cliente/
// intervenção saíram do formulário (pedido da equipa); despesas antigas mantêm as suas.
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    use ApenasEquipa;
    use WithFileUploads;

    public ?int $despesaId = null;

    public string $data = '';
    public string $descricao = '';

    // Cabeçalho da folha (como na folha impressa da empresa).
    public string $matricula = '';
    public string $departamento = '';

    // Valores por COLUNA da folha (índice → Despesa::CATEGORIAS): preenche-se o que se
    // aplica; cada coluna com valor grava uma despesa individual dessa categoria.
    /** @var array<int, string> */
    public array $valores = [];

    // Recibos: pendentes (ficam com a despesa ao guardar — funciona também na criação, antes
    // de existir id) + alvo dos uploads (câmara/galeria e o "Digitalizar" via JS).
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $recibos = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $recibosUpload = [];

    public $reciboDigitalizado = null; // upload único vindo do scanner (JS)

    public function mount(?Despesa $despesa = null): void
    {
        $this->valores = array_fill(0, count(Despesa::CATEGORIAS), '');

        if ($despesa && $despesa->exists) {
            $this->despesaId = $despesa->id;
            $this->data = $despesa->data->toDateString();
            // O valor entra na coluna da categoria da despesa (legado desconhecido → "Outras").
            $indice = array_search($despesa->categoria, Despesa::CATEGORIAS, true);
            $this->valores[$indice === false ? count(Despesa::CATEGORIAS) - 1 : $indice] = (string) $despesa->valor;
            $this->descricao = $despesa->descricao;
            $this->matricula = $despesa->matricula ?? '';
            $this->departamento = $despesa->departamento ?? '';

            return;
        }

        $this->data = now()->toDateString();
    }

    private const REGRAS_RECIBO = ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000'];

    // Câmara nativa / galeria: valida e junta aos pendentes (gravam-se com a despesa).
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
        abort_unless($this->despesaId !== null, 404);
        $anexo = Despesa::findOrFail($this->despesaId)->anexos()->whereKey($anexoId)->firstOrFail();
        \Illuminate\Support\Facades\Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();
    }

    public function guardar()
    {
        $this->validate([
            'data' => ['required', 'date'],
            'descricao' => ['required', 'string', 'max:255'],
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'valores' => ['array', 'max:' . count(Despesa::CATEGORIAS)],
            'valores.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Colunas preenchidas (valor > 0) → uma despesa por coluna, na categoria respetiva.
        // A categoria nunca vem do cliente: deriva do ÍNDICE da coluna (whitelist estrutural).
        $lancamentos = collect(Despesa::CATEGORIAS)
            ->map(fn (string $cat, int $i) => ['categoria' => $cat, 'valor' => trim((string) ($this->valores[$i] ?? ''))])
            ->filter(fn (array $l) => $l['valor'] !== '' && (float) $l['valor'] > 0)
            ->values();

        if ($lancamentos->isEmpty()) {
            $this->addError('valores', 'Preencha pelo menos uma coluna com o valor da despesa.');

            return;
        }

        $dados = [
            'data' => $this->data,
            'descricao' => trim($this->descricao),
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
        ];

        // EDIÇÃO: a 1.ª coluna preenchida atualiza a despesa aberta (categoria incluída — o
        // valor pode ter mudado de coluna) e preserva as ligações antigas (cliente/intervenção,
        // que já não se editam aqui); colunas adicionais criam despesas novas.
        // CRIAÇÃO: cada coluna preenchida cria a sua despesa.
        $primeira = null;
        foreach ($lancamentos as $i => $lancamento) {
            $atributos = $dados + ['categoria' => $lancamento['categoria'], 'valor' => (float) $lancamento['valor']];

            if ($i === 0 && $this->despesaId) {
                $despesa = Despesa::findOrFail($this->despesaId);
                $despesa->update($atributos);
            } else {
                $despesa = Despesa::create($atributos + ['faturavel' => false, 'criado_por' => auth()->id()]);
            }

            $primeira ??= $despesa;
        }

        session()->flash('sucesso', $this->despesaId ? 'Despesa atualizada.' : 'Despesa registada.');

        // Recibos pendentes → object storage + metadados anexados à 1.ª despesa (CLAUDE.md §2).
        foreach ($this->recibos as $ficheiro) {
            $key = $ficheiro->store('anexos/despesas/' . $primeira->id);
            $primeira->anexos()->create([
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
        // Total das colunas preenchidas (rodapé da grelha, atualiza enquanto se escreve).
        $total = collect($this->valores)->filter(fn ($v) => is_numeric($v))->sum(fn ($v) => (float) $v);

        return view('livewire.despesas.editor', [
            'total' => $total,
            'recibosGravados' => $this->despesaId
                ? Despesa::findOrFail($this->despesaId)->anexos()->orderBy('id')->get()
                : collect(),
        ]);
    }
}
