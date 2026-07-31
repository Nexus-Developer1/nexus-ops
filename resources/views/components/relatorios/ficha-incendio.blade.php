@props([
    'prefixo',              // caminho Livewire da ficha, ex.: "fichas.123"
    'assinaturas' => [],    // ['cliente' => url|null, 'tecnico' => url|null] das já gravadas
])

@php
    use App\Models\FichaMedicao;

    // Secções OK/KO(/N\A) com nota por item (rótulo da secção → [itens, com N\A?]).
    $seccoes = [
        'Central de deteção e extinção de incêndio' => ['chave' => 'central', 'itens' => FichaMedicao::SADEI_CENTRAL, 'na' => false],
        'Sistema de deteção' => ['chave' => 'detecao', 'itens' => FichaMedicao::SADEI_DETECAO, 'na' => true],
        'Sistema de aspiração' => ['chave' => 'aspiracao', 'itens' => FichaMedicao::SADEI_ASPIRACAO, 'na' => true],
        'Sistema por sensores' => ['chave' => 'sensores', 'itens' => FichaMedicao::SADEI_SENSORES, 'na' => true],
    ];

    // Listas periódicas (só estado OK/KO/N\A).
    $periodicas = [
        'Verificação trimestral' => ['chave' => 'trimestral', 'itens' => FichaMedicao::SADEI_TRIMESTRAL],
        'Verificação semestral' => ['chave' => 'semestral', 'itens' => FichaMedicao::SADEI_SEMESTRAL],
        'Verificação anual' => ['chave' => 'anual', 'itens' => FichaMedicao::SADEI_ANUAL],
    ];
@endphp

