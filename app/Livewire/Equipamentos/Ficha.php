<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Livewire\Concerns\ApenasEquipa;
use App\Livewire\Concerns\ComponentesComArtigos;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Services\Auditor;
use App\Services\GeradorQrEquipamento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'ativos'])]
class Ficha extends Component
{
    use ApenasEquipa;
    use ComponentesComArtigos;

    public Equipamento $equipamento;

    public string $notas = '';

    // Identificação editável: cliente final (texto livre) e localização física da instalação.
    public string $clienteFinal = '';

    public string $localizacaoInstalacao = '';

    // Pesquisa server-side de equipamentos para associar (banco de baterias → UPS).
    public string $bancoBusca = '';

    // Pesquisa server-side para MUDAR o cliente do equipamento (o clique pede confirmação).
    public string $novoClienteBusca = '';

    // Bancos de baterias (parte do equipamento) — um UPS pode ter VÁRIOS. Lista de linhas
    // { numero_serie, modelo, capacidade, num_baterias, data_instalacao, proxima_troca }.
    /** @var list<array<string, string>> */
    public array $bancos = [];

    // Componentes do sistema (equipamentos compostos) — { designacao, quantidade }.
    /** @var list<array{designacao: string, quantidade: string|int}> */
    public array $componentes = [];

    // Alertas de manutenção programados: linhas { data, texto } — o texto do aviso é editável.
    /** @var list<array{data: ?string, texto: string}> */
    public array $alertasManutencao = [];

    public function mount(Equipamento $equipamento): void
    {
        $this->equipamento = $equipamento->load('local.cliente');

        // URL canónico: a barra do browser mostra SEMPRE o mastamp. Uma ficha aberta pelo id
        // interno (etiqueta QR já impressa, favorito, link de email antigo) resolve na mesma,
        // mas é redirecionada para /ativos/<mastamp> — sem isto, os URLs antigos continuavam
        // a exibir o id apesar de a chave nova existir. Nos testes Livewire diretos (sem rota)
        // o parâmetro bruto não existe e o redirect não dispara.
        $bruto = request()->route()?->originalParameters()['equipamento'] ?? null;
        if ($bruto !== null && $bruto !== (string) $equipamento->getRouteKey()) {
            $this->redirect(route('equipamentos.ficha', $equipamento), navigate: true);

            return;
        }

        $this->notas = $equipamento->notas ?? '';
        $this->clienteFinal = $equipamento->cliente_final ?? '';
        $this->localizacaoInstalacao = $equipamento->localizacao_instalacao ?? '';

        $attrs = $equipamento->atributos ?? [];
        // Bancos no formato do formulário (converte o formato antigo de um banco só, se existir).
        $this->bancos = $equipamento->bancosParaFormulario();
        $this->componentes = array_values($attrs['componentes'] ?? []);
        $this->alertasManutencao = $equipamento->alertasManutencao()
            ->orderBy('data')->get()
            ->map(fn ($a) => ['data' => $a->data->toDateString(), 'texto' => $a->texto])
            ->all();
    }

    public function adicionarAlertaManutencao(): void
    {
        if (count($this->alertasManutencao) < 24) {
            // Texto por defeito editável — escreve-se o aviso que fizer sentido.
            $this->alertasManutencao[] = ['data' => '', 'texto' => 'Manutenção preventiva'];
        }
    }

    public function removerAlertaManutencao(int $indice): void
    {
        unset($this->alertasManutencao[$indice]);
        $this->alertasManutencao = array_values($this->alertasManutencao);
    }

