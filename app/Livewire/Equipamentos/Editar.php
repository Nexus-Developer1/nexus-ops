<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoEquipamento;
use App\Enums\TipoEquipamento;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Equipamento;
use App\Services\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Editar equipamento'])]
class Editar extends Component
{
    use ApenasEquipa;

    public Equipamento $equipamento;

    public array $form = [];

    public function mount(Equipamento $equipamento): void
    {
        $this->equipamento = $equipamento->load('local.cliente');
        foreach (['fabricante', 'modelo', 'numero_serie', 'familia', 'cliente_final', 'localizacao_instalacao', 'notas'] as $campo) {
            $this->form[$campo] = $equipamento->$campo ?? '';
        }
        $this->form['tipo'] = $equipamento->tipo->value;
        $this->form['estado'] = $equipamento->estado->value;
        $this->form['tipo_descricao'] = $equipamento->atributos['tipo_descricao'] ?? '';
        $this->form['data_instalacao'] = $equipamento->data_instalacao?->toDateString() ?? '';
        $this->form['fim_garantia'] = $equipamento->fim_garantia?->toDateString() ?? '';
    }

    public function guardar()
    {
        // Relê a origem e os atributos: o browser não decide quais os campos editáveis,
        // nem um formulário antigo pode voltar a gravar um equipamento eliminado.
        DB::transaction(function () {
            $equipamento = Equipamento::lockForUpdate()->findOrFail($this->equipamento->id);
            $manual = blank($equipamento->id_erp);
            $tipos = array_column(TipoEquipamento::selecionaveis(), 'value');
            $tipos[] = $equipamento->tipo->value; // permite manter tipos legados (PDU).

            $regras = [
                'form.tipo' => ['required', Rule::in($tipos)],
                'form.estado' => ['required', Rule::enum(EstadoEquipamento::class)],
                'form.tipo_descricao' => [Rule::requiredIf(($this->form['tipo'] ?? '') === 'diversos'), 'nullable', 'string', 'max:255'],
                'form.cliente_final' => ['nullable', 'string', 'max:255'],
                'form.localizacao_instalacao' => ['nullable', 'string', 'max:255'],
                'form.fim_garantia' => ['nullable', 'date'],
                'form.notas' => ['nullable', 'string', 'max:5000'],
            ];

            if ($manual) {
                $regras += [
                    'form.fabricante' => ['nullable', 'string', 'max:255'],
                    'form.modelo' => ['nullable', 'string', 'max:255'],
                    'form.numero_serie' => ['nullable', 'string', 'max:255'],
                    'form.data_instalacao' => ['nullable', 'date'],
                    // Equipamentos antigos podem manter uma família vazia ou já fora do catálogo.
                    'form.familia' => ['nullable', 'string', 'max:255', ...(($this->form['familia'] ?? '') !== ($equipamento->familia ?? '')
                        ? [Rule::exists('artigos', 'familia')]
                        : [])],
                ];
            }

            $dados = $this->validate($regras)['form'];
            $descricao = trim($dados['tipo_descricao'] ?? '');
            unset($dados['tipo_descricao']);
            foreach ($dados as $campo => $valor) {
                $valor = is_string($valor) ? trim($valor) : $valor;
                $dados[$campo] = $valor === '' ? null : $valor;
            }

            if ($manual && ($dados['familia'] ?? null) !== $equipamento->familia) {
                $dados['faminome'] = empty($dados['familia']) ? null : DB::table('artigos')
                    ->where('familia', $dados['familia'])->whereNotNull('faminome')->value('faminome');
            }

            // Conserva bancos, componentes e especificações editados na ficha.
            $atributos = $equipamento->atributos ?? [];
            if ($dados['tipo'] === 'diversos') {
                $atributos['tipo_descricao'] = $descricao;
            } else {
                unset($atributos['tipo_descricao']);
            }
            $dados['atributos'] = $atributos ?: null;
            $equipamento->update($dados);
            Auditor::registar('equipamento_editado', $equipamento, ['campos' => array_keys($equipamento->getChanges())]);
        });

        session()->flash('sucesso', 'Equipamento atualizado.');

        return $this->redirect(route('ativos'), navigate: true);
    }

    public function render()
    {
        $tipos = TipoEquipamento::selecionaveis();
        if (! in_array($this->equipamento->tipo, $tipos, true)) {
            $tipos[] = $this->equipamento->tipo;
        }

        return view('livewire.equipamentos.editar', [
            'manual' => blank($this->equipamento->id_erp),
            'tipos' => $tipos,
            'estados' => EstadoEquipamento::cases(),
            'familias' => DB::table('artigos')->whereNotNull('familia')->whereNotNull('faminome')
                ->select('familia', 'faminome')->distinct()->orderBy('faminome')->get(),
        ]);
    }
}
