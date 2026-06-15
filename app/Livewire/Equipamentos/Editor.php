<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Models\Equipamento;
use App\Models\Local;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'ativos'])]
class Editor extends Component
{
    public ?Equipamento $equipamento = null;

    // Campos comuns
    public $local_id = '';
    public string $tipo = 'ups';
    public string $fabricante = '';
    public string $modelo = '';
    public string $numero_serie = '';
    public ?string $data_instalacao = null;
    public ?string $fim_garantia = null;
    public string $estado = 'operacional';
    public ?string $proxima_troca_baterias = null;

    // Atributos específicos do tipo (JSONB)
    public array $atributos = [];

    public function mount(?Equipamento $equipamento = null): void
    {
        if ($equipamento && $equipamento->exists) {
            $this->equipamento = $equipamento;
            $this->local_id = $equipamento->local_id;
            $this->tipo = $equipamento->tipo->value;
            $this->fabricante = $equipamento->fabricante ?? '';
            $this->modelo = $equipamento->modelo ?? '';
            $this->numero_serie = $equipamento->numero_serie ?? '';
            $this->data_instalacao = $equipamento->data_instalacao?->format('Y-m-d');
            $this->fim_garantia = $equipamento->fim_garantia?->format('Y-m-d');
            $this->estado = $equipamento->estado->value;
            $this->proxima_troca_baterias = $equipamento->proxima_troca_baterias?->format('Y-m-d');
            $this->atributos = $equipamento->atributos ?? [];
        }
    }

    public function guardar()
    {
        $validado = $this->validate([
            'local_id' => ['required', 'exists:locais,id'],
            'tipo' => ['required', Rule::enum(TipoEquipamento::class)],
            'fabricante' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'numero_serie' => ['required', 'string', 'max:255', Rule::unique('equipamentos', 'numero_serie')->ignore($this->equipamento)],
            'data_instalacao' => ['nullable', 'date'],
            'fim_garantia' => ['nullable', 'date'],
            'estado' => ['required', Rule::enum(EstadoEquipamento::class)],
            'proxima_troca_baterias' => ['nullable', 'date'],
        ]);

        $validado['proxima_troca_baterias'] = $this->tipo === 'ups' ? ($this->proxima_troca_baterias ?: null) : null;
        $validado['atributos'] = array_filter(
            $this->atributos,
            fn ($v) => $v !== '' && $v !== null,
        );

        if ($this->equipamento) {
            $this->equipamento->update($validado);
        } else {
            $this->equipamento = Equipamento::create($validado);
        }

        session()->flash('sucesso', 'Equipamento guardado com sucesso.');

        return redirect()->route('equipamentos.ficha', $this->equipamento);
    }

    public function render()
    {
        return view('livewire.equipamentos.editor', [
            'locais' => Local::with('cliente')->get(),
            'tipos' => TipoEquipamento::cases(),
            'estados' => EstadoEquipamento::cases(),
        ]);
    }
}
