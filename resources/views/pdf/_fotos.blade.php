{{-- Grelha de fotografias em TABELA (3 por linha, maiores). O dompdf não lida bem com sequências
     de imagens inline — sobrepunham-se verticalmente à secção anterior; tabelas são o layout
     fiável. Uma tabela por linha para quebras de página limpas entre linhas de fotos.
     $titulo (opcional) sai colado à PRIMEIRA linha (page-break-inside: avoid) — sem isto o
     título ficava órfão no fundo de uma página e as fotos na seguinte. $h2 = título em <h2>
     (secções fora das fichas) em vez de barra de secção da ficha. --}}
@foreach (array_chunk($fotos, 3) as $n => $linhaFotos)
    <div @class(['junto' => $n === 0])>
        @if ($n === 0 && ($titulo ?? '') !== '')
            @if ($h2 ?? false)<h2>{{ $titulo }}</h2>@else<div class="ficha-seccao">{{ $titulo }}</div>@endif
        @endif
        <table class="fotos-tab">
            <tr>
                @foreach ($linhaFotos as $foto)
                    <td class="foto-cel"><img src="{{ $foto }}" class="foto"></td>
                @endforeach
                @for ($vazias = count($linhaFotos); $vazias < 3; $vazias++)
                    <td class="foto-cel"></td>
                @endfor
            </tr>
        </table>
    </div>
@endforeach
