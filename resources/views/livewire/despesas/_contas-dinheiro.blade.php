{{-- Contas do dinheiro levantado (editor e ficha): levantado, gasto em dinheiro (linhas
     «Dinheiro levantado») e o saldo — sobra a devolver, ou gastou-se mais do que se levantou. --}}
@if ($contas)
    @php($eur = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €')
    <dl class="mt-4 grid grid-cols-3 gap-3 rounded-lg border border-borda bg-fundo px-4 py-3 text-sm">
        <div>
            <dt class="text-xs uppercase tracking-wide text-texto-fraco">Levantado</dt>
            <dd class="mt-0.5 font-semibold text-texto-forte">{{ $eur($contas['levantado']) }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-texto-fraco">Gasto em dinheiro</dt>
            <dd class="mt-0.5 font-semibold text-texto-forte">{{ $eur($contas['gasto']) }}</dd>
        </div>
        <div>
            @if ($contas['saldo'] >= 0)
                <dt class="text-xs uppercase tracking-wide text-texto-fraco">Sobra (a devolver)</dt>
                <dd class="mt-0.5 font-semibold text-verde-700">{{ $eur($contas['saldo']) }}</dd>
            @else
                <dt class="text-xs uppercase tracking-wide text-texto-fraco">Gasto a mais</dt>
                <dd class="mt-0.5 font-semibold text-aviso-500">{{ $eur(-$contas['saldo']) }}</dd>
            @endif
        </div>
    </dl>
    @if ($contas['saldo'] < 0)
        <p class="mt-1.5 text-xs text-aviso-500">
            {{ $contas['levantado'] > 0 ? 'Gastou-se em dinheiro mais do que foi levantado — confirme os valores ou se falta registar um levantamento.' : 'Há despesas pagas com dinheiro levantado, mas nenhum levantamento registado.' }}
        </p>
    @endif
@endif
