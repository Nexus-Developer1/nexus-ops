<?php

namespace App\Livewire\Concerns;

// Componentes exclusivos da equipa interna (admin/técnico). O hook boot{Trait} do Livewire corre
// em TODAS as requisições ao componente — no load inicial (mount) E em cada update/chamada de
// método (hydrate). Assim o papel `cliente` é bloqueado de forma UNIFORME, sem depender de cada
// método lembrar-se de chamar abort_if. É defesa em profundidade: o middleware `papel:admin,tecnico`
// da rota já barra o cliente; isto protege contra regressões (ex.: componente embebido noutra vista).
trait ApenasEquipa
{
    public function bootApenasEquipa(): void
    {
        // Fail-closed: sem utilizador (null) também aborta.
        abort_if(auth()->user()?->ehCliente() ?? true, 403);
    }
}
