<div>
    <x-topbar :breadcrumb="['Equipamentos', 'Editar equipamento']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <form wire:submit="guardar" class="mx-auto max-w-3xl">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">Editar equipamento</h1>
                <div class="flex items-center gap-3">
                    <a href="{{ route('ativos') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" wire:loading.attr="disabled" wire:target="guardar" class="botao-primario">Guardar alterações</button>
                </div>
            </div>

            <section class="cartao mt-8">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-texto-forte">Dados do equipamento</h2>
                        <p class="mt-1 text-sm text-texto-medio">{{ $equipamento->local?->cliente?->nome ?? 'Sem cliente — por associar' }}</p>
                    </div>
                    <a href="{{ route('equipamentos.ficha', $equipamento) }}" wire:navigate class="botao-secundario">Ver ficha</a>
                </div>

                @if (! $manual)
                    <p class="border-t border-borda px-6 py-4 text-sm text-texto-medio">Fabricante, modelo, número de série, família e data de instalação são dados importados do PHC e estão disponíveis apenas para consulta.</p>
                @endif

                <div class="grid grid-cols-1 gap-6 border-t border-borda px-6 py-6 sm:grid-cols-2">
                    <div>
                        <label for="editar-tipo" class="campo-label">Tipo <span class="text-perigo-500">*</span></label>
                        <select id="editar-tipo" wire:model.live="form.tipo" class="campo-select">
                            @foreach ($tipos as $tipo)
                                <option value="{{ $tipo->value }}">{{ $tipo->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('form.tipo') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="editar-estado" class="campo-label">Estado <span class="text-perigo-500">*</span></label>
                        <select id="editar-estado" wire:model="form.estado" class="campo-select">
                            @foreach ($estados as $estado)
                                <option value="{{ $estado->value }}">{{ $estado->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('form.estado') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    @if ($form['tipo'] === 'diversos')
                        <div class="sm:col-span-2">
                            <label for="editar-descricao" class="campo-label">Descrição da solução <span class="text-perigo-500">*</span></label>
                            <input id="editar-descricao" wire:model="form.tipo_descricao" type="text" maxlength="255" class="campo-input">
                            @error('form.tipo_descricao') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @foreach (['fabricante' => 'Fabricante', 'modelo' => 'Modelo', 'numero_serie' => 'Nº de série'] as $campo => $rotulo)
                        <div>
                            <label for="editar-{{ $campo }}" class="campo-label">{{ $rotulo }}</label>
                            <input id="editar-{{ $campo }}" wire:model="form.{{ $campo }}" type="text" maxlength="255" class="campo-input disabled:bg-fundo disabled:text-texto-medio" @disabled(! $manual)>
                            @error('form.'.$campo) <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="editar-familia" class="campo-label">Família</label>
                        <select id="editar-familia" wire:model="form.familia" class="campo-select disabled:bg-fundo disabled:text-texto-medio" @disabled(! $manual)>
                            <option value="">Sem família</option>
                            @if ($equipamento->familia && ! $familias->contains('familia', $equipamento->familia))
                                <option value="{{ $equipamento->familia }}">{{ $equipamento->faminome ?? $equipamento->familia }}</option>
                            @endif
                            @foreach ($familias as $familia)
                                <option value="{{ $familia->familia }}">{{ $familia->faminome }}</option>
                            @endforeach
                        </select>
                        @error('form.familia') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    @foreach (['data_instalacao' => 'Data de instalação', 'fim_garantia' => 'Fim de garantia'] as $campo => $rotulo)
                        <div>
                            <label for="editar-{{ $campo }}" class="campo-label">{{ $rotulo }}</label>
                            <input id="editar-{{ $campo }}" wire:model="form.{{ $campo }}" type="date" class="campo-input disabled:bg-fundo disabled:text-texto-medio" @disabled(! $manual && $campo === 'data_instalacao')>
                            @error('form.'.$campo) <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    @foreach (['cliente_final' => 'Cliente final', 'localizacao_instalacao' => 'Localização da instalação'] as $campo => $rotulo)
                        <div>
                            <label for="editar-{{ $campo }}" class="campo-label">{{ $rotulo }}</label>
                            <input id="editar-{{ $campo }}" wire:model="form.{{ $campo }}" type="text" maxlength="255" class="campo-input">
                            @error('form.'.$campo) <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div class="sm:col-span-2">
                        <label for="editar-notas" class="campo-label">Notas</label>
                        <textarea id="editar-notas" wire:model="form.notas" rows="4" maxlength="5000" class="campo-input"></textarea>
                        @error('form.notas') <p class="mt-1 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
        </form>
    </main>
</div>
