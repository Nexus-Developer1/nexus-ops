<?php

namespace App\Services\Agenda;

use App\Enums\PapelUtilizador;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Graph\ClienteGraph;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Calendário PARTILHADO da agenda no Microsoft 365 (a "2.ª via" para o Outlook): a app escreve
// cada evento num calendário da mailbox de serviço (Suporte@nxs.pt) e partilha-o com a equipa.
// Aparece no Outlook de todos como calendário partilhado normal, em TEMPO REAL, sem porta
// aberta nem subscrição por URL — a ligação é do servidor para a Microsoft, como o email.
//
// Idempotente: eventos_agenda.graph_event_id guarda o id do evento no Graph — com id faz PATCH/
// DELETE, sem id faz POST e guarda-o. Uma única fonte de verdade (a nossa BD); o calendário é
// um espelho, nunca se lê de volta. Precisa da permissão de aplicação Calendars.ReadWrite com
// consentimento de admin; sem ela cada chamada dá 403 — o job trata isso como "desligado".
class CalendarioGraph
{
    private const CHAVE_CACHE_CALENDARIO = 'ms_graph_calendario_agenda_id';

    public function __construct(private ClienteGraph $graph) {}

    public function ativo(): bool
    {
        return (bool) config('services.microsoft_graph.calendario_ativo')
            && filled(config('services.microsoft_graph.sender'));
    }

    // Cria ou atualiza o espelho do evento. Devolve o id no Graph (guardado no evento).
    public function espelhar(EventoAgenda $evento): ?string
    {
        $evento->loadMissing(['tecnico', 'tecnicosAdicionais', 'cliente', 'local', 'equipamento', 'contrato']);
        $corpo = $this->corpo($evento);

        if ($evento->graph_event_id) {
            $r = $this->graph->patch($this->caminhoEvento($evento->graph_event_id), $corpo);
            if ($r->status() === 404) {
                // Alguém apagou o espelho à mão no Outlook: recria em vez de ficar órfão.
                $evento->graph_event_id = null;
            } else {
                $this->garantir($r, 'atualizar evento');

                return $evento->graph_event_id;
            }
        }

        $r = $this->graph->post($this->caminhoCalendario().'/events', $corpo);
        $this->garantir($r, 'criar evento');

        $id = (string) $r->json('id');
        // saveQuietly: gravar o id NÃO pode voltar a disparar o observer (loop infinito).
        $evento->forceFill(['graph_event_id' => $id])->saveQuietly();

        return $id;
    }

    // Apaga o espelho (404 = já não existia, é o resultado pretendido).
    public function remover(string $graphEventId): void
    {
        $r = $this->graph->delete($this->caminhoEvento($graphEventId));
        if ($r->status() !== 404) {
            $this->garantir($r, 'apagar evento');
        }
    }

    /**
     * Garante uma categoria COLORIDA por técnico na mailbox de serviço — sem isto o Outlook
     * mostra a categoria sem cor e o evento não se destaca. Idempotente: quem já lá está é
     * saltado; a cor é estável (pelo id do utilizador), para o mesmo técnico ter sempre a
     * mesma cor na agenda.
     *
     * @return array{criadas: list<string>, ja_tinha: list<string>, falhou: list<string>}
     */
    public function garantirCategorias(): array
    {
        $existentes = collect($this->graph->get($this->caminhoUtilizador().'/outlook/masterCategories')->json('value') ?? [])
            ->map(fn ($c) => mb_strtolower((string) ($c['displayName'] ?? '')))
            ->filter()->all();

        $resultado = ['criadas' => [], 'ja_tinha' => [], 'falhou' => []];
        $equipa = User::query()->where('papel', PapelUtilizador::Tecnico)
            ->where('ativo', true)->orderBy('id')->get();

        foreach ($equipa as $i => $u) {
            if (in_array(mb_strtolower($u->nome), $existentes, true)) {
                $resultado['ja_tinha'][] = $u->nome;

                continue;
            }
            // preset0..preset23 são as 24 cores do Outlook; a partir do id, sempre a mesma.
            $r = $this->graph->post($this->caminhoUtilizador().'/outlook/masterCategories', [
                'displayName' => $u->nome,
                'color' => 'preset'.($u->id % 24),
            ]);
            $resultado[$r->successful() ? 'criadas' : 'falhou'][] = $u->nome;
            if ($r->failed()) {
                Log::warning('Graph: falha a criar a categoria do técnico.', ['tecnico' => $u->nome, 'status' => $r->status(), 'erro' => $r->json('error.message')]);
            }
        }

        return $resultado;
    }

