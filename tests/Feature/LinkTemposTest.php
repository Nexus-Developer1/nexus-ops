<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Detalhe;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// "Ver tempos" nas fichas de contrato e de cliente: abre o consumo de horas na aplicação Tempos.
// Só aparece a administradores e só com TEMPOS_URL configurado (enquanto os Tempos não estiverem
// instalados, não há botão para lado nenhum).
class LinkTemposTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(PapelUtilizador $papel, string $email): User
    {
        return User::create(['nome' => 'Pessoa', 'email' => $email, 'password' => 'x', 'papel' => $papel, 'ativo' => true]);
    }

    private function contrato(): Contrato
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        return Contrato::create([
            'numero' => 'CT-1', 'cliente_id' => $cliente->id, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31',
            'estado' => 'ativo', 'tipo' => 'preventiva', 'renovacao_automatica' => false, 'periodo_aviso_dias' => 30,
        ]);
    }

    public function test_admin_ve_ver_tempos_no_contrato_e_no_cliente(): void
    {
        config(['app.tempos_url' => 'https://infra.exemplo.pt/tempos/']);
        $contrato = $this->contrato();
        $admin = $this->utilizador(PapelUtilizador::Admin, 'admin@nexus.pt');

        Livewire::actingAs($admin)->test(Ficha::class, ['contrato' => $contrato])
            ->assertSee('Ver tempos')
            ->assertSeeHtml('href="https://infra.exemplo.pt/tempos/relatorios/contratos/'.$contrato->id.'"');

        Livewire::actingAs($admin)->test(Detalhe::class, ['cliente' => $contrato->cliente])
            ->assertSee('Ver tempos')
            ->assertSeeHtml('href="https://infra.exemplo.pt/tempos/relatorios/clientes/'.$contrato->cliente_id.'"');
    }

    public function test_tecnico_nao_ve_o_botao(): void
    {
        config(['app.tempos_url' => 'https://infra.exemplo.pt/tempos']);
        $contrato = $this->contrato();
        $tecnico = $this->utilizador(PapelUtilizador::Tecnico, 'tec@nexus.pt');

        Livewire::actingAs($tecnico)->test(Ficha::class, ['contrato' => $contrato])->assertDontSee('Ver tempos');
        Livewire::actingAs($tecnico)->test(Detalhe::class, ['cliente' => $contrato->cliente])->assertDontSee('Ver tempos');
    }

    public function test_sem_tempos_url_nao_ha_botao(): void
    {
        config(['app.tempos_url' => null]);
        $contrato = $this->contrato();
        $admin = $this->utilizador(PapelUtilizador::Admin, 'admin@nexus.pt');

        Livewire::actingAs($admin)->test(Ficha::class, ['contrato' => $contrato])->assertDontSee('Ver tempos');
        Livewire::actingAs($admin)->test(Detalhe::class, ['cliente' => $contrato->cliente])->assertDontSee('Ver tempos');
    }
}
