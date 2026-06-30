<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Livewire\Contratos\Ficha;
use App\Livewire\Contratos\Listagem;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContratoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true,
        ]);
    }

    // Os modelos de faturação são semeados na migração; usamos o primeiro disponível.
    private function modeloFaturacaoId(): int
    {
        return ModeloFaturacao::query()->value('id');
    }

    private function clienteComEquipamento(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        return [$cliente, $equip];
    }

    public function test_listagem_mostra_contratos(): void
    {
        [$cliente] = $this->clienteComEquipamento();
        Contrato::create([
            'numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Ativo, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);

        $this->actingAs($this->admin())
            ->get('/contratos')
            ->assertOk()
            ->assertSee('2026/0001')
            ->assertSee('ACME');
    }

    public function test_cria_contrato_com_equipamentos_e_slas(): void
    {
        [$cliente, $equip] = $this->clienteComEquipamento();

        Livewire::actingAs($this->admin())
            ->test(Editor::class)
            ->set('numero', '2026/0042')
            ->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'full_service')
            ->set('modelo_faturacao_id', $this->modeloFaturacaoId())
            ->set('equipamentoIds', [$equip->id])
            ->set('slas', [['prioridade' => 'critica', 'tempo_resposta_horas' => 4, 'tempo_resolucao_horas' => 24, 'horario_cobertura' => '24x7']])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect();

        $contrato = Contrato::where('numero', '2026/0042')->firstOrFail();
        $this->assertSame(EstadoContrato::Rascunho, $contrato->estado);
        $this->assertCount(1, $contrato->equipamentos);
        $this->assertCount(1, $contrato->slas);
    }

    public function test_nao_ativa_sem_equipamento(): void
    {
        [$cliente] = $this->clienteComEquipamento();
        $contrato = Contrato::create([
            'numero' => '2026/0009', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Rascunho, $contrato->fresh()->estado);
    }

    public function test_ativa_contrato_com_equipamento(): void
    {
        [$cliente, $equip] = $this->clienteComEquipamento();
        $contrato = Contrato::create([
            'numero' => '2026/0010', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
    }
}
