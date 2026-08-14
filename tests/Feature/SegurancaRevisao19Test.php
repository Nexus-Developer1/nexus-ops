<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Http\Middleware\SessaoValida;
use App\Livewire\Relatorios\Novo;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// 19.ª revisão de segurança: (1) a invalidação de sessão à mudança de password passa a
// cobrir as AÇÕES Livewire (/livewire/update), não só os GET de página — uma sessão roubada
// deixava de ser expulsa nas ações que interessam (guardar, eliminar, enviar); (2) mudar o
// relógio do SLA (pedido_em) deixa rasto na auditoria.
class SegurancaRevisao19Test extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_expulsa_sessao_stale_incluindo_pedido_livewire(): void
    {
        // O SessaoValida corre no grupo web (cobre /livewire/update). Testado em unidade
        // porque o harness do Livewire chama o componente sem passar pelo middleware HTTP.
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $admin->forceFill(['password_alterada_em' => now()])->save();
        $this->actingAs($admin);

        $mw = new SessaoValida;

        // Pedido Livewire (cabeçalho X-Livewire) com marca de sessão ANTERIOR à mudança → 403.
        $req = Request::create('/livewire/update', 'POST');
        $req->headers->set('X-Livewire', '1');
        $req->setLaravelSession(app('session.store'));
        $req->session()->put('autenticado_em', now()->subHour()->timestamp);
        $req->setUserResolver(fn () => $admin);

        $resposta = new Response('nunca chega aqui');
        try {
            $mw->handle($req, fn () => $resposta);
            $this->fail('Esperava 403 na sessão stale.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_middleware_deixa_passar_sessao_posterior_a_mudanca(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $admin->forceFill(['password_alterada_em' => now()->subHour()])->save();

        $mw = new SessaoValida;
        $req = Request::create('/dashboard', 'GET');
        $req->setLaravelSession(app('session.store'));
        $req->session()->put('autenticado_em', now()->timestamp); // login DEPOIS da mudança
        $req->setUserResolver(fn () => $admin);

        $passou = false;
        $mw->handle($req, function () use (&$passou) {
            $passou = true;

            return new Response('ok');
        });
        $this->assertTrue($passou);
    }

    public function test_alterar_pedido_em_fica_auditado(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SLA-A']);

        session(['autenticado_em' => now()->timestamp]);

        // 1.ª gravação CRIA a intervenção com pedido_em (não audita — é create).
        $comp = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tipo', 'corretiva')
            ->set('pedido_em', '2026-08-10T09:00')
            ->call('guardarRascunho')
            ->assertHasNoErrors();
        $this->assertSame(1, Intervencao::count());
        $this->assertFalse(Auditoria::where('acao', 'sla_pedido_em_alterado')->exists());

        // 2.ª gravação MUDA o relógio → fica auditado (de/para); continua 1 intervenção.
        $comp->set('pedido_em', '2026-08-11T15:00')
            ->call('guardarRascunho')->assertHasNoErrors();
        $this->assertSame(1, Intervencao::count());

        $this->assertSame('2026-08-11 15:00:00', Intervencao::firstOrFail()->pedido_em->toDateTimeString()); // update aplicou
        $registo = Auditoria::where('acao', 'sla_pedido_em_alterado')->firstOrFail();
        $this->assertSame('2026-08-10 09:00:00', $registo->detalhe['de']);
        $this->assertSame('2026-08-11 15:00:00', $registo->detalhe['para']);
    }

    public function test_pedido_em_no_futuro_e_recusado(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SLA-F']);

        // Finalizar com um pedido no futuro → erro de validação (before_or_equal:now).
        session(['autenticado_em' => now()->timestamp]);
        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tipo', 'corretiva')
            ->set('tecnicoIds', [$tecnico->id])
            ->set('pedido_em', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->set('finalizarComFichasVazias', true)
            ->call('finalizar')
            ->assertHasErrors(['pedido_em']);
    }
}
