<?php

namespace App\Services\Agenda;

use App\Models\EventoAgenda;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Enums\EventStatus;

// iCalendar da agenda para o Outlook, nas duas vias:
//
//  CONVITE (METHOD:REQUEST / CANCEL) — um por técnico, anexado ao email de criado/alterado/
//  removido. UID ESTÁVEL derivado do id (agenda-{id}@infra.nexus-solutions.pt): é o que faz o
//  Outlook casar a alteração e o cancelamento com o evento que já tem — com UID aleatório o
//  agendamento ficava preso no calendário para sempre. SEQUENCE: 0 na criação, +1 a cada
//  alteração enviada, cancelamento com o seguinte. ORGANIZER = a mailbox de serviço que envia
//  (Suporte@nxs.pt), ATTENDEE = o técnico. Datas SEMPRE com TZID Europe/Lisbon (nada de horas
//  flutuantes nem UTC "Z" — o Outlook mostra a hora certa em qualquer PC).
//
//  FEED (METHOD:PUBLISH) — subscrito no Outlook, só leitura, refresh lento: janela [-30, +90]
//  dias, eventos apagados há menos de 30 dias como STATUS:CANCELLED (o Outlook risca-os em vez
//  de os deixar órfãos), e SEM os eventos em que o próprio subscritor é convidado (esses já lhe
//  chegam por convite — senão via-os a dobrar). Campos filtrados: só o essencial.
//
// O VEVENT/VCALENDAR é do spatie/icalendar-generator. O pacote não fala METHOD/ORGANIZER/
// ATTENDEE (é feito para feeds), por isso o convite acrescenta essas três propriedades ao
// calendário gerado — em vez de montar o ficheiro inteiro à mão.
class GeradorIcs
{
    public const DOMINIO_UID = 'infra.nexus-solutions.pt';

    public const TZ = 'Europe/Lisbon';

    public const FEED_DIAS_ATRAS = 30;

    public const FEED_DIAS_FRENTE = 90;

    public const FEED_CANCELADOS_DIAS = 30;

    public static function uid(int $eventoId): string
    {
        return "agenda-{$eventoId}@".self::DOMINIO_UID;
    }

    /**
     * Convite (ou cancelamento) para UM técnico, a partir do instantâneo do evento
     * (NotificadorAgenda::instantaneo). $cancelar → METHOD:CANCEL com STATUS:CANCELLED.
     *
     * @param  array<string, mixed>  $e
     */
    public function convite(array $e, int $sequence, User $tecnico, bool $cancelar = false): string
    {
        $tz = new DateTimeZone(self::TZ);

        $evento = Event::create($e['titulo'])
            ->uniqueIdentifier(self::uid((int) $e['id']))
            ->startsAt(Carbon::parse($e['inicio'])->setTimezone($tz))
            ->endsAt(Carbon::parse($e['fim'])->setTimezone($tz))
            ->sequence($sequence)
            ->status($cancelar ? EventStatus::Cancelled : EventStatus::Confirmed)
            ->description($this->descricao($e))
            ->url(route('agenda'));

        if ($e['cliente'] ?? null) {
            $evento->address((string) $e['cliente']);
        }

        $ics = Calendar::create('Nexus Infra · Agenda')
            ->productIdentifier('-//Nexus Infra//Agenda//PT')
            ->event($evento)
            ->get();

        $organizador = (string) (config('services.microsoft_graph.sender') ?: config('mail.from.address'));

        return $this->comConvite($ics, $cancelar ? 'CANCEL' : 'REQUEST', $organizador, $tecnico);
    }

    /** Feed de subscrição de um utilizador (ver regras no topo). */
    public function feed(User $subscritor): string
    {
        $tz = new DateTimeZone(self::TZ);

        $calendario = Calendar::create('Nexus Infra · Agenda')
            ->productIdentifier('-//Nexus Infra//Agenda//PT')
            ->refreshInterval(60); // REFRESH-INTERVAL;VALUE=DURATION:PT1H + X-PUBLISHED-TTL:PT1H

        foreach ($this->eventosDoFeed($subscritor) as $e) {
            $cancelado = $e->trashed() || $e->estado->value === 'cancelado';

            $evento = Event::create($this->resumo($e))
                ->uniqueIdentifier(self::uid($e->id))
                ->startsAt($e->inicio->copy()->setTimezone($tz))
                ->endsAt($e->fim->copy()->setTimezone($tz))
                ->sequence((int) $e->ical_sequence + ($e->trashed() ? 1 : 0))
                ->status($cancelado ? EventStatus::Cancelled : EventStatus::Confirmed)
                ->description($this->descricaoFeed($e));

            if ($e->local?->morada || $e->cliente?->nome) {
                $evento->address((string) ($e->local?->morada ?: $e->cliente?->nome));
            }

            $calendario->event($evento);
        }

        // O spatie escreve a duração em minutos (PT60M); PT1H é o mesmo valor ISO 8601 e é a
        // forma que a documentação do Outlook mostra — normaliza-se para não haver dúvidas.
        return str_replace(['DURATION:PT60M', 'X-PUBLISHED-TTL:PT60M'], ['DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'], $calendario->get());
    }

