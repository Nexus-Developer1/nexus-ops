<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

// Listagens que se lembram da PÁGINA, como já se lembram dos filtros (pedido da equipa, out.
// 2026): abrir um registo na página 13 e carregar em «Voltar» (que vem sem ?page) levava à
// página 1. A página fica na sessão por listagem (e por cliente, nos separadores da ficha do
// cliente); um ?page explícito no endereço manda sempre. Mudar um filtro continua a voltar à 1
// (resetPage de cada componente); uma página que deixou de existir cai na última.
// Usar com WithPagination, e paginar com $this->paginarLembrando($consulta, $porPagina).
trait LembraPagina
{
    // Corre depois do mount() do componente (Livewire chama os mount dos traits a seguir), por
    // isso o cliente dos separadores já está definido.
    public function mountLembraPagina(): void
    {
        if (! request()->has('page') && ($pagina = (int) session($this->chavePagina(), 1)) > 1) {
            $this->setPage($pagina);
        }
    }

    protected function paginarLembrando(Builder $consulta, int $porPagina): LengthAwarePaginator
    {
        $pagina = (clone $consulta)->paginate($porPagina);

        if ($pagina->isEmpty() && $pagina->currentPage() > 1) {
            $this->setPage($pagina->lastPage());
            $pagina = (clone $consulta)->paginate($porPagina);
        }
        session([$this->chavePagina() => $pagina->currentPage()]);

        return $pagina;
    }

    private function chavePagina(): string
    {
        $cliente = property_exists($this, 'cliente') && is_object($this->cliente ?? null) ? ':'.$this->cliente->getKey() : '';

        return 'pagina.'.static::class.$cliente;
    }
}
