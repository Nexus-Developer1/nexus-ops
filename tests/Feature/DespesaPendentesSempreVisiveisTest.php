<?php

namespace Tests\Feature;

use App\Enums\EstadoDespesa;
use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Listagem;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Uma despesa PENDENTE DE APROVAÇÃO aparece sempre na listagem, seja qual for o filtro — e em
// primeiro lugar. O aviso em cima diz que há despesas por aprovar; antes, se o período (ou a
// categoria, ou a pesquisa) as deixasse de fora, via-se o aviso e uma lista vazia.
class DespesaPendentesSempreVisiveisTest extends TestCase
{
    use RefreshDatabase;

    private User $paulo;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->paulo = User::create(['nome' => 'Paulo Gouveia', 'email' => 'pgouveia@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function registo(string $estado, string $data, string $descricao, string $categoria = 'Refeições'): RegistoDespesa
    {
        $registo = RegistoDespesa::create(['criado_por' => $this->paulo->id, 'estado' => $estado, 'submetido_em' => now()]);
        Despesa::create(['registo_despesa_id' => $registo->id, 'data' => $data, 'categoria' => $categoria,
            'descricao' => $descricao, 'valor' => '14.20', 'faturavel' => false, 'criado_por' => $this->paulo->id]);

        return $registo;
    }

    public function test_pendente_de_outro_mes_aparece_com_o_filtro_deste_mes(): void
    {
        $this->registo(EstadoDespesa::Pendente->value, '2026-08-05', 'Almoço por aprovar');   // mês passado
        $this->registo(EstadoDespesa::Aprovada->value, '2026-08-06', 'Portagem antiga');      // mês passado, fechada

        Livewire::actingAs($this->paulo)->test(Listagem::class)
            ->assertSet('periodo', 'mes')
            ->assertSee('Almoço por aprovar')      // pendente: passa à frente do filtro
            ->assertDontSee('Portagem antiga');    // o resto continua a obedecer ao período
    }

    public function test_pendente_nao_e_escondida_pela_categoria_nem_pela_pesquisa(): void
    {
        $this->registo(EstadoDespesa::Pendente->value, '2026-10-01', 'Gasóleo por aprovar', 'Combustível');

        Livewire::actingAs($this->paulo)->test(Listagem::class)
            ->set('categoria', 'Refeições')
            ->assertSee('Gasóleo por aprovar')
            ->set('categoria', '')
            ->set('pesquisa', 'algo-que-nao-existe')
            ->assertSee('Gasóleo por aprovar');
    }

    public function test_pendentes_aparecem_primeiro(): void
    {
        $this->registo(EstadoDespesa::Aprovada->value, '2026-10-01', 'Despesa recente fechada');
        $this->registo(EstadoDespesa::Pendente->value, '2026-08-05', 'Despesa antiga por aprovar');

        $html = Livewire::actingAs($this->paulo)->test(Listagem::class)->html();

        $this->assertLessThan(
            strpos($html, 'Despesa recente fechada'),
            strpos($html, 'Despesa antiga por aprovar'),
            'A pendente tem de vir à frente, mesmo sendo mais antiga.'
        );
    }

    public function test_sem_pendentes_os_filtros_mandam_na_mesma(): void
    {
        $this->registo(EstadoDespesa::Aprovada->value, '2026-08-05', 'Almoço do mês passado');

        Livewire::actingAs($this->paulo)->test(Listagem::class)
            ->assertDontSee('Almoço do mês passado')
            ->set('periodo', 'tudo')
            ->assertSee('Almoço do mês passado');
    }
}
