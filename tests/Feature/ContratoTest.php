<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Livewire\Contratos\Ficha;
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
            // Contrato novo (rascunho): abre o popup ativar/suspender em vez de sair já.
            ->assertSet('modalEstado', true)
            ->assertNoRedirect()
            ->call('decidirEstado', 'rascunho')
            ->assertRedirect();

        $contrato = Contrato::where('numero', '2026/0042')->firstOrFail();
        $this->assertSame(EstadoContrato::Rascunho, $contrato->estado);
        $this->assertCount(1, $contrato->equipamentos);
        $this->assertCount(1, $contrato->slas);
    }

    // Alertas de visita programados: linhas data + TEXTO EDITÁVEL, gravadas com o contrato;
    // a edição reabre com as linhas e removê-las apaga-as.
    public function test_programa_alertas_de_visita_com_texto_editavel(): void
    {
        [$cliente, $equip] = $this->clienteComEquipamento();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Editor::class)
            ->set('numero', '2026/0077')
            ->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', $this->modeloFaturacaoId())
            ->set('equipamentoIds', [$equip->id])
            ->call('adicionarAlertaVisita')
            ->set('alertasVisita.0.data', now()->addMonths(3)->toDateString())
            ->set('alertasVisita.0.texto', 'Agendar visita do 1.º trimestre')
            ->call('guardar')
            ->assertHasNoErrors();

        $contrato = Contrato::where('numero', '2026/0077')->firstOrFail();
        $this->assertCount(1, $contrato->alertasVisita);
        $this->assertSame('Agendar visita do 1.º trimestre', $contrato->alertasVisita->first()->texto);

        // Reabrir mostra a linha; sem data não grava; remover apaga.
        Livewire::actingAs($admin)
            ->test(Editor::class, ['contrato' => $contrato])
            ->assertSet('alertasVisita.0.texto', 'Agendar visita do 1.º trimestre')
            ->call('adicionarAlertaVisita')
            ->call('guardar')
            ->assertHasErrors('alertasVisita.1.data') // linha nova sem data → erro claro
            ->call('removerAlertaVisita', 1)
            ->call('removerAlertaVisita', 0)
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertCount(0, $contrato->fresh()->alertasVisita);
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

    public function test_selecionar_todos_e_limpar_equipamentos(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $ids = collect(range(1, 4))
            ->map(fn ($i) => Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => "SN-$i"])->id)
            ->all();

        $c = Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('selecionarCliente', $cliente->id);

        // Selecionar todos → equipamentoIds = todos os do cliente (só inteiros).
        $c->call('selecionarTodosEquipamentos');
        $this->assertSame(collect($ids)->sort()->values()->all(), collect($c->get('equipamentoIds'))->sort()->values()->all());

        // Limpar → vazio.
        $c->call('limparEquipamentos')->assertSet('equipamentoIds', []);
    }
}
