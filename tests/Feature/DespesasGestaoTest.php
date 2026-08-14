<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Listagem;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Melhorias de gestão das despesas (custos de deslocação/serviço do técnico — sem cliente):
// pesquisa por colaborador (não por cliente inexistente), seletor de mês, totais por
// categoria e export CSV consolidado.
class DespesasGestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function despesa(User $colab, string $data, string $categoria, float $valor, string $descricao = 'Deslocação'): Despesa
    {
        $registo = RegistoDespesa::create(['criado_por' => $colab->id, 'departamento' => 'Técnica']);

        return $registo->despesas()->create([
            'data' => $data, 'categoria' => $categoria, 'valor' => $valor,
            'descricao' => $descricao, 'faturavel' => false, 'criado_por' => $colab->id,
        ]);
    }

    public function test_pesquisa_por_colaborador_e_nao_por_cliente(): void
    {
        $joao = User::create(['nome' => 'João Silva', 'email' => 'joao@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $rui = User::create(['nome' => 'Rui Costa', 'email' => 'rui@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->despesa($joao, now()->toDateString(), 'Combustíveis', 40, 'GASOLEO-A1');
        $this->despesa($rui, now()->toDateString(), 'Refeições', 12, 'ALMOCO-BRAGA');

        // Pesquisar "João" encontra a despesa dele; a do Rui não aparece.
        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('pesquisa', 'João')
            ->assertSee('GASOLEO-A1')
            ->assertDontSee('ALMOCO-BRAGA');
    }

    public function test_seletor_de_mes_filtra_o_periodo(): void
    {
        $tec = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->despesa($tec, now()->toDateString(), 'Hotel', 80, 'HOTEL-ESTE-MES');
        $this->despesa($tec, now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(), 'Hotel', 60, 'HOTEL-ANTIGO');

        $mesAntigo = now()->subMonthsNoOverflow(2)->format('Y-m');
        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->set('periodo', $mesAntigo)
            ->assertSee('HOTEL-ANTIGO')
            ->assertDontSee('HOTEL-ESTE-MES')
            ->assertViewHas('meses', fn ($m) => $m->contains($mesAntigo));
    }

    public function test_totais_por_categoria(): void
    {
        $tec = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->despesa($tec, now()->toDateString(), 'Combustíveis', 40);
        $this->despesa($tec, now()->toDateString(), 'Combustíveis', 35);
        $this->despesa($tec, now()->toDateString(), 'Refeições', 12);

        Livewire::actingAs($this->admin())->test(Listagem::class)
            ->assertViewHas('porCategoria', function ($pc) {
                $comb = $pc->firstWhere('categoria', 'Combustíveis');

                return (float) $comb->total === 75.0 && (int) $comb->n === 2;
            });
    }

    public function test_export_csv_do_periodo(): void
    {
        $tec = User::create(['nome' => 'Rui Costa', 'email' => 'rui@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->despesa($tec, now()->toDateString(), 'Táxi / Comboio / Avião', 25.5, 'COMBOIO-PORTO');

        $resposta = $this->actingAs($this->admin())->get(route('despesas.export', ['periodo' => 'mes']));
        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $resposta->streamedContent();
        $this->assertStringContainsString('Colaborador', $csv);      // cabeçalho
        $this->assertStringContainsString('Rui Costa', $csv);        // colaborador
        $this->assertStringContainsString('COMBOIO-PORTO', $csv);
        $this->assertStringContainsString('25,50', $csv);            // valor pt (vírgula)
    }

    public function test_export_barrado_ao_cliente_do_portal(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $userCliente = User::create(['nome' => 'C', 'email' => 'c@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);

        $this->actingAs($userCliente)->get(route('despesas.export'))->assertRedirect(route('portal.dashboard'));
    }
}
