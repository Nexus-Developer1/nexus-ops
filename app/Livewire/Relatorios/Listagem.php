<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoRelatorio;
use App\Models\Relatorio;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Relatórios'])]
class Listagem extends Component
{
    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $estado = '';

    // Tipo de relatório: '' (todos) | 'contrato' | 'individual'. Distingue-se pelo
    // intervencao.contrato_id (preenchido = de contrato; null = individual).
    #[Url]
    public string $tipo = '';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function filtrarEstado(string $estado): void
    {
        $this->estado = $estado;
        $this->resetPage();
    }

    public function filtrarTipo(string $tipo): void
    {
        $this->tipo = $tipo;
        $this->resetPage();
    }


    // Soft delete (marca deleted_at) — recuperável; nunca DELETE físico nem apaga o PDF.
    // Se o relatório está ligado a um evento de agenda, apaga-se a unidade toda
    // (relatório + intervenção + evento) — sai da agenda e não deixa intervenção órfã.
    // ENVIADO nunca é apagado (documento já entregue ao cliente, como na edição).
    public function eliminar(int $relatorio): void
    {
        $relatorio = Relatorio::with('intervencao.eventoAgenda')->findOrFail($relatorio);

        // Guarda de servidor (além de esconder o botão na UI): enviado é imutável.
        if ($relatorio->estado === EstadoRelatorio::Enviado) {
            session()->flash('erro', "O relatório {$relatorio->numero} já foi enviado ao cliente e não pode ser eliminado.");

            return;
        }

        $rotulo = $relatorio->numero ?? 'rascunho';
        $intervencao = $relatorio->intervencao;
        $evento = $intervencao?->eventoAgenda;

        DB::transaction(function () use ($relatorio, $intervencao, $evento) {
            $relatorio->delete();

            // Ligado à agenda → apaga também a intervenção e o evento (rascunho e finalizado
            // comportam-se igual: não fica intervenção órfã nem evento fantasma).
            if ($evento) {
                $intervencao?->delete();
                $evento->delete();
            }
        });

        session()->flash('sucesso', "Relatório {$rotulo} eliminado.");
    }

    public function render()
    {
        $relatorios = Relatorio::query()
            ->with('intervencao.equipamento.local.cliente', 'intervencao.tecnico')
            ->when($this->estado, fn ($q) => $q->where('estado', $this->estado))
            ->when($this->tipo === 'contrato', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNotNull('contrato_id')))
            ->when($this->tipo === 'individual', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNull('contrato_id')))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero', 'ilike', $termo)
                        ->orWhereHas('intervencao.equipamento.local.cliente', fn ($q) => $q->where('nome', 'ilike', $termo))
                        ->orWhereHas('intervencao.tecnico', fn ($q) => $q->where('nome', 'ilike', $termo));
                });
            })
            ->orderByDesc('data')
            ->paginate(10);

        return view('livewire.relatorios.listagem', [
            'relatorios' => $relatorios,
            'estados' => EstadoRelatorio::cases(),
        ]);
    }
}
