<?php

namespace App\Livewire\Portal;

use App\Enums\EstadoRelatorio;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

// Os relatórios do cliente autenticado (só leitura + download do PDF).
// SÓ ENVIADOS: rascunhos e finalizados são trabalho interno — desde que os enviados
// passaram a ser editáveis (reabrem o ciclo), sem este filtro o cliente veria versões
// a meio da edição como se fossem o documento oficial (11.ª revisão de segurança).
#[Layout('components.layouts.portal', ['ativo' => 'relatorios', 'titulo' => 'Relatórios'])]
class Relatorios extends Component
{
    use WithPagination;

    public function render()
    {
        $relatorios = Relatorio::query()
            ->where('estado', EstadoRelatorio::Enviado)
            ->with('intervencao.equipamento.local')
            ->orderByDesc('data')
            ->paginate(10);

        return view('livewire.portal.relatorios', ['relatorios' => $relatorios]);
    }
}
