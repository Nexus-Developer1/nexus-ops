<?php

namespace App\Livewire\Concerns;

// Componentes do módulo de DESPESAS: equipa interna (admin/técnico) mais o papel `financeiro`,
// que existe só para isto — vê e trata das despesas, e não entra em mais nada da aplicação
// (set. 2026). O cliente fica sempre de fora.
//
// Vive à parte do ApenasEquipa (que barra o financeiro) porque é o único sítio onde os dois
// mundos se cruzam. Como o boot{Trait} do Livewire corre em TODAS as requisições ao componente
// — no arranque e em cada chamada de método — a regra aplica-se de forma uniforme, sem depender
// de cada método se lembrar de a verificar.
trait AcessoDespesas
{
    public function bootAcessoDespesas(): void
    {
        $utilizador = auth()->user();

        // Fail-closed: sem utilizador (null) também aborta.
        abort_unless($utilizador && ! $utilizador->ehCliente(), 403);
    }
}
