<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// As listas de técnicos (agenda, legenda, escolhas dos relatórios, painel) só mostram quem tem a
// Nexus IFE no portal. As contas da Knowledgebase (SAT, Produção) têm papel técnico na conta e
// apareciam como técnicos (out. 2026). Sem as tabelas do portal a regra não se aplica.
class TecnicosComAcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('portal.tabelas-acessos');
    }

    protected function tearDown(): void
    {
        Cache::forget('portal.tabelas-acessos');
        parent::tearDown();
    }

    private function pessoa(string $nome, PapelUtilizador $papel = PapelUtilizador::Tecnico, bool $fazServicos = false): User
    {
        return User::create(['nome' => $nome, 'email' => uniqid().'@nxs.pt', 'password' => 'x', 'papel' => $papel, 'ativo' => true, 'faz_servicos' => $fazServicos]);
    }

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

    private function dar(User $u, int $aplicacao): void
    {
        DB::table('acessos')->insert(['utilizador_id' => $u->id, 'aplicacao_id' => $aplicacao]);
    }

    public function test_so_entra_nas_listas_de_tecnicos_quem_tem_a_ife_no_portal(): void
    {
        $this->comPortal();
        $joao = $this->pessoa('João Técnico');
        $this->dar($joao, 1);
        $chefe = $this->pessoa('Chefe que vai a serviços', PapelUtilizador::Admin, fazServicos: true);
        $this->dar($chefe, 1);
        $sat = $this->pessoa('SAT');
        $this->dar($sat, 2);   // só a Knowledgebase
        $producao = $this->pessoa('Produção');
        $this->dar($producao, 2);
        $semNada = $this->pessoa('Sem acessos');

        $nomes = fn (string $ambito) => User::query()->{$ambito}()->orderBy('nome')->pluck('nome')->all();

        $this->assertSame(['Chefe que vai a serviços', 'João Técnico'], $nomes('fazServicos'));
        $this->assertSame(['Chefe que vai a serviços', 'João Técnico'], $nomes('selecionavel'));
    }

    public function test_sem_o_portal_a_regra_nao_se_aplica(): void
    {
        $this->pessoa('João Técnico');
        $this->pessoa('SAT');

        $this->assertSame(['João Técnico', 'SAT'], User::query()->selecionavel()->orderBy('nome')->pluck('nome')->all());
    }
}
