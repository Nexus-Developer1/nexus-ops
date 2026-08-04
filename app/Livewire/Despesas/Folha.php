<?php

namespace App\Livewire\Despesas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\FolhaDespesa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// Folha MENSAL de despesas do colaborador — grelha igual à folha impressa da empresa:
// uma linha por DIA, colunas fixas (Combustíveis, Outros, Hotel, Refeições, Táxi/Comboio/
// Avião, Outras) + descrição (cliente - localidade). Cada célula preenchida vira uma
// Despesa ligada à folha (folha_despesa_id) — entra nos KPIs como as avulsas.
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Folha de despesas'])]
class Folha extends Component
{
    use ApenasEquipa;
    use WithFileUploads;

    public FolhaDespesa $folha;

    // Recibos digitalizados a carregar (câmara do telemóvel ou galeria) — gravados
    // no object storage assim que chegam; a BD fica só com os metadados (CLAUDE.md §2).
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $recibosNovos = [];

    public string $matricula = '';
    public string $departamento = '';
    public string $adiantado = '';

    // linhas[dia] = ['descricao' => string, 'valores' => [índice da coluna => string]].
    // As colunas são indexadas (0..5) porque os nomes têm espaços/acentos — os índices
    // mapeiam para FolhaDespesa::COLUNAS.
    /** @var array<int, array{descricao: string, valores: array<int, string>}> */
    public array $linhas = [];

    public function mount(FolhaDespesa $folha): void
    {
        $this->folha = $folha;
        $this->matricula = $folha->matricula ?? '';
        $this->departamento = $folha->departamento ?? '';
        $this->adiantado = (float) $folha->adiantado > 0 ? (string) $folha->adiantado : '';
        $this->carregarLinhas();
    }

    private function carregarLinhas(): void
    {
        $porDia = $this->folha->despesas()->get()->groupBy(fn (Despesa $d) => (int) $d->data->format('j'));

        $linhas = [];
        for ($dia = 1; $dia <= $this->folha->diasDoMes(); $dia++) {
            $doDia = $porDia->get($dia, collect());
            $valores = [];
            foreach (FolhaDespesa::COLUNAS as $i => $coluna) {
                $soma = (float) $doDia->where('categoria', $coluna)->sum('valor');
                $valores[$i] = $soma > 0 ? number_format($soma, 2, '.', '') : '';
            }
            // Descrição do dia: a primeira que não seja o preenchimento automático (nome da coluna).
            $descricao = (string) ($doDia->first(fn (Despesa $d) => ! in_array($d->descricao, FolhaDespesa::COLUNAS, true))->descricao ?? '');
            $linhas[$dia] = ['descricao' => $descricao, 'valores' => $valores];
        }

        $this->linhas = $linhas;
    }

    // Upload imediato: cada recibo escolhido/tirado grava logo (nada se perde se a folha
    // não for guardada). Mesmos limites das fotos dos relatórios.
    public function updatedRecibosNovos(): void
    {
        $this->validate([
            'recibosNovos.*' => ['image', 'max:20480', 'dimensions:max_width=12000,max_height=12000'],
        ]);

        foreach ($this->recibosNovos as $ficheiro) {
            $key = $ficheiro->store('anexos/despesas/' . $this->folha->id);
            $this->folha->anexos()->create([
                'nome_ficheiro' => $ficheiro->getClientOriginalName() ?: 'recibo.jpg',
                'storage_key' => $key,
                'mime' => $ficheiro->getMimeType(),
                'tamanho' => $ficheiro->getSize(),
                'criado_por' => auth()->id(),
            ]);
        }

        $this->recibosNovos = [];
    }

    public function removerRecibo(int $anexoId): void
    {
        // Só recibos DESTA folha (um id forjado de outra entidade não é encontrado aqui).
        $anexo = $this->folha->anexos()->whereKey($anexoId)->firstOrFail();
        Storage::disk()->delete($anexo->storage_key);
        $anexo->delete();
    }

    public function guardar(): void
    {
        $this->validate([
            'matricula' => ['nullable', 'string', 'max:50'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'adiantado' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:255'],
        ]);

        // Valores das células: numéricos ≥ 0 (vazio = sem despesa nessa célula).
        foreach ($this->linhas as $dia => $linha) {
            foreach ($linha['valores'] ?? [] as $i => $valor) {
                $valor = trim((string) $valor);
                if ($valor !== '' && (! is_numeric($valor) || (float) $valor < 0)) {
                    $this->addError("linhas.$dia.valores.$i", 'Valor inválido (dia ' . $dia . ').');

                    return;
                }
            }
        }

        $this->folha->update([
            'matricula' => trim($this->matricula) ?: null,
            'departamento' => trim($this->departamento) ?: null,
            'adiantado' => trim($this->adiantado) !== '' ? (float) $this->adiantado : 0,
        ]);

        // Sincroniza as células com as despesas da folha: uma Despesa por (dia, coluna) com
        // valor > 0; célula esvaziada apaga; duplicados legados são consolidados na primeira.
        foreach ($this->linhas as $dia => $linha) {
            $data = Carbon::create($this->folha->ano, $this->folha->mes, $dia)->toDateString();
            $descricaoDia = trim((string) ($linha['descricao'] ?? ''));

            foreach (FolhaDespesa::COLUNAS as $i => $coluna) {
                $valor = trim((string) ($linha['valores'][$i] ?? ''));
                $daCelula = $this->folha->despesas()
                    ->whereDate('data', $data)
                    ->where('categoria', $coluna)
                    ->orderBy('id')
                    ->get();

                if ($valor === '' || (float) $valor == 0.0) {
                    $daCelula->each->delete(); // soft delete

                    continue;
                }

                $atributos = [
                    'valor' => (float) $valor,
                    'descricao' => $descricaoDia !== '' ? $descricaoDia : $coluna,
                ];

                if ($primeira = $daCelula->first()) {
                    $primeira->update($atributos);
                    $daCelula->skip(1)->each->delete(); // consolida duplicados na primeira
                } else {
                    $this->folha->despesas()->create($atributos + [
                        'data' => $data,
                        'categoria' => $coluna,
                        'faturavel' => false,
                        'criado_por' => auth()->id(),
                    ]);
                }
            }
        }

        $this->dispatch('folha-guardada'); // toast — fica na página (save de prevenção)
    }

    public function render()
    {
        // Totais calculados do que está NO ECRÃ (reflete edições ainda não gravadas).
        $totais = array_fill(0, count(FolhaDespesa::COLUNAS), 0.0);
        foreach ($this->linhas as $linha) {
            foreach ($linha['valores'] ?? [] as $i => $valor) {
                if (is_numeric($valor)) {
                    $totais[$i] += (float) $valor;
                }
            }
        }
        $total = array_sum($totais);
        $adiantado = is_numeric($this->adiantado) ? (float) $this->adiantado : 0.0;

        return view('livewire.despesas.folha', [
            'colunas' => FolhaDespesa::COLUNAS,
            'totais' => $totais,
            'total' => $total,
            'aDevolver' => max(0, $adiantado - $total),
            'aReceber' => max(0, $total - $adiantado),
            'recibos' => $this->folha->anexos()->orderBy('id')->get(),
        ]);
    }
}
