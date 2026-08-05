<?php

namespace App\Livewire\Concerns;

use App\Models\Artigo;
use Illuminate\Support\Collection;

// Pesquisa no catálogo de artigos do PHC (tabela local `artigos`, sincronizada — o ERP nunca
// está no caminho do pedido) para compor a lista de componentes de um sistema. O componente
// que usa o trait tem de ter a prop pública `componentes` (lista { designacao, quantidade }).
trait ComponentesComArtigos
{
    public string $artigoBusca = '';

    // Adiciona uma linha de componente a partir de um artigo do catálogo: a designação fica
    // "REF — designação" (a referência viaja no texto — pesquisável e visível no PDF).
    public function adicionarComponenteArtigo(int $id): void
    {
        $artigo = Artigo::find($id);
        if (! $artigo) {
            return;
        }

        $designacao = filled($artigo->designacao)
            ? $artigo->id_erp . ' — ' . $artigo->designacao
            : $artigo->id_erp;
        $this->componentes[] = ['designacao' => $designacao, 'quantidade' => 1];
        $this->artigoBusca = '';
    }

    // Pesquisa por referência OU designação (case-insensitive), limitada a 20 resultados.
    protected function artigosFiltrados(): Collection
    {
        if (trim($this->artigoBusca) === '') {
            return collect();
        }

        $termo = '%' . trim($this->artigoBusca) . '%';

        return Artigo::query()
            ->where(fn ($q) => $q->where('id_erp', 'ilike', $termo)
                ->orWhere('designacao', 'ilike', $termo))
            ->orderBy('id_erp')
            ->limit(20)
            ->get(['id', 'id_erp', 'designacao', 'faminome']);
    }
}
