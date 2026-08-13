<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\LinhaFatura;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Detalhe de uma fatura (documento do PHC): a partir de uma linha clicada, mostra o
// cabeçalho e TODAS as linhas do mesmo documento (mesmo cliente_no + nmdoc + fno).
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Fatura'])]
class Fatura extends Component
{
    use ApenasEquipa;

    public Cliente $cliente;

    public LinhaFatura $linha;

    public function mount(Cliente $cliente, LinhaFatura $linha): void
    {
        // A linha tem de pertencer a este cliente (senão o breadcrumb estaria errado).
        abort_unless((string) $linha->cliente_no === (string) $cliente->id_erp, 404);

        $this->cliente = $cliente;
        $this->linha = $linha;
    }

    public function render()
    {
        // Todas as linhas do MESMO documento (fatura), pela ordem em que foram lançadas.
        // A chave inclui a DATA: todas as linhas de um documento partilham-na, por isso
        // isto nunca parte um documento real — só evita juntar duas "Fatura 3314" de anos
        // diferentes (o fno do PHC reinicia anualmente).
        $linhas = LinhaFatura::query()
            ->where('cliente_no', $this->linha->cliente_no)
            ->where('nmdoc', $this->linha->nmdoc)
            ->where('fno', $this->linha->fno)
            ->when(
                $this->linha->data !== null,
                fn ($q) => $q->whereDate('data', $this->linha->data),
                fn ($q) => $q->whereNull('data'),
            )
            ->orderBy('id')
            ->get();

        return view('livewire.clientes.fatura', [
            'linhas' => $linhas,
        ]);
    }
}
