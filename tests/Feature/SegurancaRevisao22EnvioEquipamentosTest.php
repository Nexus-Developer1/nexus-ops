<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Contratos\Editor as EditorContrato;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Relatorios\Enviar;
use App\Livewire\Relatorios\Novo;
use App\Mail\FalhaEnvioRelatorio;
use App\Mail\RelatorioParaCliente;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// 22.ª revisão de segurança — pontos 7 e 6.
// 7: o email leva EXATAMENTE a cópia congelada do PDF (antes voltava a ler o documento de
//    trabalho, que podia mudar entretanto); um relatório não é processado duas vezes em
//    simultâneo; um relatório que voltou a rascunho não sai.
// 6: equipamentos adicionais do relatório, equipamentos do contrato e bancos de baterias têm
//    de ser do MESMO cliente — antes só se validava «existe», e o PDF de um cliente podia levar
//    equipamento de outro.
class SegurancaRevisao22EnvioEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $acme;

    private Cliente $beta;

    private Equipamento $upsAcme;

    private Equipamento $upsBeta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->acme = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $this->beta = Cliente::create(['nome' => 'Beta', 'ativo' => true]);
        $localAcme = Local::create(['cliente_id' => $this->acme->id, 'designacao' => 'DC']);
        $localBeta = Local::create(['cliente_id' => $this->beta->id, 'designacao' => 'Sede']);
        $this->upsAcme = Equipamento::create(['local_id' => $localAcme->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'ACME-1']);
        $this->upsBeta = Equipamento::create(['local_id' => $localBeta->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'BETA-1']);
    }

    private function relatorio(string $estado = 'finalizado', string $numero = '2026/9700'): Relatorio
    {
        $i = Intervencao::create(['equipamento_id' => $this->upsAcme->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $pdf = 'relatorios/'.str_replace('/', '-', $numero).'.pdf';
        Storage::disk()->put($pdf, '%PDF-trabalho');

        return Relatorio::create(['intervencao_id' => $i->id, 'numero' => $numero, 'data' => now(), 'estado' => $estado, 'pdf_path' => $pdf]);
    }

    // ---- 7 ----

    public function test_email_leva_exatamente_a_copia_congelada(): void
    {
        Mail::fake();
        $r = $this->relatorio();

        (new EnviarRelatorioPorEmail($r, 'c@acme.pt', 'Assunto', 'Msg'))->handle(app(GeradorRelatorio::class));

        $r->refresh();
        $congelada = Storage::disk()->get($r->pdf_enviado_path);
        $this->assertSame('%PDF-trabalho', $congelada);
        $this->assertSame(hash('sha256', $congelada), $r->pdf_enviado_sha256);
        Mail::assertSent(RelatorioParaCliente::class, fn ($m) => $m->pdfConteudo === $congelada);
    }

    public function test_job_nao_corre_duas_vezes_em_simultaneo_para_o_mesmo_relatorio(): void
    {
        $r = $this->relatorio();
        $mw = (new EnviarRelatorioPorEmail($r, 'c@acme.pt', 'A', 'M'))->middleware();

        $this->assertCount(1, $mw);
        $this->assertInstanceOf(WithoutOverlapping::class, $mw[0]);
        $this->assertSame('relatorio-envio:'.$r->id, $mw[0]->key);
        $this->assertNotNull($mw[0]->releaseAfter); // o 2.º espera pela vez, não é descartado
    }

    public function test_relatorio_que_voltou_a_rascunho_nao_sai_nem_na_fila_nem_na_pagina(): void
    {
        Mail::fake();
        $r = $this->relatorio(); // finalizado quando foi posto na fila…
        $job = new EnviarRelatorioPorEmail($r, 'c@acme.pt', 'A', 'M');
        $r->update(['estado' => EstadoRelatorio::Rascunho]); // …reaberto antes de o worker pegar

        $job->handle(app(GeradorRelatorio::class));

        Mail::assertNothingSent();
        $this->assertSame(EstadoRelatorio::Rascunho, $r->fresh()->estado);
        $this->assertNull($r->fresh()->pdf_enviado_path);

        // Página de envio aberta antes de o relatório ser reaberto noutro separador.
        Queue::fake();
        $r2 = $this->relatorio(numero: '2026/9701');
        $c = Livewire::actingAs($this->admin)->test(Enviar::class, ['relatorio' => $r2])
            ->set('para', 'c@acme.pt')->set('assunto', 'A')->set('mensagem', 'M');
        $r2->update(['estado' => EstadoRelatorio::Rascunho]);
        $c->call('enviar')->assertRedirect(route('relatorios'));
        Queue::assertNothingPushed();
    }

    // ---- 6 ----

    public function test_relatorio_recusa_equipamento_adicional_de_outro_cliente(): void
    {
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->set('equipamento_id', $this->upsAcme->id)
            ->set('data', now()->toDateString())
            ->set('equipamentosCobertos', [$this->upsBeta->id])
            ->call('guardarRascunho')
            ->assertHasErrors('equipamentosCobertos.0');
        $this->assertSame(0, Intervencao::count());

        // Do mesmo cliente continua a passar.
        $outroAcme = Equipamento::create(['local_id' => $this->upsAcme->local_id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'ACME-2']);
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->set('equipamento_id', $this->upsAcme->id)
            ->set('data', now()->toDateString())
            ->set('equipamentosCobertos', [$outroAcme->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertSame([$outroAcme->id], Intervencao::firstOrFail()->equipamentosCobertos()->pluck('equipamentos.id')->all());
    }

    public function test_contrato_recusa_equipamento_de_outro_cliente(): void
    {
        $modelo = ModeloFaturacao::query()->value('id');
        $base = fn () => Livewire::actingAs($this->admin)->test(EditorContrato::class)
            ->set('numero', '2026/0900')
            ->set('cliente_id', $this->acme->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', $modelo);

        $base()->set('equipamentoIds', [$this->upsBeta->id])->call('guardar')->assertHasErrors('equipamentoIds.0');
        $base()->set('equipamentoIds', [$this->upsAcme->id])->call('guardar')->assertHasNoErrors();
    }

    public function test_banco_de_baterias_de_outro_cliente_nao_se_associa(): void
    {
        $bancoBeta = Equipamento::create(['local_id' => $this->upsBeta->local_id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'BB-BETA']);
        $bancoSemLocal = Equipamento::create(['local_id' => null, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'BB-SOLTO']);

        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $this->upsAcme])
            ->call('associarBanco', $bancoBeta->id)
            ->assertHasErrors('bancoBusca');
        $this->assertNull($bancoBeta->fresh()->equipamento_pai_id);

        // Um banco ainda «por associar» (sem local/cliente) pode ser ligado.
        Livewire::actingAs($this->admin)->test(Ficha::class, ['equipamento' => $this->upsAcme])
            ->call('associarBanco', $bancoSemLocal->id)
            ->assertHasNoErrors();
        $this->assertSame($this->upsAcme->id, $bancoSemLocal->fresh()->equipamento_pai_id);
    }

    // ---- M5 / M8 (revisão completa de 16/09) ----

    public function test_job_de_envio_tem_uma_so_tentativa_e_avisa_quem_enviou_quando_falha(): void
    {
        Mail::fake();
        $r = $this->relatorio();
        $job = new EnviarRelatorioPorEmail($r, 'c@acme.pt', 'A', 'M', 'tecnico@nexus.pt');

        $this->assertSame(1, $job->tries); // repetir = reenviar ao cliente

        $job->failed(new \RuntimeException('Graph 503'));

        $this->assertDatabaseHas('auditoria', ['acao' => 'relatorio_envio_falhou', 'entidade_id' => $r->id]);
        // Quem enviou (cc) e o suporte recebem o aviso; o cliente não recebe nada.
        Mail::assertSent(FalhaEnvioRelatorio::class, fn ($m) => $m->hasTo('tecnico@nexus.pt') && $m->hasTo(config('erp.email_sync')) && ! $m->hasTo('c@acme.pt'));
        Mail::assertNotSent(RelatorioParaCliente::class);
    }

    public function test_agenda_recusa_contrato_de_outro_cliente(): void
    {
        $contratoBeta = Contrato::create(['numero' => '2026/7100', 'cliente_id' => $this->beta->id, 'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);
        $inicio = now()->addWeek()->setTime(10, 0);

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->set('formTitulo', 'Preventiva')
            ->set('formEquipamentoId', $this->upsAcme->id) // equipamento da ACME…
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', (clone $inicio)->setTime(11, 0)->format('Y-m-d\TH:i'))
            ->set('formContratoId', $contratoBeta->id)      // …com contrato da Beta
            ->set('formCobertura', 'incluida')
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])
            ->call('criarEvento')
            ->assertHasErrors('formContratoId');

        $this->assertSame(0, EventoAgenda::count());
    }

    public function test_relatorio_recusa_equipamento_principal_de_outro_cliente(): void
    {
        // Individual com cliente já escolhido: o principal tem de ser desse cliente.
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->call('selecionarCliente', $this->acme->id)
            ->set('equipamento_id', $this->upsBeta->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasErrors('equipamento_id');
        $this->assertSame(0, Intervencao::count());

        // Modo contrato: o principal tem de ser do cliente do contrato.
        $contratoAcme = Contrato::create(['numero' => '2026/7101', 'cliente_id' => $this->acme->id, 'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);
        $contratoAcme->equipamentos()->sync([$this->upsAcme->id]);
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contratoAcme->id)
            ->set('equipamento_id', $this->upsBeta->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasErrors('equipamento_id');
        $this->assertSame(0, Intervencao::count());

        // Do cliente certo passa.
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->call('selecionarCliente', $this->acme->id)
            ->set('equipamento_id', $this->upsAcme->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertSame(1, Intervencao::count());
    }
}
