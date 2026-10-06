<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Enviar;
use App\Mail\RelatorioParaCliente;
use App\Mail\ServicoParaFaturar;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comercial;
use App\Models\Dossier;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Aviso ao COMERCIAL no envio do relatório (out. 2026): opção na página de envio; email escrito
// à mão ou escolhido da lista (comerciais já usados, ligados ao vendedor PHC do cliente). Sai
// com o relatório — também nos envios agendados — e leva o nº da(s) encomenda(s) de peças.
class AvisoComercialFaturarTest extends TestCase
{
    use RefreshDatabase;

    private User $rui;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->rui = User::create(['nome' => 'Rui Pereira', 'email' => 'rpereira@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private static int $seq = 0;

    private function relatorio(?int $vendedor = 5): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'POLY LANEMA LDA', 'email' => 'artur.santos@lanema.pt', 'ativo' => true,
            'id_erp' => (string) (1234 + self::$seq++), 'vendedor' => $vendedor, 'vendnm' => $vendedor ? 'João Ferreira' : null]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $e = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-21']);
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'tecnico_id' => $this->rui->id]);

        $numero = '2026/'.str_pad((string) (20 + self::$seq), 4, '0', STR_PAD_LEFT);

        return Relatorio::create(['intervencao_id' => $i->id, 'numero' => $numero, 'data' => now(),
            'estado' => 'finalizado', 'pdf_path' => 'relatorios/2026-0021.pdf']);
    }

    private function comEncomendas(Relatorio $r): void
    {
        $d = Dossier::create(['id_erp' => 'D-1', 'ndos' => 1, 'nmdos' => 'Encomenda Peças', 'obrano' => 123, 'ano' => 2026, 'data' => now(), 'cliente_no' => 1234, 'nome' => 'POLY LANEMA LDA']);
        $r->intervencao->encomendas()->attach($d->id);
        $r->intervencao->encomendasManuais()->create(['obrano' => 45, 'ano' => 2026, 'criado_por' => $this->rui->id]);
    }

    public function test_opcao_desligada_por_defeito_e_mostra_as_encomendas_ao_ligar(): void
    {
        $r = $this->relatorio();
        $this->comEncomendas($r);

        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $r])
            ->assertSet('avisarComercial', false)
            ->assertSee('Avisar o comercial de que o serviço pode ser faturado')
            ->assertDontSee('Encomenda Peças 123/2026')
            ->set('avisarComercial', true)
            ->assertSee('Vendedor deste cliente no PHC: João Ferreira')
            ->assertSee('Encomenda Peças 123/2026')
            ->assertSee('Encomenda Peças 45/2026 (ainda por chegar do PHC)');
    }

    public function test_comercial_vai_para_o_job_e_fica_na_lista_ligado_ao_vendedor(): void
    {
        Queue::fake();
        $r = $this->relatorio(vendedor: 5);

        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $r])
            ->set('avisarComercial', true)
            ->set('comercial', ' Vendas@NXS.pt ')
            ->call('enviar')->assertHasNoErrors();

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->comercial === 'vendas@nxs.pt');
        $this->assertSame(5, Comercial::where('email', 'vendas@nxs.pt')->value('vendedor_phc'));

        // Próximo relatório de um cliente do MESMO vendedor: o email já vem preenchido.
        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $this->relatorio(vendedor: 5)])
            ->assertSet('comercial', 'vendas@nxs.pt')
            ->assertSet('avisarComercial', false)       // a opção continua desligada por defeito
            ->set('avisarComercial', true)
            ->assertSeeHtml('<option value="vendas@nxs.pt">'); // e está na lista de sugestões
    }

    public function test_sem_a_opcao_o_comercial_nao_e_avisado_nem_validado(): void
    {
        Queue::fake();
        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $this->relatorio()])
            ->set('comercial', 'isto-nao-e-email')
            ->call('enviar')->assertHasNoErrors();

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->comercial === null);
        $this->assertSame(0, Comercial::count());
    }

    public function test_com_a_opcao_o_email_e_obrigatorio_e_valido(): void
    {
        Queue::fake();
        $r = $this->relatorio(vendedor: null);

        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $r])
            ->set('avisarComercial', true)->set('comercial', '')
            ->call('enviar')->assertHasErrors('comercial');
        Livewire::actingAs($this->rui)->test(Enviar::class, ['relatorio' => $r])
            ->set('avisarComercial', true)->set('comercial', 'vendas@nxs.pt; mau-email')
            ->call('enviar')->assertHasErrors('comercial');

        Queue::assertNothingPushed();
    }

    public function test_job_avisa_o_comercial_com_as_encomendas_e_o_pdf(): void
    {
        Mail::fake();
        $r = $this->relatorio();
        $this->comEncomendas($r);

        (new EnviarRelatorioPorEmail($r, 'artur.santos@lanema.pt', 'A', 'M', 'rpereira@nxs.pt', null, 'vendas@nxs.pt'))
            ->handle(app(GeradorRelatorio::class));

        Mail::assertSent(RelatorioParaCliente::class);
        Mail::assertSent(ServicoParaFaturar::class, function (ServicoParaFaturar $m) {
            $html = $m->render();

            return $m->hasTo('vendas@nxs.pt')
                && $m->hasSubject('Serviço para faturar — Relatório '.$m->relatorio->numero.' · POLY LANEMA LDA')
                && $m->encomendas === ['Encomenda Peças 123/2026', 'Encomenda Peças 45/2026 (ainda por chegar do PHC)']
                && str_contains($html, 'pode ser faturado')
                && str_contains($html, 'Encomenda Peças 123/2026')
                && str_contains($html, 'Rui Pereira')          // quem enviou
                && count($m->attachments()) === 1;               // o PDF do relatório
        });
        $this->assertTrue(Auditoria::where('acao', 'relatorio_comercial_avisado')->exists());
    }

    public function test_sem_encomenda_o_aviso_diz_que_nao_ha(): void
    {
        Mail::fake();
        $r = $this->relatorio();

        (new EnviarRelatorioPorEmail($r, 'a@b.pt', 'A', 'M', null, null, 'vendas@nxs.pt'))->handle(app(GeradorRelatorio::class));

        Mail::assertSent(ServicoParaFaturar::class, fn (ServicoParaFaturar $m) => $m->encomendas === []
            && str_contains($m->render(), 'Sem encomenda de peças associada'));
    }

    public function test_envio_agendado_e_cancelado_nao_avisa_o_comercial(): void
    {
        Mail::fake();
        $r = $this->relatorio();
        $r->update(['envio_agendado_em' => now()->addHour(), 'envio_agendado_token' => 'tok-novo', 'envio_agendado_destino' => 'a@b.pt']);

        // Job de um agendamento que já foi substituído: não envia nada — nem ao comercial.
        (new EnviarRelatorioPorEmail($r, 'a@b.pt', 'A', 'M', null, 'tok-velho', 'vendas@nxs.pt'))->handle(app(GeradorRelatorio::class));

        Mail::assertNothingSent();
    }
}