    // Guarda os alertas de manutenção (substitui o conjunto — mesma mecânica dos SLAs do contrato).
    public function guardarAlertasManutencao(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'alertasManutencao' => ['array', 'max:24'],
            'alertasManutencao.*.data' => ['required', 'date'],
            'alertasManutencao.*.texto' => ['required', 'string', 'max:255'],
        ]);

        $this->equipamento->alertasManutencao()->delete();
        foreach ($this->alertasManutencao as $a) {
            $this->equipamento->alertasManutencao()->create(['data' => $a['data'], 'texto' => trim($a['texto'])]);
        }

        session()->flash('sucesso', 'Alertas guardados.');
    }

    public function adicionarComponente(): void
    {
        $this->componentes[] = ['designacao' => '', 'quantidade' => 1];
    }

    public function removerComponente(int $indice): void
    {
        unset($this->componentes[$indice]);
        $this->componentes = array_values($this->componentes);
    }

    public function adicionarBanco(): void
    {
        $this->bancos[] = ['numero_serie' => '', 'modelo' => '', 'capacidade' => '', 'num_baterias' => '', 'data_instalacao' => '', 'proxima_troca' => ''];
    }

    public function removerBanco(int $indice): void
    {
        unset($this->bancos[$indice]);
        $this->bancos = array_values($this->bancos);
    }

    // Guarda a lista de componentes (só linhas preenchidas). Preserva os restantes atributos.
    public function guardarComponentes(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'componentes' => ['array', 'max:200'],
            'componentes.*.designacao' => ['nullable', 'string', 'max:255'],
            'componentes.*.quantidade' => ['nullable', 'integer', 'min:0'],
        ]);

        $componentes = collect($this->componentes)
            ->map(fn ($c) => ['designacao' => trim((string) ($c['designacao'] ?? '')), 'quantidade' => (int) ($c['quantidade'] ?? 0)])
            ->filter(fn ($c) => $c['designacao'] !== '')
            ->values()
            ->all();

        $attrs = $this->equipamento->atributos ?? [];
        if ($componentes === []) {
            unset($attrs['componentes']);
        } else {
            $attrs['componentes'] = $componentes;
        }

        $this->equipamento->update(['atributos' => $attrs ?: null]);
        $this->componentes = $componentes;

        session()->flash('sucesso', 'Componentes guardados.');
    }

    // Guarda as notas livres do equipamento.
    public function guardarNotas(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate(['notas' => ['nullable', 'string', 'max:5000']]);

        $this->equipamento->update(['notas' => trim($this->notas) ?: null]);

        session()->flash('sucesso', 'Notas guardadas.');
    }

    // Muda o CLIENTE do equipamento (via local): aterra na "Instalação principal" do cliente
    // novo — a mesma designação que o registo manual e o sync usam, para não criar locais
    // paralelos. A UI pede confirmação (wire:confirm) antes de chamar. As ligações a contratos
    // do cliente antigo NÃO são mexidas (a confirmação avisa quando existem).
    public function mudarCliente(int $clienteId): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $cliente = Cliente::find($clienteId);
        if (! $cliente) {
            return;
        }

        // local pode ser null: equipamento "por associar" (veio do PHC sem cliente na fatura).
        if ($cliente->id === $this->equipamento->local?->cliente_id) {
            $this->novoClienteBusca = '';
            session()->flash('sucesso', 'O equipamento já pertence a este cliente.');

            return;
        }

        $clienteAntigo = $this->equipamento->local?->cliente;
        $local = Local::firstOrCreate(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);
        $this->equipamento->update(['local_id' => $local->id]);
        $this->equipamento = $this->equipamento->fresh()->load('local.cliente');
        $this->novoClienteBusca = '';

        // Auditoria: mover um equipamento redesenha o perímetro do portal (o histórico de
        // intervenções/relatórios dele passa a resolver no cliente novo) — fica registado
        // quem fez a mudança e o de/para (11.ª revisão de segurança).
        Log::info('Equipamento mudou de cliente.', [
            'equipamento' => $this->equipamento->numero_serie ?? $this->equipamento->id,
            'de' => $clienteAntigo->nome ?? '(sem cliente — por associar)',
            'para' => $cliente->nome,
            'utilizador' => auth()->user()?->email,
        ]);
        Auditor::registar('equipamento_mudou_cliente', $this->equipamento, [
            'serie' => $this->equipamento->numero_serie,
            'de' => $clienteAntigo->nome ?? '(sem cliente — por associar)',
            'para' => $cliente->nome,
        ]);

        session()->flash('sucesso', "Equipamento movido para {$cliente->nome}.");
    }

    // Sugestões para a mudança de cliente (nome sem acentos ou NIF, como no registo manual).
    private function novosClientesFiltrados(): Collection
    {
        if (trim($this->novoClienteBusca) === '') {
            return collect();
        }

        $semAcentos = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];
        $nomeNorm = '%'.str_replace($de, $para, mb_strtolower(trim($this->novoClienteBusca))).'%';
        $termo = '%'.trim($this->novoClienteBusca).'%';

        return Cliente::query()
            ->where(fn ($q) => $q->whereRaw($semAcentos.' like ?', [$nomeNorm])
                ->orWhere('nif', 'ilike', $termo))
            ->orderBy('nome')
            ->limit(15)
            ->get(['id', 'nome', 'nif']);
    }

    // Guarda o cliente final e a localização da instalação (texto livre).
    public function guardarIdentificacao(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'clienteFinal' => ['nullable', 'string', 'max:255'],
            'localizacaoInstalacao' => ['nullable', 'string', 'max:255'],
        ]);

        $this->equipamento->update([
            'cliente_final' => trim($this->clienteFinal) ?: null,
            'localizacao_instalacao' => trim($this->localizacaoInstalacao) ?: null,
        ]);

        session()->flash('sucesso', 'Identificação guardada.');
    }

    // Guarda o banco de baterias (parte do equipamento). Preserva os restantes atributos.
    public function guardarBanco(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'bancos' => ['array', 'max:50'],
            'bancos.*.numero_serie' => ['nullable', 'string', 'max:255'],
            'bancos.*.modelo' => ['nullable', 'string', 'max:255'],
            'bancos.*.capacidade' => ['nullable', 'string', 'max:100'],
            'bancos.*.num_baterias' => ['nullable', 'integer', 'min:0'],
            'bancos.*.data_instalacao' => ['nullable', 'date'],
            'bancos.*.proxima_troca' => ['nullable', 'date'],
        ]);

        [$bancos, $totalBaterias, $proximaTroca] = Equipamento::normalizarBancos($this->bancos);

        // Preserva os restantes atributos; substitui os campos dos bancos (e limpa o formato antigo).
        $attrs = $this->equipamento->atributos ?? [];
        unset($attrs['banco_numero_serie'], $attrs['banco_modelo'], $attrs['banco_capacidade'], $attrs['data_baterias']);
        if ($bancos !== []) {
            $attrs['bancos'] = $bancos;
        } else {
            unset($attrs['bancos']);
        }
        if ($totalBaterias !== null) {
            $attrs['num_baterias'] = $totalBaterias;
        } else {
            unset($attrs['num_baterias']);
        }

        $this->equipamento->update([
            'atributos' => $attrs ?: null,
            'proxima_troca_baterias' => $proximaTroca,
        ]);
        $this->bancos = $this->equipamento->fresh()->bancosParaFormulario();

        session()->flash('sucesso', 'Bancos de baterias guardados.');
    }

    // Associa um equipamento existente (banco de baterias/kit) a este como equipamento pai.
    public function associarBanco(int $id): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        // Um equipamento que já pertence a um pai não pode ter associados (evita cadeias).
        if ($this->equipamento->equipamento_pai_id !== null) {
            return;
        }

        $banco = Equipamento::find($id);
        if (! $banco || $banco->id === $this->equipamento->id) {
            return;
        }

        if ($banco->equipamento_pai_id !== null && $banco->equipamento_pai_id !== $this->equipamento->id) {
            $this->addError('bancoBusca', 'Este equipamento já está associado a outro UPS. Desassocie-o primeiro na ficha desse UPS.');

            return;
        }

        // A hierarquia é de UM nível (UPS → bancos). Um equipamento que já TEM associados não pode
        // passar a ser filho — evita cadeias de 3+ níveis (X → P → B), mesmo por chamada forjada.
        if ($banco->equipamentosAssociados()->exists()) {
            $this->addError('bancoBusca', 'Este equipamento já tem bancos associados a ele — não pode ser associado como banco de outro.');

            return;
        }

        $banco->update(['equipamento_pai_id' => $this->equipamento->id]);
        $this->bancoBusca = '';

        session()->flash('sucesso', 'Banco de baterias associado.');
    }

    public function desassociarBanco(int $id): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->equipamento->equipamentosAssociados()->whereKey($id)->update(['equipamento_pai_id' => null]);

        session()->flash('sucesso', 'Banco de baterias desassociado.');
    }

    // Candidatos a associar, por pesquisa server-side (nº de série ou local). Sem texto,
    // sugere bancos/kits de baterias AINDA LIVRES no MESMO LOCAL deste equipamento.
    private function bancosFiltrados(): Collection
    {
        $base = Equipamento::query()
            ->with('local.cliente')
            ->whereKeyNot($this->equipamento->id)
            ->whereNull('equipamento_pai_id')
            ->whereDoesntHave('equipamentosAssociados'); // um pai não pode virar filho (evita ciclos)

        if (trim($this->bancoBusca) === '') {
            return $base
                ->where('local_id', $this->equipamento->local_id)
                ->where(fn ($q) => $q->where('modelo', 'ilike', '%bater%')->orWhere('faminome', 'ilike', '%bater%'))
                ->orderBy('numero_serie')
                ->limit(10)
                ->get();
        }

        $termo = '%'.trim($this->bancoBusca).'%';

        return $base
            ->where(function ($q) use ($termo) {
                $q->where('numero_serie', 'ilike', $termo)
                    ->orWhere('modelo', 'ilike', $termo)
                    ->orWhereHas('local', fn ($l) => $l->where('designacao', 'ilike', $termo)
                        ->orWhereHas('cliente', fn ($c) => $c->where('nome', 'ilike', $termo)));
            })
            ->orderBy('numero_serie')
            ->limit(30)
            ->get();
    }

    // Inicia uma nova intervenção corretiva e abre o formulário de execução.
    public function novaIntervencao()
    {
        $intervencao = $this->equipamento->intervencoes()->create([
            'tecnico_id' => auth()->id(),
            'tipo' => TipoIntervencao::Corretiva,
            'estado' => EstadoIntervencao::EmCurso,
            'data_inicio' => now(),
            'pedido_em' => now(), // melhor esforço: o relógio do SLA arranca já (editável no relatório)
        ]);

        return redirect()->route('intervencoes.formulario', $intervencao);
    }

    public function render()
    {
        $id = $this->equipamento->id;

        // Histórico: intervenções onde o equipamento é o PRINCIPAL (equipamento_id) ou
        // está entre os COBERTOS (pivot). Query única sobre intervencoes com EXISTS, por
        // isso cada intervenção aparece UMA só vez — mesmo que fosse principal e coberto.
        $intervencoes = Intervencao::query()
            ->where(fn ($q) => $q->where('equipamento_id', $id)
                ->orWhereHas('equipamentosCobertos', fn ($q) => $q->whereKey($id)))
            ->with(['tecnico', 'relatorio']) // relatorio: o histórico liga cada intervenção ao seu relatório
            ->orderByDesc('data_inicio')
            ->get();

        // Contrato(s) que cobrem este equipamento (N:M via contrato_equipamentos).
        $contratos = $this->equipamento->contratos()
            ->orderByDesc('data_inicio')
            ->get();

        return view('livewire.equipamentos.ficha', [
            'intervencoes' => $intervencoes,
            'contratos' => $contratos,
            // Bancos/kits associados a este equipamento e (se for um banco) o UPS pai.
            'bancosAssociados' => $this->equipamento->equipamentosAssociados()->with('local.cliente')->orderBy('numero_serie')->get(),
            'equipamentoPai' => $this->equipamento->equipamentoPai()->with('local.cliente')->first(),
            'bancosFiltrados' => $this->bancosFiltrados(),
            'novosClientesFiltrados' => $this->novosClientesFiltrados(),
            'artigosFiltrados' => $this->artigosFiltrados(),
            // QR real com o URL desta ficha (substitui o placeholder decorativo).
            'qrEtiqueta' => app(GeradorQrEquipamento::class)->svg($this->equipamento),
        ]);
    }
}
