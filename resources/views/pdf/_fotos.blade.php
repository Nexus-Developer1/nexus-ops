{{-- Grelha de fotografias em TABELA (4 por linha). O dompdf não lida bem com sequências de
     imagens inline — sobrepunham-se verticalmente à secção anterior; tabelas são o layout
     fiável. Uma tabela por linha para quebras de página limpas entre linhas de fotos. --}}
@foreach (array_chunk($fotos, 4) as $linhaFotos)
    <table class="fotos-tab">
        <tr>
            @foreach ($linhaFotos as $foto)
                <td class="foto-cel"><img src="{{ $foto }}" class="foto"></td>
            @endforeach
            @for ($vazias = count($linhaFotos); $vazias < 4; $vazias++)
                <td class="foto-cel"></td>
            @endfor
        </tr>
    </table>
@endforeach
