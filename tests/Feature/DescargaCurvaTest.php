<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

// Curva do teste de descarga importada do FICHEIRO do carregador (battest.txt): inserir o
// ficheiro na ficha UPS preenche fichas.{id}.descarga_curva e o gráfico desenha a curva
// completa (horas na vertical, como no Excel da equipa); a tabela manual é o último recurso.
class DescargaCurvaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    /** @return array{0: Contrato, 1: Equipamento} */
    private function contratoComUps(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $e = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1']);
        $contrato = Contrato::create(['numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);
        $contrato->equipamentos()->sync([$e->id]);

        return [$contrato, $e];
    }

    private function battest(int $amostras = 40): string
    {
        $c = "Date\tTime\tVbat+\tVbat-\tIbat+\tIbat-\tOutLoad\tBatCap\tBatTime\n";
        for ($i = 0; $i < $amostras; $i++) {
            $t = sprintf('20:%02d:%02d', intdiv($i, 60), $i % 60);
            $c .= "09/12/2025\t{$t}\t".(270.6 - $i * 0.4)."\t".(270.2 - $i * 0.4)."\t3.0\t3.0\t3.0\t100\t1176\t\n";
        }

        return $c;
    }

    public function test_inserir_o_ficheiro_preenche_a_curva_e_mostra_o_grafico(): void
    {
        [$contrato, $e] = $this->contratoComUps();

        $c = Livewire::actingAs($this->admin())->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)->call('selecionarTodosEquipamentos')
            ->set('descargaFicheiros.'.$e->id, UploadedFile::fake()->createWithContent('battest.txt', $this->battest()));

        $curva = $c->get('fichas')[$e->id]['descarga_curva'];
        $this->assertCount(40, $curva);
        $this->assertSame(['t' => '20:00:00', 'p' => 270.6, 'n' => 270.2], $curva[0]);

        // O gráfico aparece com as horas na vertical (modo curva) e sem pontos individuais.
        $c->assertSeeHtml('<polyline')->assertSeeHtml('rotate(-90');

        // Remover volta ao estado sem curva.
        $c->call('removerCurvaDescarga', $e->id)
            ->assertSet('fichas.'.$e->id.'.descarga_curva', []);
    }

    public function test_ficheiro_sem_leituras_da_erro_e_nao_mexe_na_ficha(): void
    {
        [$contrato, $e] = $this->contratoComUps();

        Livewire::actingAs($this->admin())->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)->call('selecionarTodosEquipamentos')
            ->set('descargaFicheiros.'.$e->id, UploadedFile::fake()->createWithContent('battest.txt', "isto não é um registo\nde teste nenhum\n"))
            ->assertHasErrors('descargaFicheiros.'.$e->id)
            ->assertSet('fichas.'.$e->id.'.descarga_curva', []);
    }

    public function test_curva_sobrevive_ao_saneamento_e_conta_como_conteudo(): void
    {
        $attrs = FichaMedicao::atributosDeFormulario([
            'descarga_curva' => [
                ['t' => '20:02:51', 'p' => '270.6', 'n' => '270.2'],
                ['t' => str_repeat('x', 50), 'p' => 269.4, 'n' => 269.2],
                ['t' => '20:02:53', 'p' => 'lixo', 'n' => 1],      // fora (p não numérico)
                'lixo',                                             // fora (não é array)
            ],
        ]);

        $this->assertSame([
            ['t' => '20:02:51', 'p' => 270.6, 'n' => 270.2],
            ['t' => str_repeat('x', 12), 'p' => 269.4, 'n' => 269.2], // t truncado
        ], $attrs['descarga_curva']);
        $this->assertTrue(FichaMedicao::temConteudo($attrs));

        // Vazia grava null e não conta como conteúdo por si.
        $this->assertNull(FichaMedicao::atributosDeFormulario([])['descarga_curva']);
    }

    public function test_pdf_prefere_a_curva_do_ficheiro_a_tabela(): void
    {
        [, $e] = $this->contratoComUps();
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        FichaMedicao::create(['intervencao_id' => $i->id, 'equipamento_id' => $e->id, 'tipo_equipamento' => 'ups',
            'marca' => 'Riello', 'modelo' => 'NPW', 'serie' => 'SN-1',
            'teste_descarga' => ['inicio' => ['vbat_pos' => '270'], '10' => ['vbat_pos' => '250']],
            'descarga_curva' => [
                ['t' => '20:02:51', 'p' => 270.6, 'n' => 270.2],
                ['t' => '20:02:52', 'p' => 270.0, 'n' => 269.6],
                ['t' => '20:02:53', 'p' => 269.4, 'n' => 269.2],
            ]]);
        $r = Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/9500', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        $html = view('pdf.relatorio', ['relatorio' => $r, 'fotos' => []])->render();

        $this->assertStringContainsString('rotate(-90', $html);      // horas na vertical → veio da curva
        $this->assertStringContainsString('20:02:53', $html);
        $this->assertStringNotContainsString('<circle', $html);      // modo curva não desenha pontos
        $this->assertSame(2, substr_count($html, '<polyline'));
    }
}
