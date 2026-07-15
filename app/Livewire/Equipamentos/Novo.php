<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
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
    private const NOME_SEM_ACENTOS = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    private const LOCAL_PADRAO = 'Instalação principal';

    // Cliente (existente, pesquisa server-side) + local.
    public ?int $cliente_id = null;
    public string $clienteBusca = '';
    public ?int $local_id = null;

    // Dados do equipamento.
    public string $tipo = 'ups';
    public string $fabricante = '';
    public string $modelo = '';
    public string $numero_serie = '';
    public string $cliente_final = '';
    public string $localizacao_instalacao = '';
    public string $estado = 'operacional';
    public string $data_instalacao = '';
    public string $fim_garantia = '';
    public string $notas = '';

    // Especificações UPS (guardadas em atributos JSONB) + próxima troca de baterias (coluna própria).
    public string $potencia_kva = '';
    public string $topologia = '';
    public string $autonomia_min = '';
    public string $firmware = '';

    // Banco de baterias (parte do mesmo equipamento). Identidade nova em atributos; nº de baterias
    // e datas reutilizam os campos de bateria existentes.
    public string $banco_numero_serie = '';
    public string $banco_modelo = '';
    public string $banco_capacidade = '';
    public string $num_baterias = '';
    public string $data_baterias = '';
    public string $proxima_troca_baterias = '';

    // Componentes do sistema (ex.: sistema de deteção de incêndio) — { designacao, quantidade }.
    // Guardados em atributos.componentes. Útil para equipamentos compostos, sem nº de série.
    /** @var list<array{designacao: string, quantidade: string|int}> */
    public array $componentes = [];

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
            'tipo' => ['required', Rule::enum(TipoEquipamento::class)],
            'fabricante' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'numero_serie' => ['nullable', 'string', 'max:255'],
            'cliente_final' => ['nullable', 'string', 'max:255'],
            'localizacao_instalacao' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::enum(EstadoEquipamento::class)],
            'data_instalacao' => ['nullable', 'date'],
            'fim_garantia' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'potencia_kva' => ['nullable', 'numeric', 'min:0'],
            'topologia' => ['nullable', 'string', 'max:100'],
            'autonomia_min' => ['nullable', 'integer', 'min:0'],
            'firmware' => ['nullable', 'string', 'max:100'],
            'banco_numero_serie' => ['nullable', 'string', 'max:255'],
            'banco_modelo' => ['nullable', 'string', 'max:255'],
            'banco_capacidade' => ['nullable', 'string', 'max:100'],
            'num_baterias' => ['nullable', 'integer', 'min:0'],
            'data_baterias' => ['nullable', 'date'],
            'proxima_troca_baterias' => ['nullable', 'date'],
        ]);

        // Local: o escolhido ou a "Instalação principal" do cliente (criada se não existir — mesma
        // designação que o sync usa, para não criar locais paralelos).
        $localId = $this->local_id
            ?: Local::firstOrCreate(['cliente_id' => $this->cliente_id, 'designacao' => self::LOCAL_PADRAO])->id;

        // Especificações UPS → JSONB (só as preenchidas).
        $atributos = array_filter([
            'potencia_kva' => $this->potencia_kva !== '' ? (float) $this->potencia_kva : null,
            'topologia' => trim($this->topologia) ?: null,
            'autonomia_min' => $this->autonomia_min !== '' ? (int) $this->autonomia_min : null,
            'firmware' => trim($this->firmware) ?: null,
            'banco_numero_serie' => trim($this->banco_numero_serie) ?: null,
            'banco_modelo' => trim($this->banco_modelo) ?: null,
            'banco_capacidade' => trim($this->banco_capacidade) ?: null,
            'num_baterias' => $this->num_baterias !== '' ? (int) $this->num_baterias : null,
            'data_baterias' => $this->data_baterias ?: null,
        ], fn ($v) => $v !== null);

        // Componentes do sistema (lista { designacao, quantidade }), só linhas preenchidas.
        $componentes = $this->componentesNormalizados();
        if ($componentes !== []) {
            $atributos['componentes'] = $componentes;
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
            'proxima_troca_baterias' => $this->proxima_troca_baterias ?: null,
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
            'tipos' => TipoEquipamento::cases(),
            'estados' => EstadoEquipamento::cases(),
        ]);
    }
}
