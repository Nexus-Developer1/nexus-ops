<?php

namespace App\Livewire\Equipamentos;

use App\Models\Equipamento;
use App\Models\Local;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Associa um equipamento EXISTENTE a um local (atualiza local_id). Os equipamentos
// vêm do import e não são criados/editados na app — aqui só se define o seu local.
//
// Pesquisa SERVER-SIDE (mesmo padrão do editor de relatórios): nunca carrega os ~17k
// equipamentos nem os ~600 locais para o cliente — só os resultados de cada pesquisa.
#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Associar equipamento'])]
class Associar extends Component
{
    public ?int $equipamento_id = null;
    public ?int $local_id = null;

    // Veio da ficha de um equipamento → equipamento fixo, escolhe-se só o local.
    public bool $equipamentoFixo = false;

    // Pesquisa server-side (associação livre): texto do equipamento e do local.
    public string $equipamentoBusca = '';
    public string $localBusca = '';

    public function mount(?Equipamento $equipamento = null): void
    {
        if ($equipamento && $equipamento->exists) {
            $this->equipamento_id = $equipamento->id;
            $this->local_id = $equipamento->local_id;
            $this->equipamentoFixo = true;

            // Pré-preenche a caixa do local com o local já associado (sem carregar todos).
            $local = $equipamento->local()->with('cliente')->first();
            $this->localBusca = $local ? $this->rotuloLocal($local) : '';
        }
    }

    // Modo livre: escolhe o equipamento na pesquisa (fixa o id e mostra o rótulo).
    public function selecionarEquipamento(int $id): void
    {
        $equipamento = Equipamento::with('local.cliente')->find($id);
        if (! $equipamento) {
            return;
        }
        $this->equipamento_id = $equipamento->id;
        $this->equipamentoBusca = $this->rotuloEquipamento($equipamento);
    }

    // Escrever na caixa do equipamento desfaz a seleção (até escolher um da lista).
    public function updatedEquipamentoBusca(): void
    {
        $this->equipamento_id = null;
    }

    public function selecionarLocal(int $id): void
    {
        $local = Local::with('cliente')->find($id);
        if (! $local) {
            return;
        }
        $this->local_id = $local->id;
        $this->localBusca = $this->rotuloLocal($local);
    }

    public function updatedLocalBusca(): void
    {
        $this->local_id = null;
    }

    public function guardar()
    {
        $validado = $this->validate([
            'equipamento_id' => ['required', 'integer', 'exists:equipamentos,id'],
            'local_id' => ['required', 'integer', 'exists:locais,id'],
        ]);

        $equipamento = Equipamento::findOrFail($validado['equipamento_id']);
        $equipamento->update(['local_id' => $validado['local_id']]);

        session()->flash('sucesso', 'Equipamento associado ao local com sucesso.');

        return redirect()->route('equipamentos.ficha', $equipamento);
    }

    // Rótulo de um equipamento (cliente · tipo modelo (nº série)) — para reconhecer o certo.
    private function rotuloEquipamento(Equipamento $e): string
    {
        return ($e->local?->cliente?->nome ?? '—') . ' · ' . trim($e->tipo->rotulo() . ' ' . $e->modelo) . ' (' . ($e->numero_serie ?? '—') . ')';
    }

    // Rótulo de um local (cliente · designação).
    private function rotuloLocal(Local $l): string
    {
        return ($l->cliente?->nome ?? '—') . ' · ' . $l->designacao;
    }

    // Dobra de acentos para pesquisa (igual aos outros comboboxes server-side).
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    // Pesquisa server-side de equipamentos (nº série / fabricante+modelo sem acentos), limitada.
    // Vazia sem texto — NUNCA carrega os ~17k equipamentos de uma vez.
    private function equipamentosFiltrados(): Collection
    {
        if (trim($this->equipamentoBusca) === '') {
            return collect();
        }

        $termo = '%' . $this->equipamentoBusca . '%';
        $norm = '%' . $this->normalizarBusca($this->equipamentoBusca) . '%';
        $semAcentos = "translate(lower(coalesce(fabricante, '') || ' ' || coalesce(modelo, '')), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return Equipamento::query()
            ->with('local.cliente')
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->where('numero_serie', 'ilike', $termo)
                    ->orWhereRaw($semAcentos . ' like ?', [$norm]);
            })
            ->orderBy('numero_serie')
            ->limit(30)
            ->get();
    }

    // Pesquisa server-side de locais (designação sem acentos / nome do cliente), limitada.
    // Vazia sem texto — NUNCA carrega os ~600 locais de uma vez.
    private function locaisFiltrados(): Collection
    {
        if (trim($this->localBusca) === '') {
            return collect();
        }

        $termo = '%' . $this->localBusca . '%';
        $norm = '%' . $this->normalizarBusca($this->localBusca) . '%';
        $semAcentos = "translate(lower(designacao), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return Local::query()
            ->with('cliente')
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->whereRaw($semAcentos . ' like ?', [$norm])
                    ->orWhereHas('cliente', fn ($c) => $c->where('nome', 'ilike', $termo));
            })
            ->orderBy('designacao')
            ->limit(30)
            ->get();
    }

    public function render()
    {
        // Equipamento fixo (veio da ficha): carrega SÓ esse, não os 17k para firstWhere um.
        $equipamentoAtual = $this->equipamento_id
            ? Equipamento::with('local.cliente')->find($this->equipamento_id)
            : null;

        return view('livewire.equipamentos.associar', [
            'equipamentoAtual' => $equipamentoAtual,
            'equipamentosFiltrados' => $this->equipamentosFiltrados(),
            'locaisFiltrados' => $this->locaisFiltrados(),
        ]);
    }
}
