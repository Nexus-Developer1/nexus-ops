@component('mail::message')
{!! nl2br(e($mensagem)) !!}

@slot('subcopy')
Relatório {{ $relatorio->numero }} — o documento completo (medições e registo fotográfico) segue em anexo (PDF).
@endslot
@endcomponent
