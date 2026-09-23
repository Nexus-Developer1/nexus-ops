<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Contratos\Editor as EditorContrato;
use App\Livewire\Contratos\Listagem as ListagemContratos;
use App\Livewire\Despesas\Editor as EditorDespesas;
use App\Livewire\Relatorios\Novo;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Despesa;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Revisão de segurança de 16/09 — M6: ações destrutivas/oficiais que não deixavam rasto passam
// a ficar na auditoria (eliminar contrato, editar contrato, remover evento em cascata,
// finalizar relatório, apagar recibo gravado). M7: o seeder nunca reescreve o admin inicial
// nem o cria com «password» em produção.
class AuditoriaAcoesRevisao16Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $cliente;

    private Equipamento $ups;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Carbon::setTestNow('2026-08-10 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $this->cliente->id, 'designacao' => 'DC']);
        $this->ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-1']);
    }

    private function contrato(string $numero = '2026/0300'): Contrato
    {
        return Contrato::create(['numero' => $numero, 'cliente_id' => $this->cliente->id, 'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'), 'visitas_incluidas' => 4, 'periodo_aviso_dias' => 30, 'renovacao_automatica' => false]);
    }

    private function auditoria(string $acao): ?Auditoria
    {
        return Auditoria::where('acao', $acao)->latest('id')->first();
    }

    public function test_eliminar_contrato_fica_registado(): void
    {
        $c = $this->contrato();

        Livewire::actingAs($this->admin)->test(ListagemContratos::class)->call('eliminar', $c->id);

        $a = $this->auditoria('contrato_eliminado');
        $this->assertNotNull($a);
        $this->assertSame($c->id, $a->entidade_id);
        $this->assertSame('2026/0300', $a->detalhe['numero']);
        $this->assertSame($this->admin->id, $a->user_id);
    }

    public function test_editar_contrato_regista_os_campos_alterados(): void
    {
        $c = $this->contrato();

        Livewire::actingAs($this->admin)->test(EditorContrato::class, ['contrato' => $c])
            ->set('visitas_incluidas', 6)
            ->set('valor', '1500')
            ->call('guardar')
            ->assertHasNoErrors();

        $a = $this->auditoria('contrato_editado');
        $this->assertNotNull($a);
        $this->assertSame($c->id, $a->entidade_id);
        $this->assertSame('4', $a->detalhe['alteracoes']['visitas_incluidas']['de']);
        $this->assertSame('6', $a->detalhe['alteracoes']['visitas_incluidas']['para']);
        $this->assertArrayHasKey('valor', $a->detalhe['alteracoes']);
        $this->assertArrayNotHasKey('numero', $a->detalhe['alteracoes']); // não mudou → não aparece

        // Criar um contrato novo também fica registado.
        Livewire::actingAs($this->admin)->test(EditorContrato::class)
            ->set('numero', '2026/0301')->set('cliente_id', $this->cliente->id)
            ->set('data_inicio', now()->toDateString())->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->call('guardar')->assertHasNoErrors();
        $this->assertSame('2026/0301', $this->auditoria('contrato_criado')?->detalhe['numero']);
    }

    public function test_remover_evento_da_agenda_fica_registado_com_a_cascata(): void
    {
        $tecnico = $this->tecnicoDeTeste();
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-04', '2026-09-04')
            ->set('formTitulo', 'Intervenção')
            ->set('formEquipamentoId', $this->ups->id)
            ->set('formTecnicoIds', [$tecnico->id])
            ->set('formInicio', '2026-09-04T08:00')
            ->set('formFim', '2026-09-04T09:00')
            ->call('criarEvento')->assertHasNoErrors();
        $evento = EventoAgenda::firstOrFail();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('removerEvento');

        $this->assertSoftDeleted('eventos_agenda', ['id' => $evento->id]);
        $a = $this->auditoria('evento_removido');
        $this->assertNotNull($a);
        $this->assertSame($evento->id, $a->entidade_id);
        $this->assertSame('Intervenção', $a->detalhe['titulo']);
        $this->assertArrayHasKey('rascunho_apagado', $a->detalhe);
    }

    public function test_finalizar_relatorio_regista_o_numero_atribuido(): void
    {
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->set('equipamento_id', $this->ups->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$this->tecnicoDeTeste()->id])
            ->set('finalizarComFichasVazias', true)
            ->call('finalizar')
            ->assertHasNoErrors();

        $r = Relatorio::firstOrFail();
        $a = $this->auditoria('relatorio_finalizado');
        $this->assertNotNull($a);
        $this->assertSame($r->id, $a->entidade_id);
        $this->assertSame($r->numero, $a->detalhe['numero']);

        // Voltar a gravar um relatório já numerado não regista outra finalização.
        Livewire::actingAs($this->admin)->test(Novo::class, ['relatorio' => $r])
            ->set('finalizarComFichasVazias', true)
            ->call('finalizar')->assertHasNoErrors();
        $this->assertSame(1, Auditoria::where('acao', 'relatorio_finalizado')->count());
    }

    public function test_apagar_recibo_gravado_fica_registado(): void
    {
        Livewire::actingAs($this->admin)->test(EditorDespesas::class)
            ->set('linhas.0.dia', '2026-08-04')->set('linhas.0.descricao', 'ACME - Porto')
            ->set('linhas.0.categoria', 'Combustíveis')->set('linhas.0.pago_por', 'tecnico')->set('linhas.0.valor', '20.50')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('recibo.jpg', 800, 600)])
            ->call('guardar')->assertHasNoErrors();
        $despesa = Despesa::firstOrFail();
        $recibo = $despesa->anexos()->firstOrFail();

        Livewire::actingAs($this->admin)->test(EditorDespesas::class, ['registo' => $despesa->registo])
            ->call('removerReciboGravado', $recibo->id);

        $a = $this->auditoria('recibo_removido');
        $this->assertNotNull($a);
        $this->assertSame($despesa->registo_despesa_id, $a->entidade_id);
        $this->assertSame('recibo.jpg', $a->detalhe['ficheiro']);
        $this->assertSame($despesa->id, $a->detalhe['despesa_id']);
    }

    // ---- M7: seeder ----

    public function test_seeder_nunca_reescreve_um_admin_existente(): void
    {
        $existente = User::create(['nome' => 'Admin', 'email' => DatabaseSeeder::EMAIL_ADMIN, 'password' => 'Segura-123456', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('Segura-123456', $existente->fresh()->password)); // password intocada
        $this->assertSame(1, User::where('email', DatabaseSeeder::EMAIL_ADMIN)->count());
    }

    public function test_seeder_cria_o_admin_sem_password_previsivel(): void
    {
        $this->seed(DatabaseSeeder::class);

        $u = User::where('email', DatabaseSeeder::EMAIL_ADMIN)->firstOrFail();
        $this->assertFalse(Hash::check('password', $u->password));
        $this->assertSame(PapelUtilizador::Admin, $u->papel);
    }

    public function test_seeder_em_producao_exige_password_por_variavel_de_ambiente(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->isProduction());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('email', DatabaseSeeder::EMAIL_ADMIN)->count()); // recusou
    }
}
