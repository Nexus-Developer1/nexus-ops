{{-- Módulos de potência (até 4). Os "Bancos de bateria" saíram do formulário a pedido
     da equipa — os bancos registam-se na ficha do EQUIPAMENTO; fichas antigas com
     bancos preenchidos continuam a mostrá-los no PDF. --}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div>
        <p class="campo-label">Módulos de potência</p>
        <div class="space-y-2">
            @for ($i = 0; $i < \App\Models\FichaMedicao::MAX_LINHAS; $i++)
                <div class="grid grid-cols-2 gap-2">
                    <input type="text" wire:model="{{ $prefixo }}.modulos.{{ $i }}.modelo" class="campo-input" placeholder="Modelo">
                    <input type="text" wire:model="{{ $prefixo }}.modulos.{{ $i }}.sn" class="campo-input" placeholder="N/S">
                </div>
            @endfor
        </div>
    </div>
</div>
