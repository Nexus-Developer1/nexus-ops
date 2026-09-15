<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Http\Middleware\SessaoValida;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// 22.ª revisão de segurança — o SessaoValida passa a expulsar, em todos os pedidos (página e
// ação Livewire): (1) contas DESATIVADAS com sessão aberta — o `ativo` só era olhado no login;
// (2) equipa a quem o portal RETIROU o módulo Nexus — o portal apaga a linha em `acessos` mas
// não muda o papel, e a Nexus só olhava para o papel. Sem as tabelas do portal (instalação
// sem portal) a segunda regra não se aplica.
class SegurancaRevisao22Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('portal.tabelas-acessos'); // o cache do array sobrevive entre testes
    }

    // As tabelas do portal criadas em comPortal() desaparecem com o rollback, mas o cache
    // «portal presente» ficaria a true para os testes seguintes — e rebentavam na consulta.
    protected function tearDown(): void
    {
        Cache::forget('portal.tabelas-acessos');
        parent::tearDown();
    }

    private function utilizador(PapelUtilizador $papel = PapelUtilizador::Tecnico, bool $ativo = true): User
    {
        return User::create(['nome' => 'X', 'email' => uniqid().'@nexus.pt', 'password' => 'x', 'papel' => $papel, 'ativo' => $ativo]);
    }

    private function pedido(User $u, bool $livewire = false): Request
    {
        $req = Request::create($livewire ? '/livewire/update' : '/relatorios', $livewire ? 'POST' : 'GET');
        if ($livewire) {
            $req->headers->set('X-Livewire', '1');
        }
        $req->setLaravelSession(app('session.store'));
        $req->session()->put('autenticado_em', now()->timestamp);
        $req->setUserResolver(fn () => $u);
        $this->actingAs($u);

        return $req;
    }

    private function passa(User $u): bool
    {
        $resposta = (new SessaoValida)->handle($this->pedido($u), fn () => new Response('ok'));

        return ! $resposta instanceof RedirectResponse;
    }

    // Tabelas do portal (migrações dele, mesma BD) — criadas só neste teste.
    private function comPortal(): void
    {
        Schema::create('aplicacoes', function (Blueprint $t) {
            $t->id();
            $t->string('chave');
            $t->string('nome');
        });
        Schema::create('acessos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('utilizador_id');
            $t->unsignedBigInteger('aplicacao_id');
        });
        DB::table('aplicacoes')->insert([['id' => 1, 'chave' => 'nexus-infra', 'nome' => 'Nexus IFE'], ['id' => 2, 'chave' => 'knowledgebase', 'nome' => 'KB']]);
        Cache::forget('portal.tabelas-acessos');
    }

    public function test_conta_desativada_e_expulsa_na_pagina_e_na_acao_livewire(): void
    {
        $u = $this->utilizador(ativo: false);

        $resposta = (new SessaoValida)->handle($this->pedido($u), fn () => new Response('nunca'));
        $this->assertInstanceOf(RedirectResponse::class, $resposta);
        $this->assertSame(route('login'), $resposta->getTargetUrl());
        $this->assertGuest();

        try {
            (new SessaoValida)->handle($this->pedido($u, livewire: true), fn () => new Response('nunca'));
            $this->fail('Esperava 403 na ação Livewire de uma conta desativada.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_conta_ativa_passa(): void
    {
        $this->assertTrue($this->passa($this->utilizador()));
        $this->assertTrue($this->passa($this->utilizador(PapelUtilizador::Admin)));
        $this->assertTrue($this->passa($this->utilizador(PapelUtilizador::Cliente)));
    }

    public function test_sem_tabelas_do_portal_o_acesso_ao_modulo_nao_e_verificado(): void
    {
        $this->assertFalse(SessaoValida::portalPresente());
        $this->assertTrue($this->passa($this->utilizador()));
    }

    public function test_com_portal_so_entra_quem_tem_o_modulo_nexus(): void
    {
        $this->comPortal();
        $this->assertTrue(SessaoValida::portalPresente());

        $com = $this->utilizador();
        $soKb = $this->utilizador();
        $sem = $this->utilizador(PapelUtilizador::Admin);
        $cliente = $this->utilizador(PapelUtilizador::Cliente);
        DB::table('acessos')->insert([
            ['utilizador_id' => $com->id, 'aplicacao_id' => 1],
            ['utilizador_id' => $soKb->id, 'aplicacao_id' => 2], // só Knowledgebase
        ]);

        $this->assertTrue($this->passa($com));
        $this->assertFalse($this->passa($soKb));   // técnico sem o módulo Nexus
        $this->assertFalse($this->passa($sem));    // admin sem qualquer acesso
        $this->assertTrue($this->passa($cliente)); // portal de cliente não usa `acessos`

        // Retirar o módulo no portal (apagar a linha) fecha a Nexus no pedido seguinte.
        DB::table('acessos')->where('utilizador_id', $com->id)->delete();
        $this->assertFalse($this->passa($com->fresh()));
    }
}
