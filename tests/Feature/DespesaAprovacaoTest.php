<?php

namespace Tests\Feature;

use App\Enums\EstadoDespesa;
use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Ficha;
use App\Livewire\Despesas\Listagem;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Notifications\DespesaDecidida;
use App\Notifications\DespesaSubmetida;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Processo de validação das despesas: recibo obrigatório por linha; guardar → pendente + email
// (criador, aprovador, financeiro); aprovador aprova/rejeita → email de decisão; rejeitada e
// corrigida volta a pendente; aprovada não se edita; alerta no dashboard; filtros na listagem.
class DespesaAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 10:00:00');
        Notification::fake();
        config(['despesas.aprovadores' => ['pgouveia@nxs.pt'], 'despesas.notificar' => ['pgouveia@nxs.pt', 'financeiro@nxs.pt']]);
    }

    private function tecnico(string $nome = 'João Silva', string $email = 'joao@nxs.pt'): User
    {
        return User::create(['nome' => $nome, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function aprovador(): User
    {
        return User::create(['nome' => 'Paulo Gouveia', 'email' => 'pgouveia@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    // Guarda um registo de UMA linha com recibo, como o utilizador faz no editor.
    private function registar(User $quem, string $descricao = 'Almoço ACME', string $valor = '14.20'): RegistoDespesa
    {
        Livewire::actingAs($quem)->test(Editor::class)
            ->set('linhas.0.dia', '2026-08-05')
            ->set('linhas.0.descricao', $descricao)
            ->set('linhas.0.categoria', 'Refeições')
            ->set('linhas.0.refeicao_tipo', 'A')
            ->set('linhas.0.valor', $valor)
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('recibo.jpg', 800, 600)])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('despesas'));

        return RegistoDespesa::latest('id')->firstOrFail();
    }

    public function test_recibo_e_obrigatorio_em_cada_linha(): void
    {
        Livewire::actingAs($this->tecnico())->test(Editor::class)
            ->set('linhas.0.dia', '2026-08-05')
            ->set('linhas.0.descricao', 'Gasóleo')
            ->set('linhas.0.categoria', 'Combustíveis')
            ->set('linhas.0.valor', '40')
            ->call('guardar')
            ->assertHasErrors('linhas.0.recibos');

        $this->assertSame(0, RegistoDespesa::count());
        Notification::assertNothingSent();
    }

    public function test_guardar_submete_e_avisa_criador_aprovador_e_financeiro(): void
    {
        $joao = $this->tecnico();
        $registo = $this->registar($joao);

        $this->assertSame(EstadoDespesa::Pendente, $registo->estado);
        $this->assertNotNull($registo->submetido_em);

        // Quem criou (conta) + os dois emails de config (sem conta → "on demand").
        Notification::assertSentTo($joao, DespesaSubmetida::class, function (DespesaSubmetida $n) use ($joao, $registo) {
            $html = (string) $n->toMail($joao)->render();

            return str_contains($html, 'Almoço ACME')
                && str_contains($html, '14,20')
                && str_contains($html, route('despesas.registo.ficha', $registo))
                && str_contains($html, 'aguarda aprovação')
                && str_contains($html, 'Nexus Infra')          // template proprio da app (tema verde)
                && ! str_contains($html, 'Regards');
        });
        Notification::assertSentOnDemand(DespesaSubmetida::class, fn ($n, $canais, $notifiable) => $notifiable->routes['mail'] === 'pgouveia@nxs.pt');
        Notification::assertSentOnDemand(DespesaSubmetida::class, fn ($n, $canais, $notifiable) => $notifiable->routes['mail'] === 'financeiro@nxs.pt');
        Notification::assertSentOnDemandTimes(DespesaSubmetida::class, 2);
    }

    public function test_criador_que_e_aprovador_nao_recebe_em_duplicado(): void
    {
        $paulo = $this->aprovador();
        $this->registar($paulo);

        Notification::assertSentTo($paulo, DespesaSubmetida::class);
        Notification::assertSentOnDemandTimes(DespesaSubmetida::class, 1); // só o financeiro
        Notification::assertSentOnDemand(DespesaSubmetida::class, fn ($n, $canais, $notifiable) => $notifiable->routes['mail'] === 'financeiro@nxs.pt');
    }

    public function test_so_aprovador_ou_admin_decide(): void
    {
        $joao = $this->tecnico();
        $registo = $this->registar($joao);

        // Um técnico qualquer não aprova (403) — nem vê os botões.
        Livewire::actingAs($this->tecnico('Rui', 'rui@nxs.pt'))->test(Ficha::class, ['registo' => $registo])
            ->assertDontSee('Confirmar rejeição')
            ->call('aprovar')
            ->assertForbidden();
        $this->assertSame(EstadoDespesa::Pendente, $registo->fresh()->estado);

        // O aprovador (pgouveia@nxs.pt) aprova → estado, quem, quando, auditoria e email.
        $paulo = $this->aprovador();
        Livewire::actingAs($paulo)->test(Ficha::class, ['registo' => $registo])
            ->assertSee('Aprovar')
            ->call('aprovar')
            ->assertHasNoErrors();

        $registo->refresh();
        $this->assertSame(EstadoDespesa::Aprovada, $registo->estado);
        $this->assertSame($paulo->id, $registo->decidido_por);
        $this->assertNotNull($registo->decidido_em);
        $this->assertDatabaseHas('auditoria', ['acao' => 'despesa_aprovada', 'entidade_id' => $registo->id]);

        Notification::assertSentTo($joao, DespesaDecidida::class, function (DespesaDecidida $n) use ($joao) {
            $html = (string) $n->toMail($joao)->render();

            return str_contains($html, 'APROVADA') && str_contains($html, 'Paulo Gouveia');
        });
        Notification::assertSentTo($paulo, DespesaDecidida::class);            // criador não, mas é destinatário de config
        Notification::assertSentOnDemandTimes(DespesaDecidida::class, 1);      // financeiro
    }

    public function test_admin_tambem_pode_aprovar(): void
    {
        $registo = $this->registar($this->tecnico());
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Livewire::actingAs($admin)->test(Ficha::class, ['registo' => $registo])->call('aprovar')->assertHasNoErrors();

        $this->assertSame(EstadoDespesa::Aprovada, $registo->fresh()->estado);
    }

    public function test_rejeitar_exige_motivo_e_a_correcao_volta_a_pendente(): void
    {
        $joao = $this->tecnico();
        $registo = $this->registar($joao);
        $paulo = $this->aprovador();

        $c = Livewire::actingAs($paulo)->test(Ficha::class, ['registo' => $registo]);
        $c->call('rejeitar')->assertHasErrors('motivo');
        $this->assertSame(EstadoDespesa::Pendente, $registo->fresh()->estado);

        $c->set('motivo', 'Falta o talão do almoço.')->call('rejeitar')->assertHasNoErrors();

        $registo->refresh();
        $this->assertSame(EstadoDespesa::Rejeitada, $registo->estado);
        $this->assertSame('Falta o talão do almoço.', $registo->motivo_rejeicao);
        Notification::assertSentTo($joao, DespesaDecidida::class, function (DespesaDecidida $n) use ($joao) {
            $html = (string) $n->toMail($joao)->render();

            return str_contains($html, 'REJEITADA') && str_contains($html, 'Falta o talão do almoço');
        });

        // O colaborador corrige e volta a guardar → pendente outra vez, decisão limpa, novo email.
        Livewire::actingAs($joao)->test(Editor::class, ['registo' => $registo])
            ->set('linhas.0.valor', '15.00')
            ->call('guardar')
            ->assertHasNoErrors();

        $registo->refresh();
        $this->assertSame(EstadoDespesa::Pendente, $registo->estado);
        $this->assertNull($registo->decidido_por);
        $this->assertNull($registo->motivo_rejeicao);
        Notification::assertSentToTimes($joao, DespesaSubmetida::class, 2);
    }

    public function test_despesa_aprovada_nao_se_edita(): void
    {
        $registo = $this->registar($this->tecnico());
        $registo->update(['estado' => EstadoDespesa::Aprovada]);

        // Editor manda para a ficha; guardar à força é recusado.
        Livewire::actingAs($this->tecnico('Rui', 'rui@nxs.pt'))->test(Editor::class, ['registo' => $registo])
            ->assertRedirect(route('despesas.registo.ficha', $registo));

        Livewire::actingAs($this->tecnico('Ana', 'ana@nxs.pt'))->test(Ficha::class, ['registo' => $registo])
            ->assertDontSee('Editar');
    }

    public function test_alerta_de_despesa_por_aprovar_com_link_para_a_ficha(): void
    {
        $joao = $this->tecnico();
        $registo = $this->registar($joao);

        $alertas = app(ServicoAlertas::class)->recolher()->where('tipo', 'despesa_aprovacao')->values();
        $this->assertCount(1, $alertas);
        $this->assertStringContainsString('João Silva', $alertas[0]['titulo']);
        $this->assertStringContainsString('14,20 €', $alertas[0]['titulo']);
        $this->assertSame(route('despesas.registo.ficha', $registo), $alertas[0]['url']);
        $this->assertSame('media', $alertas[0]['severidade']);

        // Pendente há mais de 7 dias → alta. Depois de decidida, some.
        $registo->update(['submetido_em' => now()->subDays(8)]);
        $this->assertSame('alta', app(ServicoAlertas::class)->recolher()->where('tipo', 'despesa_aprovacao')->first()['severidade']);

        $registo->update(['estado' => EstadoDespesa::Aprovada]);
        $this->assertCount(0, app(ServicoAlertas::class)->recolher()->where('tipo', 'despesa_aprovacao'));
    }

    public function test_listagem_filtra_por_estado_e_por_colaborador(): void
    {
        $joao = $this->tecnico();
        $rui = $this->tecnico('Rui Costa', 'rui@nxs.pt');
        $rJoao = $this->registar($joao, 'GASOLEO-JOAO');
        $rRui = $this->registar($rui, 'ALMOCO-RUI');
        $rRui->update(['estado' => EstadoDespesa::Aprovada]);

        $c = Livewire::actingAs($joao)->test(Listagem::class);
        $c->assertSee('GASOLEO-JOAO')->assertSee('ALMOCO-RUI')->assertSee('Pendente')->assertSee('Aprovada');

        $c->set('estado', 'pendente')->assertSee('GASOLEO-JOAO')->assertDontSee('ALMOCO-RUI');
        $c->set('estado', '')->set('colaborador', (string) $rui->id)->assertSee('ALMOCO-RUI')->assertDontSee('GASOLEO-JOAO');
    }

    public function test_ficha_acessivel_pela_rota_e_mostra_recibo(): void
    {
        $joao = $this->tecnico();
        $registo = $this->registar($joao);

        $this->actingAs($joao)->get(route('despesas.registo.ficha', $registo))
            ->assertOk()
            ->assertSee('Pendente de aprovação')
            ->assertSee('Almoço ACME')
            ->assertSee('anexos/'); // miniatura do recibo
    }
}
