<?php

namespace App\Livewire\Despesas;

use App\Models\CategoriaDespesa;
use App\Models\Cliente;
use App\Models\Despesa;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    // Dobra de acentos em SQL, para a pesquisa de cliente (igual ao editor de contratos).
    private const NOME_SEM_ACENTOS = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    public ?int $despesaId = null;

    public string $data = '';
    public string $categoria = '';
    public string $descricao = '';
    public string $valor = '';
    public bool $faturavel = false;

    // Cliente (opcional) — pesquisa server-side.
    public ?int $cliente_id = null;
    public string $clienteBusca = '';

    public function mount(?Despesa $despesa = null): void
    {
        if ($despesa && $despesa->exists) {
            $this->despesaId = $despesa->id;
            $this->data = $despesa->data->toDateString();
            $this->categoria = $despesa->categoria;
            $this->descricao = $despesa->descricao;
            $this->valor = (string) $despesa->valor;
            $this->faturavel = $despesa->faturavel;
            $this->cliente_id = $despesa->cliente_id;
            $this->clienteBusca = $despesa->cliente?->nome ?? '';

            return;
        }

        $this->data = now()->toDateString();
    }

    public function selecionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if ($cliente) {
            $this->cliente_id = $cliente->id;
            $this->clienteBusca = $cliente->nome;
        }
    }

    public function limparCliente(): void
    {
        $this->cliente_id = null;
        $this->clienteBusca = '';
    }

    // Guarda uma nova categoria (lookup que cresce) e seleciona-a. Idempotente:
    // se já existir (sem acentos/maiúsculas), reutiliza a existente.
    public function adicionarCategoria(string $nome): bool
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        if ($nome === '') {
            $this->addError('novaCategoria', 'Indique a categoria.');

            return false;
        }

        $categoria = CategoriaDespesa::firstOrCreate(
            ['nome_normalizado' => CategoriaDespesa::normalizar($nome)],
            ['nome' => $nome],
        );

        $this->categoria = $categoria->nome; // forma canónica
        $this->resetErrorBag('novaCategoria');

        return true;
    }

    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    public function guardar()
    {
        $dados = $this->validate([
            'data' => ['required', 'date'],
            'categoria' => ['required', 'string', 'max:100'],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0'],
            'faturavel' => ['boolean'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ]);

        // Categoria fica guardada para reutilização futura (lookup que cresce com o uso);
        // o valor gravado é a forma canónica da lookup.
        $categoria = CategoriaDespesa::firstOrCreate(
            ['nome_normalizado' => CategoriaDespesa::normalizar($this->categoria)],
            ['nome' => trim($this->categoria)],
        );
        $dados['categoria'] = $categoria->nome;

        // O contrato/equipamento herdados ficam para uma fase seguinte; por agora liga ao cliente.
        $dados['cliente_id'] = $this->cliente_id;

        if ($this->despesaId) {
            Despesa::findOrFail($this->despesaId)->update($dados);
            session()->flash('sucesso', 'Despesa atualizada.');
        } else {
            $dados['criado_por'] = auth()->id();
            Despesa::create($dados);
            session()->flash('sucesso', 'Despesa registada.');
        }

        return redirect()->route('despesas');
    }

    public function render()
    {
        $clientesFiltrados = Cliente::query()
            ->when($this->clienteBusca !== '', function ($q) {
                $termo = '%' . $this->clienteBusca . '%';
                $nomeNorm = '%' . $this->normalizarBusca($this->clienteBusca) . '%';
                $q->where(fn ($q) => $q->whereRaw(self::NOME_SEM_ACENTOS . ' like ?', [$nomeNorm])
                    ->orWhere('nif', 'ilike', $termo));
            })
            ->orderBy('nome')
            ->limit(20)
            ->get();

        return view('livewire.despesas.editor', [
            'categorias' => CategoriaDespesa::orderBy('nome')->get(),
            'clientesFiltrados' => $clientesFiltrados,
        ]);
    }
}
