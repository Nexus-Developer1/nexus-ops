<div>
    <x-topbar :breadcrumb="['Ativos', $equipamento ? $equipamento->numero_serie : 'Novo Ativo']">
        <a href="{{ $equipamento ? route('equipamentos.ficha', $equipamento) : route('ativos') }}" class="botao-secundario">Cancelar</a>
        <button wire:click="guardar" class="botao-primario">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Guardar
        </button>
    </x-topbar>

    <main class="flex-1 px-10 py-9">
        <div class="mx-auto max-w-4xl">

            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $equipamento ? 'Editar equipamento' : 'Novo equipamento' }}</h1>
            <p class="mt-2 text-sm text-texto-medio">Registo do ativo e das suas especificações técnicas.</p>

            {{-- Dados gerais --}}
            <section class="cartao mt-8">
                <div class="flex items-center gap-3 px-6 py-5">
                    <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3"/></svg></span>
                    <h2 class="text-lg font-semibold text-texto-forte">Dados Gerais</h2>
                </div>
                <div class="grid grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">
                    <div class="col-span-2">
                        <label class="campo-label">Local</label>
                        <select wire:model="local_id" class="campo-select">
                            <option value="">Selecione o local...</option>
                            @foreach ($locais as $local)
                                <option value="{{ $local->id }}">{{ $local->cliente->nome }} · {{ $local->designacao }}</option>
                            @endforeach
                        </select>
                        @error('local_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Tipo de ativo</label>
                        <select wire:model.live="tipo" class="campo-select">
                            @foreach ($tipos as $t)
                                <option value="{{ $t->value }}">{{ $t->rotulo() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="campo-label">Estado</label>
                        <select wire:model="estado" class="campo-select">
                            @foreach ($estados as $e)
                                <option value="{{ $e->value }}">{{ $e->rotulo() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="campo-label">Nº de série</label>
                        <input wire:model="numero_serie" type="text" class="campo-input" placeholder="Ex: 4839-22-AX">
                        @error('numero_serie') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label">Fabricante</label>
                        <input wire:model="fabricante" type="text" class="campo-input" placeholder="Ex: Eaton">
                    </div>
                    <div>
                        <label class="campo-label">Modelo</label>
                        <input wire:model="modelo" type="text" class="campo-input" placeholder="Ex: 93PM">
                    </div>
                    <div></div>
                    <div>
                        <label class="campo-label">Data de instalação</label>
                        <input wire:model="data_instalacao" type="date" class="campo-input">
                    </div>
                    <div>
                        <label class="campo-label">Fim de garantia</label>
                        <input wire:model="fim_garantia" type="date" class="campo-input">
                    </div>
                </div>
            </section>

            {{-- Especificações por tipo --}}
            <section class="cartao mt-5">
                <div class="flex items-center gap-3 px-6 py-5">
                    <span class="cartao-icone"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                    <h2 class="text-lg font-semibold text-texto-forte">Especificações</h2>
                </div>
                <div class="grid grid-cols-2 gap-x-8 gap-y-6 border-t border-borda px-6 py-6">

                    @if ($tipo === 'ups')
                        <div><label class="campo-label">Potência (kVA)</label><input wire:model="atributos.potencia_kva" type="number" class="campo-input" placeholder="Ex: 40"></div>
                        <div>
                            <label class="campo-label">Topologia</label>
                            <select wire:model="atributos.topologia" class="campo-select">
                                <option value="">—</option>
                                <option value="Online dupla conversão">Online dupla conversão</option>
                                <option value="Line-interactive">Line-interactive</option>
                                <option value="Standby">Standby</option>
                            </select>
                        </div>
                        <div><label class="campo-label">Autonomia (min)</label><input wire:model="atributos.autonomia_min" type="number" class="campo-input" placeholder="Ex: 12"></div>
                        <div><label class="campo-label">Nº de baterias</label><input wire:model="atributos.num_baterias" type="number" class="campo-input" placeholder="Ex: 40"></div>
                        <div><label class="campo-label">Data das baterias</label><input wire:model="atributos.data_baterias" type="date" class="campo-input"></div>
                        <div><label class="campo-label">Firmware</label><input wire:model="atributos.firmware" type="text" class="campo-input" placeholder="Ex: v2.14.0"></div>
                        <div><label class="campo-label">Próxima troca de baterias</label><input wire:model="proxima_troca_baterias" type="date" class="campo-input"></div>
                    @elseif ($tipo === 'gerador')
                        <div><label class="campo-label">Potência (kVA)</label><input wire:model="atributos.potencia_kva" type="number" class="campo-input" placeholder="Ex: 220"></div>
                        <div><label class="campo-label">Combustível</label><input wire:model="atributos.combustivel" type="text" class="campo-input" placeholder="Ex: Diesel"></div>
                        <div><label class="campo-label">Horas de funcionamento</label><input wire:model="atributos.horas_funcionamento" type="number" class="campo-input" placeholder="Ex: 1320"></div>
                    @elseif ($tipo === 'pdu')
                        <div><label class="campo-label">Nº de tomadas</label><input wire:model="atributos.num_tomadas" type="number" class="campo-input" placeholder="Ex: 24"></div>
                        <div><label class="campo-label">Corrente (A)</label><input wire:model="atributos.corrente_a" type="number" class="campo-input" placeholder="Ex: 32"></div>
                    @endif

                </div>
            </section>

        </div>
    </main>
</div>