    // Partilha o calendário (leitura) com a equipa ativa — quem tiver email no M365 passa a vê-lo
    // no Outlook sem configurar nada. Idempotente: quem já tem permissão é saltado.
    /** @return array{partilhado: list<string>, ja_tinha: list<string>, falhou: list<string>} */
    public function partilharComEquipa(): array
    {
        $existentes = collect($this->graph->get($this->caminhoCalendario().'/calendarPermissions')->json('value') ?? [])
            ->map(fn ($p) => mb_strtolower((string) ($p['emailAddress']['address'] ?? '')))
            ->filter()->all();

        $resultado = ['partilhado' => [], 'ja_tinha' => [], 'falhou' => []];
        $equipa = User::query()->whereIn('papel', [PapelUtilizador::Admin, PapelUtilizador::Tecnico])
            ->where('ativo', true)->whereNotNull('email')->orderBy('nome')->get();

        foreach ($equipa as $u) {
            $email = mb_strtolower($u->email);
            if ($email === mb_strtolower((string) config('services.microsoft_graph.sender'))) {
                continue; // a própria mailbox é a dona
            }
            if (in_array($email, $existentes, true)) {
                $resultado['ja_tinha'][] = $u->email;

                continue;
            }
            $r = $this->graph->post($this->caminhoCalendario().'/calendarPermissions', [
                'emailAddress' => ['address' => $u->email, 'name' => $u->nome],
                'role' => 'read',
                'isRemovable' => true,
                'isInsideOrganization' => true,
            ]);
            $resultado[$r->successful() ? 'partilhado' : 'falhou'][] = $u->email;
            if ($r->failed()) {
                Log::warning('Graph: falha a partilhar o calendário da agenda.', ['email' => $u->email, 'status' => $r->status(), 'erro' => $r->json('error.message')]);
            }
        }

        return $resultado;
    }

    // Id do calendário da agenda na mailbox de serviço — encontra pelo nome ou cria; em cache.
    public function calendarioId(): string
    {
        return Cache::remember(self::CHAVE_CACHE_CALENDARIO, now()->addDay(), function () {
            $nome = (string) config('services.microsoft_graph.calendario_agenda');
            $lista = $this->graph->get($this->caminhoUtilizador().'/calendars', ['$select' => 'id,name']);
            $this->garantir($lista, 'listar calendários');

            foreach ($lista->json('value') ?? [] as $c) {
                if (($c['name'] ?? null) === $nome) {
                    return (string) $c['id'];
                }
            }

            $criado = $this->graph->post($this->caminhoUtilizador().'/calendars', ['name' => $nome]);
            $this->garantir($criado, 'criar calendário');

            return (string) $criado->json('id');
        });
    }

    /** @return array<string, mixed> */
    public function corpo(EventoAgenda $e): array
    {
        $tz = GeradorIcs::TZ;
        $cancelado = $e->trashed() || $e->estado->value === 'cancelado';
        // Corpo em HTML (era texto simples) para o nome dos TÉCNICOS sair a negrito e em
        // destaque — pedido da equipa. O assunto do evento não aceita formatação nenhuma
        // (o Outlook desenha-o sempre em texto simples), por isso a evidência na grelha
        // faz-se pela COR da categoria (uma por técnico, ver garantirCategorias()).
        $linhas = array_filter([
            $e->tecnico_label ? '<span style="font-size:15px;">Técnicos: <strong>'.e($e->tecnico_label).'</strong></span>' : null,
            $e->cliente ? 'Cliente: <strong>'.e($e->cliente->nome).'</strong>' : null,
            $e->equipamento ? 'Equipamento: '.e(trim(($e->equipamento->numero_serie ?? '').' · '.trim(($e->equipamento->fabricante ?? '').' '.($e->equipamento->modelo ?? '')), ' ·')) : null,
            $e->contrato ? 'Contrato: '.e($e->contrato->numero) : null,
            $e->local?->designacao ? 'Local: '.e($e->local->designacao) : null,
            'Estado: '.e($e->estado->value),
            $e->notas ? '<br>Notas:<br>'.nl2br(e($e->notas)) : null,
            '',
            '<a href="'.route('agenda').'">Abrir a agenda</a>',
        ]);

        return [
            'subject' => ($cancelado ? '[CANCELADO] ' : '').$e->resumoCompleto(),
            'start' => ['dateTime' => $e->inicio->copy()->setTimezone($tz)->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'end' => ['dateTime' => $e->fim->copy()->setTimezone($tz)->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'location' => ['displayName' => (string) ($e->local?->morada ?: $e->cliente?->nome ?: '')],
            'body' => ['contentType' => 'html', 'content' => '<div style="font-family:Segoe UI,Arial,sans-serif; font-size:14px; color:#111827;">'.implode('<br>', $linhas).'</div>'],
            'showAs' => $cancelado ? 'free' : 'busy',
            'isReminderOn' => false,
            // Uma categoria por TÉCNICO (além do tipo): é o que dá cor ao evento na grelha do
            // Outlook — o "em evidência" possível num calendário. As cores vivem na lista de
            // categorias da mailbox de serviço (garantirCategorias()).
            'categories' => array_values(array_filter(array_merge(
                [$e->tipo?->value === 'visita_preventiva' ? 'Preventiva' : null],
                $e->tecnico_label ? array_map('trim', explode(',', $e->tecnico_label)) : [],
            ))),
            // Identificação estável (o mesmo UID dos convites) — útil para reconciliar à mão.
            'transactionId' => GeradorIcs::uid($e->id),
        ];
    }

    private function caminhoUtilizador(): string
    {
        return '/users/'.rawurlencode((string) config('services.microsoft_graph.sender'));
    }

    private function caminhoCalendario(): string
    {
        return $this->caminhoUtilizador().'/calendars/'.$this->calendarioId();
    }

    private function caminhoEvento(string $id): string
    {
        return $this->caminhoUtilizador().'/events/'.rawurlencode($id);
    }

    private function garantir($resposta, string $acao): void
    {
        if ($resposta->failed()) {
            throw new RuntimeException("Graph: falha a {$acao} ({$resposta->status()}): ".substr((string) ($resposta->json('error.message') ?? $resposta->body()), 0, 200));
        }
    }
}
