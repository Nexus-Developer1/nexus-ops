<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\CategoriaDespesa;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Intervencao;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Editor extends Component
{
    use ApenasEquipa;

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

    // Intervenção (opcional) — pesquisa GLOBAL server-side (nº relatório / nº série / cliente).
    // Ao associar, herda cliente + equipamento + contrato da intervenção.
    public ?int $intervencao_id = null;
    public string $intervencaoBusca = '';
    public string $intervencaoRotulo = '';   // rótulo da intervenção escolhida (para mostrar)
    // Herdados da intervenção (gravados; não editáveis à mão neste ecrã).
    public ?int $equipamento_id = null;
    public ?int $contrato_id = null;

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

            if ($despesa->intervencao_id) {
                $this->intervencao_id = $despesa->intervencao_id;
                $this->equipamento_id = $despesa->equipamento_id;
                $this->contrato_id = $despesa->contrato_id;
                $intervencao = $despesa->intervencao()->with(['equipamento.local.cliente', 'relatorio'])->first();
                if ($intervencao) {
                    $this->intervencaoRotulo = $this->rotuloIntervencao($intervencao);
                }
            }

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

    // Associa a despesa a uma intervenção e HERDA dela o cliente, o equipamento e o contrato
    // (fonte da verdade — essencial para faturação: incluído no contrato vs à parte).
    public function selecionarIntervencao(int $id): void
    {
        $intervencao = Intervencao::with(['equipamento.local.cliente', 'relatorio'])->find($id);
        if (! $intervencao) {
            return;
        }

        $this->intervencao_id = $intervencao->id;
        $this->intervencaoBusca = '';
        $this->intervencaoRotulo = $this->rotuloIntervencao($intervencao);

        $cliente = $intervencao->equipamento?->local?->cliente;
        $this->cliente_id = $cliente?->id;
        $this->clienteBusca = $cliente?->nome ?? '';
        $this->equipamento_id = $intervencao->equipamento_id;
        $this->contrato_id = $intervencao->contrato_id;
    }

    public function limparIntervencao(): void
    {
        // Desassocia a intervenção e os campos herdados dela. O cliente mantém-se (o utilizador
        // pode querer conservá-lo); pode limpá-lo à parte em "Remover cliente".
        $this->intervencao_id = null;
        $this->intervencaoRotulo = '';
        $this->equipamento_id = null;
        $this->contrato_id = null;
    }

    // Rótulo legível de uma intervenção: nº do relatório (se já tiver), senão "Intervenção #id",
    // + equipamento + cliente + data.
    private function rotuloIntervencao(Intervencao $intervencao): string
    {
        $partes = [$intervencao->relatorio?->numero
            ? 'Relatório ' . $intervencao->relatorio->numero
            : 'Intervenção #' . $intervencao->id];

        if ($sn = $intervencao->equipamento?->numero_serie) {
            $partes[] = $sn;
        }
        if ($nome = $intervencao->equipamento?->local?->cliente?->nome) {
            $partes[] = $nome;
        }
        if ($data = $intervencao->data_inicio) {
            $partes[] = $data->format('d/m/Y');
        }

        return implode(' · ', $partes);
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
            'intervencao_id' => ['nullable', 'integer', 'exists:intervencoes,id'],
        ]);

        // Categoria fica guardada para reutilização futura (lookup que cresce com o uso);
        // o valor gravado é a forma canónica da lookup.
        $categoria = CategoriaDespesa::firstOrCreate(
            ['nome_normalizado' => CategoriaDespesa::normalizar($this->categoria)],
            ['nome' => trim($this->categoria)],
        );
        $dados['categoria'] = $categoria->nome;

        // Ligações. Com intervenção associada, o cliente/equipamento/contrato são HERDADOS dela
        // (fonte da verdade, re-lida no servidor — não se confia no que vem do cliente). Sem
        // intervenção, liga-se apenas ao cliente escolhido à mão.
        if ($this->intervencao_id) {
            $intervencao = Intervencao::with('equipamento.local')->findOrFail($this->intervencao_id);
            $dados['intervencao_id'] = $intervencao->id;
            $dados['equipamento_id'] = $intervencao->equipamento_id;
            $dados['contrato_id'] = $intervencao->contrato_id;
            $dados['cliente_id'] = $intervencao->equipamento?->local?->cliente_id;
        } else {
            $dados['intervencao_id'] = null;
            $dados['equipamento_id'] = null;
            $dados['contrato_id'] = null;
            $dados['cliente_id'] = $this->cliente_id;
        }

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

    // Pesquisa GLOBAL de intervenções por nº de relatório, nº de série do equipamento ou nome do
    // cliente (sem acentos). whereHas evita duplicar linhas dos joins. Vazia sem texto; limitada.
    private function intervencoesFiltradas(): Collection
    {
        $busca = trim($this->intervencaoBusca);
        if ($busca === '') {
            return collect();
        }

        $termo = '%' . $busca . '%';
        $nomeNorm = '%' . $this->normalizarBusca($busca) . '%';

        return Intervencao::query()
            ->with(['equipamento.local.cliente', 'relatorio'])
            ->where(function ($q) use ($termo, $nomeNorm) {
                $q->whereHas('relatorio', fn ($r) => $r->where('numero', 'ilike', $termo))
                    ->orWhereHas('equipamento', fn ($e) => $e->where('numero_serie', 'ilike', $termo))
                    ->orWhereHas('equipamento.local.cliente', fn ($c) => $c->whereRaw(self::NOME_SEM_ACENTOS . ' like ?', [$nomeNorm]));
            })
            ->orderByDesc('data_inicio')
            ->limit(20)
            ->get();
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
            'intervencoesFiltradas' => $this->intervencoesFiltradas(),
        ]);
    }
}
