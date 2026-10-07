<?php

namespace Tests\Feature;

use App\Enums\EstadoDespesa;
use App\Enums\PapelUtilizador;
use App\Livewire\Clientes\Caderno;
use App\Models\CadernoPagina;
use App\Models\CadernoSeparador;
use App\Models\Cliente;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Services\Despesas\FluxoAprovacaoDespesas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// 28.ª revisão de segurança (out. 2026) — duas corridas entre pedidos simultâneos:
//  · caderno: duas gravações sobre a mesma versão da página — a segunda escrevia por cima;
//  · despesas: dois aprovadores ao mesmo tempo — decidiam os dois (emails a dobrar, e uma
//    aprovação total por cima de uma parcial ficava «Aprovada» com linhas recusadas).
// A corrida simula-se mudando a BD entre a leitura e a escrita (o que o outro pedido faria).
class Revisao28SegurancaTest extends TestCase
{
    use RefreshDatabase;

    private User $rui;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['despesas.aprovadores' => ['pgouveia@nxs.pt'], 'despesas.notificar' => [], 'despesas.notificar_aprovacao' => []]);
        $this->rui = User::create(['nome' => 'Rui Pereira', 'email' => 'rpereira@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_caderno_duas_gravacoes_na_mesma_versao_so_uma_acerta(): void
    {
        $cliente = Cliente::create(['nome' => 'BBS', 'ativo' => true]);
        $pagina = CadernoSeparador::create(['cliente_id' => $cliente->id, 'nome' => 'SPI'])->paginas()->create(['titulo' => 'CCTV']);
        $componente = Livewire::actingAs($this->rui)->test(Caderno::class, ['cliente' => $cliente]);

        // O outro pedido grava a versão 0 → 1 logo depois de este ler a página (ainda versão 0).
        $feito = false;
        CadernoPagina::retrieved(function (CadernoPagina $p) use (&$feito) {
            if (! $feito) {
                $feito = true;
                DB::table('caderno_paginas')->where('id', $p->id)->update(['versao' => 1, 'conteudo' => '<div>IP 192.168.1.10</div>']);
            }
        });

        $componente->call('guardarConteudo', $pagina->id, '<div>texto antigo</div>', 0)
            ->assertReturned(fn ($r) => $r['ok'] === false);

        $this->assertSame('<div>IP 192.168.1.10</div>', $pagina->fresh()->conteudo); // não foi por cima
        $this->assertSame(1, $pagina->fresh()->versao);
    }

    public function test_despesa_decidida_por_outro_entretanto_nao_se_decide_outra_vez(): void
    {
        $paulo = User::create(['nome' => 'Paulo Gouveia', 'email' => 'pgouveia@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $registo = RegistoDespesa::create(['criado_por' => $this->rui->id]);
        $a = $registo->despesas()->create(['data' => '2026-09-25', 'descricao' => 'Bnp', 'categoria' => 'Refeições', 'valor' => 92.90, 'pago_por' => 'tecnico']);
        $registo->despesas()->create(['data' => '2026-09-26', 'descricao' => 'Bnp', 'categoria' => 'Refeições', 'valor' => 20.50, 'pago_por' => 'tecnico']);
        $registo->refresh();
        $this->assertSame(EstadoDespesa::Pendente, $registo->estado);

        // Enquanto esta decisão vinha a caminho, o outro aprovador aprovou tudo.
        DB::table('registos_despesa')->where('id', $registo->id)->update(['estado' => EstadoDespesa::Aprovada->value]);

        $fluxo = app(FluxoAprovacaoDespesas::class);
        foreach ([
            fn () => $fluxo->decidirParcial($registo, $paulo, [$a->id => 'Sem recibo']),
            fn () => $fluxo->decidir($registo, $paulo, aprovar: false, motivo: 'Errado'),
        ] as $decisao) {
            try {
                $decisao();
                $this->fail('Decidiu um registo que já não estava pendente.');
            } catch (\LogicException $e) {
                $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
            }
        }

        $this->assertSame(EstadoDespesa::Aprovada, $registo->fresh()->estado);
        $this->assertFalse((bool) $a->fresh()->recusada);
        Notification::assertNothingSent();
    }
}
