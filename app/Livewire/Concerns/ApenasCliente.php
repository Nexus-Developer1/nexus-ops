<?php

namespace App\Livewire\Concerns;

// Componentes do PORTAL do cliente. Como o ApenasEquipa, o boot{Trait} corre em TODAS as
// requisições ao componente (mount e cada update em /livewire/update, onde o middleware da rota
// não volta a correr). Só entra quem é cliente COM cliente associado: o isolamento por cliente
// (RestritoAoCliente) só filtra o papel `cliente` — um utilizador da equipa ou do financeiro que
// reutilizasse o estado de um componente do portal via /livewire/update veria os dados de todos
// os clientes (27.ª revisão de segurança). Fail-closed.
trait ApenasCliente
{
    public function bootApenasCliente(): void
    {
        $utilizador = auth()->user();

        abort_unless($utilizador?->ehCliente() && $utilizador->cliente_id !== null, 403);
    }
}
