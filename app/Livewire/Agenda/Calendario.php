<?php

namespace App\Livewire\Agenda;

use App\Enums\EstadoContrato;
use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\AssuntoEvento;
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

    // Equipamento opcional do evento (pesquisa server-side; deriva local/cliente).
    public ?int $formEquipamentoId = null;

    public string $formEquipamentoBusca = '';

    // Contrato opcional a que a visita pertence; cobertura só conta se houver contrato.
    public ?int $formContratoId = null;

    public ?string $formCobertura = null; // 'incluida' | 'extra'

    // Avisar por email os técnicos associados (criar / alterar / remover). Guardado no evento,
    // para valer também no arrasto na agenda e no remover do detalhe. Ligado por defeito ao criar.
    public bool $formNotificar = true;

    // Ao mudar o filtro de técnico, manda o FullCalendar re-buscar os eventos (sem F5).
    public function updatedTecnicoNome(): void
    {
        $this->recarregar();
    }

    // Mudar o intervalo refaz as linhas por dia (mantendo as horas já editadas).
    public function updatedFormInicio(): void
    {
        $this->reconstruirHorasDias();
    }

    public function updatedFormFim(): void
    {
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
            // Horas do intervalo não servem de padrão por dia — usa o horário de cobertura.
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

        $this->reset(['editandoId', 'editandoConvertido', 'formTitulo', 'formTecnicoIds', 'formEquipamentoId', 'formEquipamentoBusca', 'formContratoId', 'formCobertura', 'formHorasDias', 'formNotificar']);

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

        $evento = EventoAgenda::with('equipamento', 'intervencao.relatorio')->findOrFail($this->eventoSelecionadoId);

        if (! $evento->editavelPelaAgenda()) {
            return; // o botão não aparece nestes casos — guard defensivo
        }

        $this->resetErrorBag();
        $this->editandoConvertido = (bool) $evento->intervencao_id;
        $this->editandoId = $evento->id;
        $this->formTitulo = $evento->titulo;
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
        $this->formEquipamentoId = $evento->equipamento_id;
        $this->formEquipamentoBusca = $evento->equipamento
            ? trim(($evento->equipamento->numero_serie ?? '—')
                .' · '.trim($evento->equipamento->fabricante.' '.$evento->equipamento->modelo))
            : '';
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

        $this->eventoSelecionadoId = null; // fecha o detalhe; abre o formulário
        $this->modalCriar = true;
    }

    // Seleção de um equipamento na pesquisa server-side: fixa o id e o texto da label.
    public function selecionarEquipamento(int $id): void
    {
        $equipamento = Equipamento::with('local')->find($id);
        if (! $equipamento) {
            return;
        }

        $this->formEquipamentoId = $equipamento->id;
        $this->formEquipamentoBusca = trim(($equipamento->numero_serie ?? '—')
            .' · '.trim($equipamento->fabricante.' '.$equipamento->modelo));
    }

    // Escrever na caixa desfaz a seleção (campo opcional: sem escolha => sem equipamento).
    public function updatedFormEquipamentoBusca(): void
    {
        $this->formEquipamentoId = null;
    }

    // Escolher contrato assume "incluída" por defeito; limpar contrato limpa a cobertura.
    public function updatedFormContratoId(): void
    {
        $this->formCobertura = $this->formContratoId ? ($this->formCobertura ?: 'incluida') : null;
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
        $this->reconstruirHorasDias();

        $this->validate([
            'formTitulo' => ['required', 'string', 'max:255'],
            // Técnicos = contas ativas com papel técnico (mesma regra dos colaboradores no relatório).
            'formTecnicoIds' => ['array'],
            'formTecnicoIds.*' => ['integer',
                Rule::exists('utilizadores', 'id')
                    ->where('papel', PapelUtilizador::Tecnico->value)
                    ->where('ativo', true)],
            'formEquipamentoId' => ['nullable', 'exists:equipamentos,id'],
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

        $atributos = [
            'titulo' => $titulo,
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

        $resultado = $agendador->gravar($atributos, $tecnicosEscolhidos, $adicionaisIds, $this->editandoId);

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
        $equipamentosFiltrados = $this->formEquipamentoBusca !== ''
            ? Equipamento::query()
                ->with('local.cliente')
                ->where(function ($q) {
                    $termo = '%'.$this->formEquipamentoBusca.'%';
                    $norm = '%'.$this->normalizarBusca($this->formEquipamentoBusca).'%';
                    $semAcentos = "translate(lower(fabricante || ' ' || modelo), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";
                    $q->whereRaw($semAcentos.' like ?', [$norm])
                        ->orWhere('numero_serie', 'ilike', $termo);
                })
                ->orderBy('numero_serie')
                ->limit(30)
                ->get()
            : collect();

        // Contratos ativos para ligar a visita manual; se há equipamento escolhido,
        // filtra aos contratos do cliente desse equipamento.
        $clienteDoEquipamento = $this->formEquipamentoId
            ? Equipamento::with('local')->find($this->formEquipamentoId)?->local?->cliente_id
            : null;
        $contratos = Contrato::query()
            ->where('estado', EstadoContrato::Ativo->value)
            ->when($clienteDoEquipamento, fn ($q) => $q->where('cliente_id', $clienteDoEquipamento))
            ->with('cliente')
            ->orderBy('numero')
            ->get();

        // Saldo do contrato escolhido no formulário (Vaga 1): marcar "incluída" era às
        // cegas — o excesso só se descobria depois, na ficha do contrato.
        $saldoContratoForm = $this->formContratoId
            ? Contrato::find($this->formContratoId)?->saldoVisitas()
            : null;

        return view('livewire.agenda.calendario', [
            'tecnicos' => $tecnicos,
            'nomesTecnicos' => $nomesTecnicos,
            'saldoContratoForm' => $saldoContratoForm,
            'evento' => $evento,
            'eventoEditavel' => $evento && $evento->editavelPelaAgenda(),
            'assuntos' => AssuntoEvento::orderBy('nome')->get(),
            'equipamentosFiltrados' => $equipamentosFiltrados,
            'contratos' => $contratos,
        ]);
    }
}
