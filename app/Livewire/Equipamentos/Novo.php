<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Registo MANUAL de um equipamento que NÃO vem do ERP (id_erp fica nulo = "não vendido por nós").
// Fica logo disponível em contratos e relatórios (que filtram por cliente, não por origem) e o
// sync do ERP nunca lhe toca (só faz upsert por id_erp). O cliente tem de já existir (vem do ERP).
#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Novo equipamento'])]
class Novo extends Component
{
    use ApenasEquipa;

    private const NOME_SEM_ACENTOS = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    private const LOCAL_PADRAO = 'Instalação principal';

    // Cliente (existente, pesquisa server-side) + local.
    public ?int $cliente_id = null;
    public string $clienteBusca = '';
    public ?int $local_id = null;

    // Dados do equipamento.
    public string $tipo = 'ups';
    // Só para o tipo "Diversos" (soluções pontuais): o tipo não diz nada, a descrição é
    // obrigatória e é ela que identifica a solução. Guardada em atributos.tipo_descricao.
    public string $tipo_descricao = '';
    public string $fabricante = '';
    public string $modelo = '';
    public string $numero_serie = '';
    public string $cliente_final = '';
    public string $localizacao_instalacao = '';
    public string $estado = 'operacional';
    public string $data_instalacao = '';
    public string $fim_garantia = '';
    public string $notas = '';

    // Bancos de baterias (parte do mesmo equipamento) — um UPS pode ter VÁRIOS. Lista de linhas
    // { numero_serie, modelo, capacidade, num_baterias, data_instalacao, proxima_troca }.
    /** @var list<array<string, string>> */
    public array $bancos = [];

    // Componentes do sistema (ex.: sistema de deteção de incêndio) — { designacao, quantidade }.
    // Guardados em atributos.componentes. Útil para equipamentos compostos, sem nº de série.
    /** @var list<array{designacao: string, quantidade: string|int}> */
    public array $componentes = [];

    // As secções extra do formulário dependem do tipo: bancos de baterias são de UPS;
    // componentes do sistema são de equipamentos compostos (incêndio / sistema / ambiental / diversos).
    // A view esconde as secções; a gravação re-verifica (o tipo pode mudar depois de preencher).
    public function tipoTemBancos(): bool
    {
        return $this->tipo === TipoEquipamento::Ups->value;
    }

    public function tipoTemComponentes(): bool
    {
        return in_array($this->tipo, [
            TipoEquipamento::Incendio->value,
            TipoEquipamento::Sistema->value,
            TipoEquipamento::Ambiental->value,
            TipoEquipamento::Diversos->value,
        ], true);
    }

    public function tipoTemDescricao(): bool
    {
        return $this->tipo === TipoEquipamento::Diversos->value;
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

    public function adicionarComponente(): void
    {
        $this->componentes[] = ['designacao' => '', 'quantidade' => 1];
    }

    public function removerComponente(int $indice): void
    {
        unset($this->componentes[$indice]);
        $this->componentes = array_values($this->componentes);
    }

    // Normaliza a lista de componentes: designação limpa, quantidade inteira; ignora linhas vazias.
    private function componentesNormalizados(): array
    {
        return collect($this->componentes)
            ->map(fn ($c) => [
                'designacao' => trim((string) ($c['designacao'] ?? '')),
                'quantidade' => (int) ($c['quantidade'] ?? 0),
            ])
            ->filter(fn ($c) => $c['designacao'] !== '')
            ->values()
            ->all();
    }

    public function selecionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if (! $cliente) {
            return;
        }

        $this->cliente_id = $cliente->id;
        $this->clienteBusca = $cliente->nome ?? '';
        // Pré-seleciona o primeiro local do cliente (normalmente "Instalação principal").
        $this->local_id = Local::where('cliente_id', $cliente->id)->orderBy('designacao')->value('id');
    }

    public function limparCliente(): void
    {
        $this->cliente_id = null;
        $this->clienteBusca = '';
        $this->local_id = null;
    }