{{-- Ficha de Verificações SADEI (deteção/extinção de incêndio) — espelho da folha oficial. --}}
<div class="space-y-6" wire:key="ficha-sadei-{{ $prefixo }}">

    {{-- Identificação do equipamento (colunas partilhadas com a ficha UPS) --}}
    <div class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        <div><label class="campo-label">Marca</label><input type="text" wire:model="{{ $prefixo }}.marca" class="campo-input"></div>
        <div><label class="campo-label">Modelo</label><input type="text" wire:model="{{ $prefixo }}.modelo" class="campo-input"></div>
        <div><label class="campo-label">Nº de série</label><input type="text" wire:model="{{ $prefixo }}.serie" class="campo-input"></div>
        <div><label class="campo-label">Baterias</label><input type="text" wire:model="{{ $prefixo }}.baterias" class="campo-input"></div>
    </div>

    {{-- Tipo de manutenção --}}
    <div>
        <p class="campo-label">Tipo de manutenção</p>
        <div class="flex flex-wrap gap-2">
            @foreach (['trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual'] as $valor => $rotulo)
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-borda px-3 py-2 text-sm text-texto-medio transition hover:bg-fundo">
                    <input type="radio" wire:model="{{ $prefixo }}.sadei.tipo_manutencao" value="{{ $valor }}" class="h-4 w-4 border-borda text-verde-600 focus:ring-verde-600">
                    {{ $rotulo }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- Secções com estado + nota por item --}}
    @foreach ($seccoes as $tituloSec => $sec)
        <div>
            <p class="campo-label">{{ $tituloSec }}</p>
            <div class="space-y-2">
                @foreach ($sec['itens'] as $k => $rotulo)
                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-12" wire:key="sadei-{{ $sec['chave'] }}-{{ $k }}-{{ $prefixo }}">
                        <span class="text-sm text-texto-forte sm:col-span-4">{{ $rotulo }}</span>
                        <div class="flex items-center gap-3 sm:col-span-3">
                            @foreach (array_filter(['ok' => 'OK', 'ko' => 'KO', 'na' => $sec['na'] ? 'N/A' : null]) as $ev => $er)
                                <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-texto-medio">
                                    <input type="radio" wire:model="{{ $prefixo }}.sadei.{{ $sec['chave'] }}.{{ $k }}.estado" value="{{ $ev }}" class="h-3.5 w-3.5 border-borda text-verde-600 focus:ring-verde-600">
                                    {{ $er }}
                                </label>
                            @endforeach
                        </div>
                        <input type="text" wire:model="{{ $prefixo }}.sadei.{{ $sec['chave'] }}.{{ $k }}.nota" class="campo-input sm:col-span-5" placeholder="Notas">
                    </div>
                @endforeach
                @if ($sec['chave'] === 'sensores')
                    <div class="grid grid-cols-1 sm:grid-cols-12">
                        <div class="sm:col-span-4"><label class="campo-label">Número de sensores</label></div>
                        <input type="text" wire:model="{{ $prefixo }}.sadei.num_sensores" class="campo-input sm:col-span-3" placeholder="Ex: 12">
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    {{-- Verificações periódicas (estado apenas) --}}
    @foreach ($periodicas as $tituloSec => $sec)
        <div>
            <div class="flex items-center justify-between">
                <p class="campo-label">{{ $tituloSec }}</p>
                <span class="text-xs text-aviso-500">Inibir o sistema antes de iniciar · repor em automático no fim</span>
            </div>
            <div class="space-y-2">
                @foreach ($sec['itens'] as $k => $rotulo)
                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-12" wire:key="sadei-{{ $sec['chave'] }}-{{ $k }}-{{ $prefixo }}">
                        <span class="text-sm text-texto-forte sm:col-span-9">{{ $rotulo }}</span>
                        <div class="flex items-center gap-3 sm:col-span-3">
                            @foreach (['ok' => 'OK', 'ko' => 'KO', 'na' => 'N/A'] as $ev => $er)
                                <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-texto-medio">
                                    <input type="radio" wire:model="{{ $prefixo }}.sadei.{{ $sec['chave'] }}.{{ $k }}.estado" value="{{ $ev }}" class="h-3.5 w-3.5 border-borda text-verde-600 focus:ring-verde-600">
                                    {{ $er }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Dados dos cilindros --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach (['cilindros' => ['Cilindros — agente extintor', 'tipo_agente', 'Tipo de agente extintor', FichaMedicao::SADEI_CILINDROS_LINHAS], 'piloto' => ['Cilindros — piloto', 'tipo_piloto', 'Tipo de piloto', FichaMedicao::SADEI_PILOTO_LINHAS]] as $grelha => [$tituloGrelha, $campoTipo, $rotuloTipo, $numLinhas])
            <div>
                <p class="campo-label">{{ $tituloGrelha }}</p>
                <input type="text" wire:model="{{ $prefixo }}.sadei.{{ $campoTipo }}" class="campo-input mb-2" placeholder="{{ $rotuloTipo }}">
                <div class="space-y-2">
                    @for ($i = 0; $i < $numLinhas; $i++)
                        <div class="grid grid-cols-6 gap-1.5" wire:key="sadei-{{ $grelha }}-{{ $i }}-{{ $prefixo }}">
                            @foreach (FichaMedicao::SADEI_COLS_CILINDRO as $col => $ph)
                                <input type="text" wire:model="{{ $prefixo }}.sadei.{{ $grelha }}.{{ $i }}.{{ $col }}" class="campo-input px-2 text-xs" placeholder="{{ $ph }}">
                            @endforeach
                            <select wire:model="{{ $prefixo }}.sadei.{{ $grelha }}.{{ $i }}.estado" class="campo-select px-2 text-xs">
                                <option value="">—</option>
                                <option value="ok">OK</option>
                                <option value="ko">KO</option>
                            </select>
                        </div>
                    @endfor
                </div>
            </div>
        @endforeach
    </div>

    {{-- Relatório final --}}
    <div>
        <p class="campo-label">Relatório final</p>
        <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-12">
            <span class="text-sm text-texto-forte sm:col-span-9">Equipamento em modo automático, com a solenoide colocada, e a funcionar corretamente</span>
            <div class="flex items-center gap-3 sm:col-span-3">
                @foreach (['ok' => 'OK', 'ko' => 'KO'] as $ev => $er)
                    <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-texto-medio">
                        <input type="radio" wire:model="{{ $prefixo }}.sadei.final_automatico" value="{{ $ev }}" class="h-3.5 w-3.5 border-borda text-verde-600 focus:ring-verde-600">
                        {{ $er }}
                    </label>
                @endforeach
            </div>
        </div>
        <div class="mt-3">
            <label class="campo-label">Notas</label>
            <textarea wire:model="{{ $prefixo }}.notas_finais" rows="3" class="campo-input"></textarea>
        </div>
    </div>

    {{-- Recomendações e próximos passos (igual à ficha UPS — alimenta o PDF e os alertas) --}}
    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <label class="campo-label">Recomendações e próximos passos</label>
            <textarea wire:model="{{ $prefixo }}.recomendacao" rows="3" class="campo-input"></textarea>
        </div>
        <div>
            <label class="campo-label">Prioridade</label>
            <select wire:model="{{ $prefixo }}.prioridade" class="campo-select">
                <option value="Baixa">Baixa</option>
                <option value="Normal">Normal</option>
                <option value="Alta">Alta</option>
            </select>
        </div>
    </div>

    {{-- Assinaturas no local (obrigatórias nesta folha): cliente e técnico. --}}
    <div>
        <p class="campo-label">Assinaturas</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-relatorios.assinatura :prefixo="$prefixo" quem="cliente" rotulo="Assinatura do cliente" :guardada="$assinaturas['cliente'] ?? null" />
            <x-relatorios.assinatura :prefixo="$prefixo" quem="tecnico" rotulo="Assinatura do técnico" :guardada="$assinaturas['tecnico'] ?? null" />
        </div>
        <p class="mt-1.5 text-xs text-texto-fraco">Assine com a caneta (ou o dedo) diretamente no retângulo. As assinaturas saem no PDF do relatório.</p>
    </div>
</div>
