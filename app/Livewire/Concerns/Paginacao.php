<?php

namespace App\Livewire\Concerns;

// Barra de páginas da casa em TODAS as listagens (set. 2026).
//
// A que vem com o Livewire saía sem forma nenhuma: o Tailwind só gera as classes que
// encontra em resources/ e app/ (ver `content` no tailwind.config.js) e as dela vivem em
// vendor/ — ficavam números sem botão, sem caixa e sem cor no fundo das listagens grandes
// (equipamentos: 18 000 registos; clientes: 3 000; dossiers: 200 000).
//
// O Livewire pergunta ao componente por este método antes de usar o tema dele, por isso
// basta acrescentar o trait a quem usa WithPagination.
trait Paginacao
{
    public function paginationView(): string
    {
        return 'components.paginacao';
    }

    public function paginationSimpleView(): string
    {
        return 'components.paginacao';
    }
}
