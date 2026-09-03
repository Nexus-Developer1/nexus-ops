<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// A data do relatório é a data da INTERVENÇÃO, não a de quando o rascunho nasceu: um relatório
// pré-criado a partir de um evento da agenda ficava com o dia do agendamento, e era esse que
// aparecia na listagem e no PDF.
class RelatorioDataIntervencaoTest extends TestCase
{
    use RefreshDatabase;

    private function equipamento(): Equipamento
    {
        $cliente = Cliente::create(['nome' => 'BNP PARIBAS', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);

        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-D']);
    }

    public function test_rascunho_pre_criado_fica_com_a_data_da_intervencao(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00'); // dia em que o evento foi agendado
        $i = Intervencao::create(['equipamento_id' => $this->equipamento()->id, 'tipo' => 'preventiva',
            'estado' => 'planeada', 'data_inicio' => '2026-09-02']);

        $r = $i->garantirRascunho();

        $this->assertSame('2026-09-02', $r->data->toDateString()); // não 31/08
    }

    public function test_sem_data_na_intervencao_usa_hoje(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $i = Intervencao::create(['equipamento_id' => $this->equipamento()->id, 'tipo' => 'preventiva', 'estado' => 'planeada']);

        $this->assertSame('2026-08-31', $i->garantirRascunho()->data->toDateString());
    }

    public function test_gravar_o_relatorio_alinha_a_data_com_a_da_intervencao(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $tecnico = User::create(['nome' => 'Daniel', 'email' => 'd@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $equip = $this->equipamento();

        // Rascunho antigo, com a data do dia em que nasceu (como os que já existem).
        $i = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada']);
        $r = Relatorio::create(['intervencao_id' => $i->id, 'data' => '2026-08-31', 'estado' => 'rascunho']);

        // O técnico abre-o e escreve a data real da intervenção.
        Livewire::actingAs($tecnico)->test(Novo::class, ['relatorio' => $r])
            ->set('data', '2026-09-02')
            ->set('tecnicoIds', [$tecnico->id])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertSame('2026-09-02', $r->fresh()->data->toDateString());
        $this->assertSame('2026-09-02', $i->fresh()->data_inicio->toDateString());

        // E o PDF mostra a mesma data.
        $html = view('pdf.relatorio', ['relatorio' => $r->fresh()->load('intervencao'), 'fotos' => []])->render();
        $this->assertStringContainsString('02/09/2026', $html);
        // 31/08 só pode sobrar no rodapé ("Documento gerado em"), nunca como data do trabalho.
        $this->assertSame(1, substr_count($html, '31/08/2026'));
        $this->assertMatchesRegularExpression('/Documento gerado em 31\/08\/2026/', $html);
    }

    public function test_relatorio_criado_pelo_gerador_leva_a_data_da_intervencao(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $i = Intervencao::create(['equipamento_id' => $this->equipamento()->id, 'tipo' => 'preventiva',
            'estado' => 'concluida', 'data_inicio' => '2026-09-02', 'data_fim' => '2026-09-02']);

        $r = app(GeradorRelatorio::class)->criarParaIntervencao($i);

        $this->assertSame('2026-09-02', $r->data->toDateString());
        $this->assertNotNull($r->numero);
    }
}