    /**
     * Eventos do feed: janela temporal, apagados recentes incluídos (para irem como CANCELLED),
     * sem os eventos em que o subscritor é convidado. Eager loading do que o VEVENT usa.
     *
     * @return Collection<int, EventoAgenda>
     */
    public function eventosDoFeed(User $subscritor)
    {
        return EventoAgenda::query()
            ->paraFeed()
            ->with(['cliente', 'local', 'tecnico', 'tecnicosAdicionais'])
            // Agrupado: sem os parênteses o OR saltava a janela temporal e o filtro dos apagados.
            ->where(fn ($q) => $q->where('tecnico_id', '!=', $subscritor->id)->orWhereNull('tecnico_id'))
            ->whereDoesntHave('tecnicosAdicionais', fn ($q) => $q->whereKey($subscritor->id))
            ->orderBy('inicio')
            ->get();
    }

    /** Instante da última mudança relevante para o feed (ETag / Last-Modified / cache). */
    public function ultimaAlteracao(): ?Carbon
    {
        $max = EventoAgenda::query()->paraFeed()
            ->selectRaw('greatest(max(updated_at), max(coalesce(deleted_at, updated_at))) as m')
            ->value('m');

        return $max ? Carbon::parse($max) : null;
    }

    // ---- conteúdo -------------------------------------------------------------------------

    private function resumo(EventoAgenda $e): string
    {
        return trim($e->titulo.($e->cliente ? ' · '.$e->cliente->nome : ''));
    }

    // Só o essencial — nada de notas internas, contactos ou faturação.
    private function descricaoFeed(EventoAgenda $e): string
    {
        $linhas = array_filter([
            $e->tecnico_label ? 'Técnicos: '.$e->tecnico_label : null,
            $e->cliente ? 'Cliente: '.$e->cliente->nome : null,
            $e->local?->designacao ? 'Local: '.$e->local->designacao : null,
            $e->estado ? 'Estado: '.$e->estado->value : null,
            $e->notas ? "Notas:\n".$e->notas : null,
        ]);

        return implode("\n", $linhas);
    }

    /** @param array<string, mixed> $e */
    private function descricao(array $e): string
    {
        $linhas = array_filter([
            ($e['tecnicos_nomes'] ?? '') !== '' ? 'Técnicos: '.$e['tecnicos_nomes'] : null,
            ($e['cliente'] ?? null) ? 'Cliente: '.$e['cliente'] : null,
            ($e['equipamento'] ?? null) ? 'Equipamento: '.$e['equipamento'] : null,
            ($e['contrato'] ?? null) ? 'Contrato: '.$e['contrato'] : null,
            ($e['notas'] ?? null) ? "Notas:\n".$e['notas'] : null,
            'Agenda: '.route('agenda'),
        ]);

        return implode("\n", $linhas);
    }

    // Acrescenta METHOD ao VCALENDAR e ORGANIZER/ATTENDEE ao VEVENT gerados pelo spatie.
    private function comConvite(string $ics, string $metodo, string $organizador, User $tecnico): string
    {
        $organizadorLinha = 'ORGANIZER;CN='.$this->escapar('Nexus Infra').':mailto:'.$organizador;
        $attendee = 'ATTENDEE;CN='.$this->escapar($tecnico->nome).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:'.$tecnico->email;

        $ics = preg_replace('/^VERSION:2\.0\r?\n/m', "VERSION:2.0\r\nMETHOD:{$metodo}\r\n", $ics, 1);

        // O spatie omite SEQUENCE quando é 0 (o RFC assume 0 na ausência), mas num convite
        // convém ser explícito — é o número que o Outlook compara para aceitar atualizações.
        if (! preg_match('/^SEQUENCE:/m', $ics)) {
            $ics = preg_replace('/^(UID:[^\r\n]*\r?\n)/m', "$1SEQUENCE:0\r\n", $ics, 1);
        }

        return preg_replace('/^BEGIN:VEVENT\r?\n/m', "BEGIN:VEVENT\r\n{$organizadorLinha}\r\n{$attendee}\r\n", $ics, 1);
    }

    private function escapar(string $texto): string
    {
        return str_replace([';', ',', '"'], ['\\;', '\\,', ''], $texto);
    }
}
