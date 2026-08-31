<?php

namespace App\Livewire\Agenda;

use App\Enums\EstadoContrato;
use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\AssuntoEvento;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Agenda\AgendadorEvento;
use App\Services\Agenda\ConversorVisita;
use App\Services\Agenda\FonteCalendario;
use App\Services\Agenda\NotificadorAgenda;
use App\Services\Agenda\SincronizadorAgenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'agenda', 'titulo' => 'Agenda'])]
class Calendario extends Component
{
    use ApenasEquipa;

    // Filtro por técnico: pelo NOME (os eventos são marcados por tecnico_nome, texto livre),
    // não pela conta. Vazio = todos.
    #[Url]
    public string $tecnicoNome = '';

    public function mount(): void
    {
        // Técnico = admin: vê a agenda toda por defeito (o filtro por técnico é opcional, igual
        // ao admin). Sem auto-filtro à sua própria agenda.
    }

    // Duração máxima de um evento (dias). Serviços reais não passam disto; o limite existe
    // para travar enganos/abusos que tornariam o técnico inagendável (14.ª revisão).
    private const MAX_DIAS_EVENTO = 31;

    // Detalhe de um evento (clique num evento).
    public ?int $eventoSelecionadoId = null;

    // Modal de criação/edição de evento próprio (o texto livre vai para o título).
    // editandoId preenchido = modo edição (o mesmo modal e validação servem os dois).
    // editandoConvertido = o evento já tem intervenção (rascunho): equipamento e contrato
    // pertencem ao relatório e ficam trancados no formulário; as datas propagam-se.
    public bool $modalCriar = false;

    public ?int $editandoId = null;

    public bool $editandoConvertido = false;

    public string $formTitulo = '';

    // Notas livres do evento (morada, contactos no local, indicações de acesso, o que levar…).
    public string $formNotas = '';

    // Alertas programados do evento: linhas {data, texto} — mesma mecânica dos alertas do
    // contrato/equipamento (painel de alertas + email diário), com o texto que se quiser.
    /** @var list<array{data: string, texto: string}> */
    public array $formAlertas = [];

    // Técnicos do evento: CONTAS de utilizador (mesma lista do relatório) — um evento pode ter
    // 1 ou mais. O 1.º (por ordem alfabética) fica como principal em tecnico_id (cor do evento);
    // os restantes vão para a pivot evento_tecnicos. Todos contam para conflitos,
    // feed iCal.
    /** @var list<int|string> */
    public array $formTecnicoIds = [];

    public string $formInicio = '';

    public string $formFim = '';

    // Horas trabalhadas POR DIA — só quando o intervalo atravessa vários dias. Linhas
    // {dia: 'Y-m-d', inicio: 'H:i', fim: 'H:i'} geradas a partir de formInicio/formFim
    // (uma por dia, horas editáveis). Vazio = evento de um só dia.
    /** @var list<array{dia: string, inicio: string, fim: string}> */
    public array $formHorasDias = [];

    // Cliente do evento (combobox por nome/NIF/nº ERP). Filtra a pesquisa de equipamentos a esse
    // cliente; escolher um equipamento preenche-o. Sem equipamento, o evento fica com este cliente.
    public ?int $formClienteId = null;

    public string $formClienteBusca = '';

    // Equipamento principal do evento, opcional (pesquisa server-side; deriva local/cliente).
    public ?int $formEquipamentoId = null;

    public string $formEquipamentoBusca = '';

    // Equipamentos ADICIONAIS do evento (além do principal): um trabalho pode abranger vários
    // equipamentos do mesmo cliente. Num evento já convertido são os "cobertos" do relatório.
    /** @var list<int> */
    public array $formEquipamentosExtra = [];

    // Contrato opcional a que a visita pertence; cobertura só conta se houver contrato.
    public ?int $formContratoId = null;

    public ?string $formCobertura = null; // 'incluida' | 'extra'

    // Avisar por email os técnicos associados (criar / alterar / remover). Guardado no evento,
    // para valer também no arrasto na agenda e no remover do detalhe. Ligado por defeito ao criar.
    public bool $formNotificar = true;

    // Dia inteiro: o evento ocupa CADA dia do período das 00:00 às 23:59 (férias, ausências).
    // Liga-se sozinho quando o tipo de evento é "Férias"; pode marcar-se à mão para outros.
    public bool $formDiaInteiro = false;

    // Ao mudar o filtro de técnico, manda o FullCalendar re-buscar os eventos (sem F5).
    public function updatedTecnicoNome(): void
    {
        $this->recarregar();
    }

    // Mudar o intervalo refaz as linhas por dia (mantendo as horas já editadas).
    public function updatedFormInicio(): void
    {
        $this->formDiaInteiro ? $this->aplicarDiaInteiro() : $this->reconstruirHorasDias();
    }

