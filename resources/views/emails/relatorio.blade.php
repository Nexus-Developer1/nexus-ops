@component('mail::message')
# Relatório de intervenção {{ $relatorio->numero }}

Caro(a) **{{ $cliente->nome }}**,

Segue em anexo o relatório da intervenção técnica realizada ao equipamento
**{{ $equipamento->fabricante }} {{ $equipamento->modelo }}**{{ $equipamento->numero_serie ? ' (nº de série ' . $equipamento->numero_serie . ')' : '' }}.

@component('mail::panel')
**Tipo:** {{ $intervencao->tipo->rotulo() }}
**Data:** {{ $relatorio->data->translatedFormat('d \d\e F \d\e Y') }}
@if ($intervencao->trabalho_realizado)

{{ \Illuminate\Support\Str::limit($intervencao->trabalho_realizado, 300) }}
@endif
@endcomponent

O documento completo, com as medições e o registo fotográfico, encontra-se no PDF anexo.

Para qualquer esclarecimento, não hesite em contactar-nos.

Com os melhores cumprimentos,
**{{ config('app.name') }}**

@slot('subcopy')
Este é um email automático de notificação. Por favor não responda a esta mensagem.
@endslot
@endcomponent
