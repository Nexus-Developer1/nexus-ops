<?php

namespace App\Livewire\Equipamentos;

use App\Models\Equipamento;
use App\Models\Local;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Associa um equipamento EXISTENTE a um local (atualiza local_id). Os equipamentos
// vêm do import e não são criados/editados na app — aqui só se define o seu local.
#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Associar equipamento'])]
class Associar extends Component
{
    public ?int $equipamento_id = null;
    public ?int $local_id = null;

    // Veio da ficha de um equipamento → equipamento fixo, escolhe-se só o local.
    public bool $equipamentoFixo = false;

    public function mount(?Equipamento $equipamento = null): void
    {
        if ($equipamento && $equipamento->exists) {
            $this->equipamento_id = $equipamento->id;
            $this->local_id = $equipamento->local_id;
            $this->equipamentoFixo = true;
        }
    }

    public function guardar()
    {
        $validado = $this->validate([
            'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
            'local_id' => ['required', 'integer', 'exists:locais,id'],
        ]);

        $equipamento = Equipamento::findOrFail($validado['equipamento_id']);
        $equipamento->update(['local_id' => $validado['local_id']]);

        session()->flash('sucesso', 'Equipamento associado ao local com sucesso.');

        return redirect()->route('equipamentos.ficha', $equipamento);
    }

    public function render()
    {
        return view('livewire.equipamentos.associar', [
            'equipamentos' => Equipamento::with('local.cliente')->orderBy('numero_serie')->get(),
            'locais' => Local::with('cliente')->orderBy('designacao')->get(),
        ]);
    }
}