    public function updatedFormFim(): void
    {
        $this->formDiaInteiro ? $this->aplicarDiaInteiro() : $this->reconstruirHorasDias();
    }

    // Tipo de evento "Férias" → dia inteiro, automaticamente (o técnico não tem de acertar horas
    // em cada dia). Só liga; não desliga se a pessoa mudar o texto depois de o ter marcado.
    public function updatedFormTitulo(): void
    {
        if (self::ehFerias($this->formTitulo) && ! $this->formDiaInteiro) {
            $this->formDiaInteiro = true;
            $this->aplicarDiaInteiro();
        }
    }

    public function updatedFormDiaInteiro(): void
    {
        if ($this->formDiaInteiro) {
            $this->aplicarDiaInteiro();

            return;
        }

        // Desligar: volta às horas propostas (08h–19h) nos mesmos dias — senão ficavam os
        // 00:00–23:59 do dia inteiro como se fossem horas escolhidas à mão.
        try {
            $ini = Carbon::parse($this->formInicio)->setTime((int) config('agenda.hora_abertura'), 0);
            $fim = Carbon::parse($this->formFim)->setTime((int) config('agenda.hora_fecho'), 0);
        } catch (\Throwable) {
            $this->reconstruirHorasDias();

            return;
        }

        $this->formInicio = $ini->format('Y-m-d\TH:i');
        $this->formFim = $fim->format('Y-m-d\TH:i');
        $this->formHorasDias = [];
        $this->reconstruirHorasDias();
    }

    public static function ehFerias(string $titulo): bool
    {
        return (bool) preg_match('/f[ée]rias?/iu', $titulo);
    }

    // Dia inteiro: início às 00:00 do 1.º dia, fim às 23:59 do último, e CADA dia do período
    // com 00:00–23:59 nas horas por dia (o calendário mostra um bloco cheio em todos os dias).
    private function aplicarDiaInteiro(): void
    {
        if (trim($this->formInicio) === '' || trim($this->formFim) === '') {
            return;
        }

        try {
            $ini = Carbon::parse($this->formInicio)->startOfDay();
            $fim = Carbon::parse($this->formFim)->startOfDay();
        } catch (\Throwable) {
            return;
        }

        if ($fim->lt($ini)) {
            $fim = $ini->copy();
        }

        $this->formInicio = $ini->format('Y-m-d\T00:00');
        $this->formFim = $fim->format('Y-m-d\T23:59');
        $this->formHorasDias = []; // sem restos de horas editadas
        $this->reconstruirHorasDias();
    }

    // Uma linha por dia do intervalo [formInicio..formFim]: dias novos herdam as horas do
    // intervalo, dias já editados mantêm as suas; dias fora do intervalo caem. Um só dia
    // (ou intervalo inválido/desmesurado) → sem linhas, o evento fica contínuo como sempre.
    private function reconstruirHorasDias(): void
    {
        // Campo vazio: Carbon::parse('') devolve "agora" (não falha) e gerava linhas fantasma
        // com o fim na hora atual, preservadas depois como se fossem horas editadas.
        if (trim($this->formInicio) === '' || trim($this->formFim) === '') {
            $this->formHorasDias = [];

            return;
        }

        try {
            $ini = Carbon::parse($this->formInicio);
            $fim = Carbon::parse($this->formFim);
        } catch (\Throwable) {
            $this->formHorasDias = [];

            return;
        }

        // Nº de meias-noites cruzadas. 0 = mesmo dia; 1 com fim "mais cedo" que o início =
        // turno NOTURNO (ex.: 22:00→06:00) — fica contínuo, como registo de um só turno;
        // linhas por dia só para serviços que ocupam vários dias de trabalho.
        $diasCalendario = (int) $ini->copy()->startOfDay()->diffInDays($fim->copy()->startOfDay());
        if (! $ini->lt($fim) || $diasCalendario === 0 || $diasCalendario > 31
            || ($diasCalendario === 1 && $fim->format('H:i') <= $ini->format('H:i'))) {
            $this->formHorasDias = [];

            return;
        }

        $existentes = collect($this->formHorasDias)->keyBy('dia');
        $horaIni = $ini->format('H:i');
        $horaFim = $fim->format('H:i');
        if ($horaFim <= $horaIni) {
            // Horas do intervalo não servem de padrão por dia — usa as horas propostas (config).
            $horaIni = sprintf('%02d:00', (int) config('agenda.hora_abertura'));
            $horaFim = sprintf('%02d:00', (int) config('agenda.hora_fecho'));
        }

        $linhas = [];
        for ($dia = $ini->copy()->startOfDay(); $dia->lte($fim); $dia->addDay()) {
            $chave = $dia->toDateString();
            $linhas[] = [
                'dia' => $chave,
                'inicio' => $existentes[$chave]['inicio'] ?? $horaIni,
                'fim' => $existentes[$chave]['fim'] ?? $horaFim,
            ];
        }

        // Dia inteiro: todos os dias cheios, ignorando horas editadas antes de marcar a opção.
        if ($this->formDiaInteiro) {
            $linhas = array_map(fn (array $l) => ['dia' => $l['dia'], 'inicio' => '00:00', 'fim' => '23:59'], $linhas);
        }

        $this->formHorasDias = $linhas;
    }