    public function guardar()
    {
        $this->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'local_id' => ['nullable', 'integer', Rule::exists('locais', 'id')->where('cliente_id', $this->cliente_id)],
            // Só os tipos do catálogo (sem PDU) — Rule::enum aceitaria o case legado.
            'tipo' => ['required', Rule::in(array_column(TipoEquipamento::selecionaveis(), 'value'))],
            // Em "Diversos" a descrição é obrigatória (é ela que identifica a solução).
            'tipo_descricao' => $this->tipoTemDescricao() ? ['required', 'string', 'max:255'] : [],
            'fabricante' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'numero_serie' => ['nullable', 'string', 'max:255'],
            'cliente_final' => ['nullable', 'string', 'max:255'],
            'localizacao_instalacao' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::enum(EstadoEquipamento::class)],
            'data_instalacao' => ['nullable', 'date'],
            'fim_garantia' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:5000'],
            // Secções escondidas para o tipo escolhido não se validam: linhas preenchidas antes
            // de mudar o tipo ficam no estado do componente e um erro nelas bloqueava o guardar
            // a apontar para uma secção invisível (a gravação já as descarta de qualquer forma).
            ...($this->tipoTemBancos() ? [
                'bancos' => ['array', 'max:50'],
                'bancos.*.numero_serie' => ['nullable', 'string', 'max:255'],
                'bancos.*.modelo' => ['nullable', 'string', 'max:255'],
                'bancos.*.capacidade' => ['nullable', 'string', 'max:100'],
                'bancos.*.num_baterias' => ['nullable', 'integer', 'min:0'],
                'bancos.*.data_instalacao' => ['nullable', 'date'],
                'bancos.*.proxima_troca' => ['nullable', 'date'],
            ] : []),
            ...($this->tipoTemComponentes() ? [
                'componentes' => ['array', 'max:200'],
                'componentes.*.designacao' => ['nullable', 'string', 'max:255'],
                'componentes.*.quantidade' => ['nullable', 'integer', 'min:0'],
            ] : []),
        ], [
            'tipo_descricao.required' => 'No tipo "Diversos", descreve a solução no campo ao lado.',
        ]);

        // Local: o escolhido ou a "Instalação principal" do cliente (criada se não existir — mesma
        // designação que o sync usa, para não criar locais paralelos).
        $localId = $this->local_id
            ?: Local::firstOrCreate(['cliente_id' => $this->cliente_id, 'designacao' => self::LOCAL_PADRAO])->id;

        // Atributos JSONB do equipamento (bancos/componentes abaixo; a secção "Especificações
        // UPS" saiu do formulário a pedido da equipa — equipamentos antigos mantêm o que têm).
        $atributos = [];

        // Bancos de baterias (lista). num_baterias = TOTAL (p/ ficha de medição); a coluna
        // proxima_troca_baterias = a mais próxima (p/ alertas). Ver Equipamento::normalizarBancos().
        [$bancos, $totalBaterias, $proximaTroca] = Equipamento::normalizarBancos($this->tipoTemBancos() ? $this->bancos : []);
        if ($bancos !== []) {
            $atributos['bancos'] = $bancos;
        }
        if ($totalBaterias !== null) {
            $atributos['num_baterias'] = $totalBaterias;
        }

        // Componentes do sistema (lista { designacao, quantidade }), só linhas preenchidas.
        $componentes = $this->tipoTemComponentes() ? $this->componentesNormalizados() : [];
        if ($componentes !== []) {
            $atributos['componentes'] = $componentes;
        }

        // Descrição do tipo (só "Diversos") — mostrada na ficha junto à etiqueta do tipo.
        if ($this->tipoTemDescricao() && trim($this->tipo_descricao) !== '') {
            $atributos['tipo_descricao'] = trim($this->tipo_descricao);
        }

        $equipamento = Equipamento::create([
            'id_erp' => null, // manual — não vendido por nós
            'local_id' => $localId,
            'tipo' => $this->tipo,
            'fabricante' => trim($this->fabricante) ?: null,
            'modelo' => trim($this->modelo) ?: null,
            'numero_serie' => trim($this->numero_serie) ?: null,
            'cliente_final' => trim($this->cliente_final) ?: null,
            'localizacao_instalacao' => trim($this->localizacao_instalacao) ?: null,
            'estado' => $this->estado,
            'data_instalacao' => $this->data_instalacao ?: null,
            'fim_garantia' => $this->fim_garantia ?: null,
            'notas' => trim($this->notas) ?: null,
            'proxima_troca_baterias' => $proximaTroca,
            'atributos' => $atributos ?: null,
        ]);

        session()->flash('sucesso', 'Equipamento registado.');

        return redirect()->route('equipamentos.ficha', $equipamento);
    }

    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    private function clientesFiltrados(): Collection
    {
        if (trim($this->clienteBusca) === '') {
            return collect();
        }

        $termo = '%' . $this->clienteBusca . '%';
        $nomeNorm = '%' . $this->normalizarBusca($this->clienteBusca) . '%';

        return Cliente::query()
            ->where(fn ($q) => $q->whereRaw(self::NOME_SEM_ACENTOS . ' like ?', [$nomeNorm])
                ->orWhere('nif', 'ilike', $termo))
            ->orderBy('nome')
            ->limit(20)
            ->get(['id', 'nome', 'nif']);
    }

    public function render()
    {
        $locais = $this->cliente_id
            ? Local::where('cliente_id', $this->cliente_id)->orderBy('designacao')->get(['id', 'designacao'])
            : collect();

        return view('livewire.equipamentos.novo', [
            'clientesFiltrados' => $this->clientesFiltrados(),
            'locais' => $locais,
            'tipos' => TipoEquipamento::selecionaveis(),
            'estados' => EstadoEquipamento::cases(),
        ]);
    }
}