    private function recarregar(): void
    {
        $this->js("window.dispatchEvent(new Event('agenda:refetch'))");
    }

    // ---- Fonte de eventos do FullCalendar (intervalo visível) ----
    /** @return array<int, array<string, mixed>> */
    public function eventos(string $inicio, string $fim, FonteCalendario $fonte): array
    {
        return $fonte->eventos(Carbon::parse($inicio), Carbon::parse($fim), $this->tecnicoNome);
    }

    // ---- Reagendamento (drag/resize) ----
    /** @return array<string, mixed> */
    public function reagendar(int $id, string $inicio, string $fim, ?int $tecnicoId, AgendadorEvento $agendador): array
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::findOrFail($id);
        // Instantâneo ANTES do arrasto — o email de "alterado" mostra o horário antigo → novo.
        $antes = NotificadorAgenda::instantaneo($evento);

        $resultado = $agendador->reagendar($evento, Carbon::parse($inicio), Carbon::parse($fim));

        if ($resultado['ok'] ?? false) {
            app(NotificadorAgenda::class)->alterado($evento->refresh(), $antes);
        }

        return $resultado;
    }

    // ---- Detalhe + conversão evento→intervenção ----
    public function selecionar(int $id): void
    {
        $this->eventoSelecionadoId = $id;
    }

    public function fecharModal(): void
    {
        $this->eventoSelecionadoId = null;
    }

    // Remove um evento a partir do modal de detalhe, com regras seguras quanto ao
    // relatório ligado (camadas 2/3). Soft deletes em todos os modelos → o cascade da
    // BD não dispara, por isso apago explicitamente relatorio + intervencao + evento.
    public function removerEvento(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::with('intervencao.relatorio')->findOrFail($this->eventoSelecionadoId);

        // Preventivas são geridas pelo contrato (geração apaga/recria planeadas) —
        // removê-las aqui dessincronizava. Não removível por este caminho.
        if ($evento->tipo === TipoEvento::VisitaPreventiva) {
            session()->flash('erro', 'Visitas preventivas são geridas pelo contrato e não podem ser removidas pela agenda.');
            $this->eventoSelecionadoId = null;
            $this->recarregar();

            return;
        }

        $intervencao = $evento->intervencao;
        $relatorio = $intervencao?->relatorio;

        // Relatório finalizado/enviado (tem número) nunca é apagado.
        if ($relatorio && $relatorio->estado !== EstadoRelatorio::Rascunho) {
            session()->flash('erro', "Este evento tem um relatório finalizado (nº {$relatorio->numero}) — não pode ser removido.");
            $this->eventoSelecionadoId = null;
            $this->recarregar();

            return;
        }

        // Instantâneo ANTES de apagar — depois já não há evento para descrever no email.
        $antes = NotificadorAgenda::instantaneo($evento);

        DB::transaction(function () use ($evento, $intervencao, $relatorio) {
            // Rascunho ligado → apaga o relatório e a intervenção que nasceram do evento.
            $relatorio?->delete();
            $intervencao?->delete();
            $evento->delete();
        });

        app(NotificadorAgenda::class)->removido($antes);

        $this->eventoSelecionadoId = null;
        $this->recarregar();
    }

    public function iniciarVisita(ConversorVisita $conversor)
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::findOrFail($this->eventoSelecionadoId);
        $intervencao = $conversor->iniciar($evento, $evento->tecnico_id ?? auth()->id());

        return redirect()->route('intervencoes.formulario', $intervencao);
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'formTitulo' => 'tipo de evento',
            'formTecnicoIds' => 'técnicos',
            'formTecnicoIds.*' => 'técnico',
            'formEquipamentoId' => 'equipamento',
            'formNotas' => 'notas',
            'formInicio' => 'início',
            'formFim' => 'fim',
            'formHorasDias.*.inicio' => 'hora de início do dia',
            'formHorasDias.*.fim' => 'hora de fim do dia',
        ];
    }

    // ---- Criação de evento próprio ----
    public function abrirCriacao(string $inicio, string $fim): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->reset(['editandoId', 'editandoConvertido', 'formTitulo', 'formNotas', 'formAlertas', 'formTecnicoIds', 'formClienteId', 'formClienteBusca', 'formEquipamentoId', 'formEquipamentoBusca', 'formEquipamentosExtra', 'formContratoId', 'formCobertura', 'formHorasDias', 'formNotificar', 'formDiaInteiro']);

        // A agenda manda o DIA (sem hora) — as horas reais escrevem-se no formulário e podem
        // abranger vários dias. Sem hora, arranca na abertura e propõe 1h (fácil de ajustar).
        $ini = Carbon::parse($inicio);
        $fimC = Carbon::parse($fim);
        if (! str_contains($inicio, 'T')) {
            $ini = $ini->setTime((int) config('agenda.hora_abertura'), 0);
            $fimC = $ini->copy()->addHour();
        }

        $this->formInicio = $ini->format('Y-m-d\TH:i');
        $this->formFim = $fimC->format('Y-m-d\TH:i');
        $this->reconstruirHorasDias();
        $this->modalCriar = true;
    }

    // ---- Edição de evento (reutiliza o modal/formulário da criação) ----
    public function abrirEdicao(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::with('equipamento', 'cliente', 'intervencao.relatorio')->findOrFail($this->eventoSelecionadoId);

        if (! $evento->editavelPelaAgenda()) {
            return; // o botão não aparece nestes casos — guard defensivo
        }

        $this->resetErrorBag();
        $this->editandoConvertido = (bool) $evento->intervencao_id;
        $this->editandoId = $evento->id;
        $this->formTitulo = $evento->titulo;
        $this->formNotas = (string) $evento->notas;
        $this->formAlertas = $evento->alertas()->orderBy('data')->get()
            ->map(fn ($a) => ['data' => $a->data->toDateString(), 'texto' => $a->texto])
            ->all();
        // Contas dos técnicos (principal + adicionais). Eventos LEGADOS só têm o nome em texto —
        // tenta casá-lo com uma conta (é o caso normal: os nomes escritos eram os dos técnicos).
        $principal = $evento->tecnico_id
            ?? ($evento->tecnico_nome
                ? User::where('papel', PapelUtilizador::Tecnico)->where('ativo', true)
                    ->whereRaw('lower(nome) = ?', [mb_strtolower(trim($evento->tecnico_nome))])
                    ->value('id')
                : null);
        $this->formTecnicoIds = array_values(array_unique(array_filter(array_merge(
            [$principal],
            $evento->tecnicosAdicionais()->pluck('utilizadores.id')->all(),
        ))));
        $this->formClienteId = $evento->cliente_id;
        $this->formClienteBusca = '';
        $this->formEquipamentoId = $evento->equipamento_id;
        // Adicionais: num evento convertido a fonte de verdade são os COBERTOS do relatório
        // (podem ter sido acrescentados/retirados no editor); senão, a pivot do evento.
        $this->formEquipamentosExtra = $evento->intervencao_id
            ? $evento->intervencao->equipamentosCobertos()->pluck('equipamentos.id')->map(fn ($v) => (int) $v)->all()
            : $evento->equipamentosAdicionais()->pluck('equipamentos.id')->map(fn ($v) => (int) $v)->all();
        $this->formEquipamentosExtra = array_values(array_diff($this->formEquipamentosExtra, [(int) $evento->equipamento_id]));
        // A caixa fica VAZIA: o principal mostra-se no chip; a caixa é só para pesquisar e
        // acrescentar (com o texto do principal lá dentro, a pesquisa não encontrava nada).
        $this->formEquipamentoBusca = '';
        $this->formContratoId = $evento->contrato_id;
        $this->formCobertura = $evento->cobertura;
        $this->formNotificar = (bool) $evento->notificar_tecnicos;
        $this->formInicio = $evento->inicio->format('Y-m-d\TH:i');
        $this->formFim = $evento->fim->format('Y-m-d\TH:i');
        // Horas por dia gravadas → pré-preenche; reconstruir alinha com o intervalo
        // (acrescenta dias em falta, descarta dias fora, mantém as horas editadas).
        $this->formHorasDias = collect($evento->horas_dias ?? [])
            ->map(fn ($l) => [
                'dia' => (string) ($l['dia'] ?? ''),
                'inicio' => substr((string) ($l['inicio'] ?? ''), 0, 5),
                'fim' => substr((string) ($l['fim'] ?? ''), 0, 5),
            ])
            ->all();
        $this->reconstruirHorasDias();

        // Dia inteiro guardado? (00:00 → 23:59 e, se houver horas por dia, todas cheias.)
        $this->formDiaInteiro = $evento->inicio->format('H:i') === '00:00' && $evento->fim->format('H:i') === '23:59'
            && collect($this->formHorasDias)->every(fn ($l) => $l['inicio'] === '00:00' && $l['fim'] === '23:59');

        $this->eventoSelecionadoId = null; // fecha o detalhe; abre o formulário
        $this->modalCriar = true;
    }

    // Seleção de um equipamento na pesquisa server-side: fixa o id e o texto da label.
    // Escolher da lista: sem principal → fica principal (dá o cliente/local ao evento); com
    // principal → junta-se aos ADICIONAIS (mesmo cliente — validado ao gravar). Num evento já
    // convertido o principal pertence ao relatório e não muda por aqui: entra sempre como extra.
    public function selecionarEquipamento(int $id): void
    {
        $equipamento = Equipamento::with('local')->find($id);
        if (! $equipamento) {
            return;
        }

        if ($this->formEquipamentoId === null && ! $this->editandoConvertido) {
            $this->formEquipamentoId = $equipamento->id;
            // O cliente segue o principal (equipamento → local → cliente).
            $this->formClienteId = $equipamento->local?->cliente_id ?? $this->formClienteId;
        } elseif ($equipamento->id !== $this->formEquipamentoId && ! in_array($equipamento->id, $this->formEquipamentosExtra, true)) {
            $this->formEquipamentosExtra[] = $equipamento->id;
        }

        // A caixa fica sempre livre para a próxima pesquisa — o escolhido mostra-se no chip.
        $this->formEquipamentoBusca = '';
    }

    // Tirar o principal (só em eventos sem relatório): o 1.º adicional sobe a principal.
    public function removerEquipamentoPrincipal(): void
    {
        if ($this->editandoConvertido) {
            return;
        }

        $this->formEquipamentoId = array_shift($this->formEquipamentosExtra) ?: null;
        $this->formEquipamentosExtra = array_values($this->formEquipamentosExtra);
    }

    public function removerEquipamentoExtra(int $id): void
    {
        $this->formEquipamentosExtra = array_values(array_filter($this->formEquipamentosExtra, fn ($e) => (int) $e !== $id));
    }

    // Escolher contrato assume "incluída" por defeito; limpar contrato limpa a cobertura.
    public function updatedFormContratoId(): void
    {
        $this->formCobertura = $this->formContratoId ? ($this->formCobertura ?: 'incluida') : null;
    }

    // Escolher um cliente no combobox. Se já havia equipamentos de OUTRO cliente escolhidos,
    // saem (um evento tem um só cliente). Num evento convertido o cliente é do relatório.
    public function selecionarCliente(int $id): void
    {
        if ($this->editandoConvertido) {
            return;
        }

        $cliente = Cliente::find($id);
        if (! $cliente) {
            return;
        }

        if ($this->formClienteId !== null && $this->formClienteId !== $cliente->id) {
            $this->formEquipamentoId = null;
            $this->formEquipamentosExtra = [];
        }

        $this->formClienteId = $cliente->id;
        $this->formClienteBusca = '';
    }

    // Tirar o cliente: os equipamentos (que são dele) saem também.
    public function adicionarAlerta(): void
    {
        if (count($this->formAlertas) < 24) {
            $this->formAlertas[] = ['data' => '', 'texto' => ''];
        }
    }

    public function removerAlerta(int $i): void
    {
        unset($this->formAlertas[$i]);
        $this->formAlertas = array_values($this->formAlertas);
    }

    public function removerCliente(): void
    {
        if ($this->editandoConvertido) {
            return;
        }

        $this->formClienteId = null;
        $this->formClienteBusca = '';
        $this->formEquipamentoId = null;
        $this->formEquipamentosExtra = [];
    }

    // Dobra de acentos para pesquisa (igual ao combobox de cliente).
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    public function fecharCriar(): void
    {
        $this->modalCriar = false;
        $this->editandoId = null;
        $this->editandoConvertido = false;
    }

    // Guarda um novo assunto de evento próprio (lookup que cresce com o uso) e
    // seleciona-o. Idempotente: se já existir (sem acentos/maiúsculas), reutiliza.
    public function adicionarAssunto(string $nome): bool
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        if ($nome === '') {
            $this->addError('novoAssunto', 'Indique o assunto do evento.');

            return false;
        }

        AssuntoEvento::firstOrCreate(
            ['nome_normalizado' => AssuntoEvento::normalizar($nome)],
            ['nome' => $nome],
        );

        $this->formTitulo = $nome;
        $this->resetErrorBag('novoAssunto');

        return true;
    }

    // Submit do modal de evento: cria um novo OU grava a edição (editandoId preenchido).
    // Mantém o nome histórico "criarEvento" — é o submit único do formulário. As regras
    // (conflitos, transação, locks) vivem no AgendadorEvento; a camada 2 no Sincronizador.
    public function criarEvento(AgendadorEvento $agendador, SincronizadorAgenda $sincronizador, NotificadorAgenda $notificador)
    {
        abort_if(auth()->user()->ehCliente(), 403);

        // Refaz as linhas por dia a partir do intervalo submetido (à prova de payload forjado:
        // os DIAS vêm sempre do intervalo; só as horas de cada dia vêm do formulário).
        // Dia inteiro manda: à prova de um payload que traga outras horas com a opção marcada.
        $this->formDiaInteiro ? $this->aplicarDiaInteiro() : $this->reconstruirHorasDias();

        $this->validate([
            'formTitulo' => ['required', 'string', 'max:255'],
            'formNotas' => ['nullable', 'string', 'max:5000'],
            'formAlertas' => ['array', 'max:24'],
            'formAlertas.*.data' => ['required', 'date'],
            'formAlertas.*.texto' => ['required', 'string', 'max:255'],
            // Técnicos = contas ativas com papel técnico (mesma regra dos colaboradores no relatório).
            'formTecnicoIds' => ['array'],
            'formTecnicoIds.*' => ['integer',
                Rule::exists('utilizadores', 'id')
                    ->where('papel', PapelUtilizador::Tecnico->value)
                    ->where('ativo', true)],
            'formClienteId' => ['nullable', 'exists:clientes,id'],
            'formEquipamentoId' => ['nullable', 'exists:equipamentos,id'],
            'formEquipamentosExtra' => ['array', 'max:50'],
            'formEquipamentosExtra.*' => ['integer', 'exists:equipamentos,id'],
            'formInicio' => ['required', 'date'],
            // Teto de duração: sem ele (e sem o travão do horário de cobertura, que já não se
            // aplica a multi-dia) dava para gravar um evento de anos, que colidiria com tudo e
            // tornaria o técnico inagendável para sempre (14.ª revisão de segurança).
            'formFim' => ['required', 'date', 'after:formInicio', 'before_or_equal:'.Carbon::parse($this->formInicio ?: 'now')->addDays(self::MAX_DIAS_EVENTO)->toDateTimeString()],
            'formContratoId' => ['nullable', 'exists:contratos,id'],
            'formCobertura' => ['nullable', 'required_with:formContratoId', 'in:incluida,extra'],
            'formHorasDias' => ['array', 'max:32'],
            'formHorasDias.*.inicio' => ['required', 'date_format:H:i'],
            'formHorasDias.*.fim' => ['required', 'date_format:H:i'],
        ]);

        // Cada dia tem de ter fim depois do início (as horas são dentro do próprio dia).
        foreach ($this->formHorasDias as $i => $linha) {
            if ($linha['fim'] <= $linha['inicio']) {
                $this->addError("formHorasDias.$i.fim", 'O fim tem de ser depois do início (dia '.Carbon::parse($linha['dia'])->format('d/m').').');

                return;
            }
        }

        $inicio = Carbon::parse($this->formInicio);
        $fim = Carbon::parse($this->formFim);

        // Com horas por dia, o intervalo real do evento é do início do 1.º dia ao fim do último.
        $horasDias = $this->formHorasDias !== [] ? $this->formHorasDias : null;
        if ($horasDias) {
            $primeira = $horasDias[0];
            $ultima = $horasDias[count($horasDias) - 1];
            $inicio = Carbon::parse($primeira['dia'].' '.$primeira['inicio']);
            $fim = Carbon::parse($ultima['dia'].' '.$ultima['fim']);
        }

        // Contas escolhidas, por ordem alfabética (determinística): 1.º = principal, resto = adicionais.
        // Re-filtra por papel/ativo (defesa em profundidade): mesmo que a validação acima fosse
        // contornada num refactor, nunca se atribui uma conta não-técnica ou inativa.
        $tecnicosEscolhidos = $this->formTecnicoIds === []
            ? collect()
            : User::whereIn('id', array_map('intval', $this->formTecnicoIds))
                ->where('papel', PapelUtilizador::Tecnico)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get();
        $tecnico = $tecnicosEscolhidos->first();
        $adicionaisIds = $tecnicosEscolhidos->skip(1)->pluck('id')->values()->all();

        // O tipo de evento (texto livre) fica guardado para sugestões futuras (cresce com o uso).
        $titulo = trim(preg_replace('/\s+/', ' ', $this->formTitulo));
        if ($titulo !== '') {
            AssuntoEvento::firstOrCreate(
                ['nome_normalizado' => AssuntoEvento::normalizar($titulo)],
                ['nome' => $titulo],
            );
        }

        // Equipamento opcional: se escolhido, herda local e cliente (equipamento → local → cliente).
        $equipamentoId = $localId = $clienteId = null;
        if ($this->formEquipamentoId) {
            $equipamento = Equipamento::with('local')->find($this->formEquipamentoId);
            $equipamentoId = $equipamento->id;
            $localId = $equipamento->local_id;
            $clienteId = $equipamento->local?->cliente_id;
        }

        // Sem equipamento mas COM contrato: o evento herda o cliente do contrato.
        if (! $clienteId && $this->formContratoId) {
            $clienteId = Contrato::withoutGlobalScopes()->whereKey($this->formContratoId)->value('cliente_id');
        }

        // Sem equipamento nem contrato: fica o cliente escolhido no formulário (pode ser nenhum).
        $clienteId ??= $this->formClienteId;

        $atributos = [
            'titulo' => $titulo,
            'notas' => trim($this->formNotas) !== '' ? trim($this->formNotas) : null,
            'inicio' => $inicio,
            'fim' => $fim,
            // Conta do técnico + nome desnormalizado: o id liga conflitos/iCal;
            // o nome alimenta as cores, o filtro e a legenda (partilhados com eventos legados).
            'tecnico_id' => $tecnico?->id,
            'tecnico_nome' => $tecnico?->nome,
            'equipamento_id' => $equipamentoId,
            'local_id' => $localId,
            'cliente_id' => $clienteId,
            'contrato_id' => $this->formContratoId,
            // Cobertura só se houver contrato (incluída = desconta saldo; extra = faturável).
            'cobertura' => $this->formContratoId ? $this->formCobertura : null,
            // Horas trabalhadas por dia (multi-dia); null = evento contínuo de um dia.
            'horas_dias' => $horasDias,
            'notificar_tecnicos' => $this->formNotificar,
        ];

        // Instantâneo ANTES da edição — o email de "alterado" diz o que mudou e avisa quem saiu.
        $antes = $this->editandoId ? NotificadorAgenda::instantaneo(EventoAgenda::findOrFail($this->editandoId)) : null;

        // Equipamentos adicionais: só do MESMO cliente do principal (um evento tem um cliente).
        // Sem principal não há adicionais (o 1.º escolhido é sempre o principal).
        $extras = array_values(array_unique(array_map('intval', $this->formEquipamentosExtra)));
        $extras = array_values(array_diff($extras, [(int) $equipamentoId]));
        if ($extras !== []) {
            if (! $equipamentoId) {
                $this->addError('formEquipamentoId', 'Escolha primeiro o equipamento principal.');

                return;
            }
            $foraDoCliente = Equipamento::whereIn('id', $extras)
                ->whereDoesntHave('local', fn ($q) => $q->where('cliente_id', $clienteId))
                ->exists();
            if ($foraDoCliente) {
                $this->addError('formEquipamentoId', 'Todos os equipamentos têm de ser do mesmo cliente.');

                return;
            }
        }

        $resultado = $agendador->gravar($atributos, $tecnicosEscolhidos, $adicionaisIds, $this->editandoId, $extras);

        if (isset($resultado['erro'])) {
            $this->addError('formInicio', $resultado['erro']);

            return;
        }

        if (isset($resultado['bloqueado'])) {
            session()->flash('erro', 'Este evento já não pode ser editado pela agenda.');
            $this->modalCriar = false;
            $this->editandoId = null;
            $this->recarregar();

            return;
        }

        $evento = $resultado['evento'];

        // Alertas programados: substituição total pelas linhas do formulário (igual ao contrato).
        $evento->alertas()->delete();
        foreach ($this->formAlertas as $a) {
            $evento->alertas()->create(['data' => $a['data'], 'texto' => trim($a['texto'])]);
        }

        // Email aos técnicos associados (se o evento o pedir): novo → "criado"; edição →
        // "alterado" a quem ficou, "criado" a quem entrou, "removido" a quem saiu.
        $antes ? $notificador->alterado($evento, $antes) : $notificador->criado($evento);

        // Camada 2 (agenda → relatórios) via ponto único: evento com equipamento OU contrato
        // e início futuro → rascunho de relatório ligado. As guardas anti-loop vivem no serviço.
        if ($sincronizador->eventoGravado($evento)) {
            session()->flash('sucesso', 'Rascunho de relatório criado para esta intervenção.');
        }

        $this->modalCriar = false;
        $this->editandoId = null;
        $this->editandoConvertido = false;
        $this->recarregar();
    }

    public function render(FonteCalendario $fonte)
    {
        // Contas de técnico — checkboxes do formulário de evento (1 ou mais técnicos).
        $tecnicos = User::where('papel', PapelUtilizador::Tecnico)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (User $t) => ['id' => $t->id, 'nome' => $t->nome]);

        // Filtro + legenda com a cor por técnico (coerente com a cor dos eventos).
        $nomesTecnicos = $fonte->legenda();

        $evento = $this->eventoSelecionadoId
            ? EventoAgenda::with(['cliente', 'equipamento', 'tecnico', 'intervencao.relatorio'])->find($this->eventoSelecionadoId)
            : null;

        // Pesquisa de equipamentos server-side (nº série/fabricante/modelo, sem acentos), limitada.
        // São muitos (~17k) — nunca carregar tudo no cliente.
        // Com cliente escolhido, a lista fica restrita aos equipamentos dele — e aparece logo ao
        // abrir a caixa, mesmo sem escrever nada (são poucos por cliente).
        $equipamentosFiltrados = $this->formEquipamentoBusca !== '' || $this->formClienteId
            ? Equipamento::query()
                ->with('local.cliente')
                ->when($this->formClienteId, fn ($q) => $q->whereHas('local', fn ($l) => $l->where('cliente_id', $this->formClienteId)))
                ->when($this->formEquipamentoBusca !== '', fn ($q) => $q->where(function ($q) {
                    $termo = '%'.$this->formEquipamentoBusca.'%';
                    $norm = '%'.$this->normalizarBusca($this->formEquipamentoBusca).'%';
                    $semAcentos = "translate(lower(fabricante || ' ' || modelo), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";
                    $clienteSemAcentos = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";
                    $q->whereRaw($semAcentos.' like ?', [$norm])
                        ->orWhere('numero_serie', 'ilike', $termo)
                        // Pela CLIENTE: escreve-se o nome do cliente e aparecem os equipamentos dele
                        // para escolher (quem marca a visita sabe o cliente, não o nº de série).
                        ->orWhereHas('local.cliente', fn ($c) => $c->whereRaw($clienteSemAcentos.' like ?', [$norm]));
                }))
                // Agrupados por cliente (os do mesmo cliente ficam juntos), depois por nº de série.
                ->orderBy(Cliente::select('nome')->join('locais', 'locais.cliente_id', '=', 'clientes.id')->whereColumn('locais.id', 'equipamentos.local_id')->limit(1))
                ->orderBy('numero_serie')
                ->limit(50)
                ->get()
            : collect();

        // Contratos ativos para ligar a visita manual; se há equipamento escolhido,
        // filtra aos contratos do cliente desse equipamento.
        $clienteDoEquipamento = $this->formEquipamentoId
            ? Equipamento::with('local')->find($this->formEquipamentoId)?->local?->cliente_id
            : null;
        $clienteDoForm = $clienteDoEquipamento ?? $this->formClienteId;
        $contratos = Contrato::query()
            ->where('estado', EstadoContrato::Ativo->value)
            ->when($clienteDoForm, fn ($q) => $q->where('cliente_id', $clienteDoForm))
            ->with('cliente')
            ->orderBy('numero')
            ->get();

        // Saldo do contrato escolhido no formulário (Vaga 1): marcar "incluída" era às
        // cegas — o excesso só se descobria depois, na ficha do contrato.
        $saldoContratoForm = $this->formContratoId
            ? Contrato::find($this->formContratoId)?->saldoVisitas()
            : null;

        // Pesquisa de clientes server-side (nome sem acentos, NIF, nº ERP), limitada.
        $clientesFiltrados = $this->formClienteBusca !== ''
            ? Cliente::query()
                ->where(function ($q) {
                    $termo = '%'.$this->formClienteBusca.'%';
                    $norm = '%'.$this->normalizarBusca($this->formClienteBusca).'%';
                    $q->whereRaw("translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu') like ?", [$norm])
                        ->orWhere('nif', 'ilike', $termo)
                        ->orWhere('id_erp', 'ilike', $termo);
                })
                ->orderBy('nome')
                ->limit(30)
                ->get()
            : collect();

        return view('livewire.agenda.calendario', [
            'clientesFiltrados' => $clientesFiltrados,
            'clienteEscolhido' => $this->formClienteId ? Cliente::find($this->formClienteId) : null,
            'tecnicos' => $tecnicos,
            'nomesTecnicos' => $nomesTecnicos,
            'saldoContratoForm' => $saldoContratoForm,
            'evento' => $evento,
            'eventoEditavel' => $evento && $evento->editavelPelaAgenda(),
            'assuntos' => AssuntoEvento::orderBy('nome')->get(),
            'equipamentosFiltrados' => $equipamentosFiltrados,
            // Chips do principal + adicionais (modelos, para mostrar série/modelo/cliente).
            'equipamentoPrincipal' => $this->formEquipamentoId ? Equipamento::with('local.cliente')->find($this->formEquipamentoId) : null,
            'equipamentosExtra' => $this->formEquipamentosExtra !== []
                ? Equipamento::with('local.cliente')->whereIn('id', $this->formEquipamentosExtra)->orderBy('numero_serie')->get()
                : collect(),
            'contratos' => $contratos,
        ]);
    }
}
