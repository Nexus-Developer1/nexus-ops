<?php

namespace Tests\Feature;

use App\Enums\EstadoDespesa;
use App\Enums\PapelUtilizador;
use App\Livewire\Despesas\Editor;
use App\Livewire\Despesas\Ficha;
use App\Models\Auditoria;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Notifications\DespesaDecidida;
use App\Services\Despesas\FluxoAprovacaoDespesas;
use App\Services\Despesas\PdfRegistoDespesas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Aprovação PARCIAL das despesas (out. 2026): o aprovador recusa algumas linhas (com motivo) e
// aprova as outras → «Aprovada parcialmente», fechada como uma aprovada. Emails da aprovação; a
// contabilidade recebe SÓ as linhas aprovadas.
class DespesaAprovacaoParcialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        Notification::fake();
        Storage::fake();
        config([
            'despesas.aprovadores' => ['pgouveia@nxs.pt'],
            'despesas.notificar' => ['financeiro@nxs.pt'],
            'despesas.notificar_aprovacao' => ['contabilidade@nxs.pt'],
        ]);
    }

    private function utilizador(string $nome, string $email): User
    {
        return User::create(['nome' => $nome, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    // Registo de DUAS linhas (92,90 € + 20,50 €), como o do pedido da equipa.
    private function registo(User $quem): RegistoDespesa
    {
        Livewire::actingAs($quem)->test(Editor::class)
            ->set('linhas.0.dia', '2026-09-25')->set('linhas.0.descricao', 'Bnp')->set('linhas.0.categoria', 'Refeições')
            ->set('linhas.0.refeicao_tipo', 'J')->set('linhas.0.pago_por', 'tecnico')->set('linhas.0.valor', '92.90')
            ->set('recibosLinhaUpload.0', [UploadedFile::fake()->image('r1.jpg', 800, 600)])
            ->call('adicionarLinha')
            ->set('linhas.1.dia', '2026-09-26')->set('linhas.1.descricao', 'Bnp')->set('linhas.1.categoria', 'Refeições')
            ->set('linhas.1.refeicao_tipo', 'J')->set('linhas.1.pago_por', 'tecnico')->set('linhas.1.valor', '20.50')
            ->set('recibosLinhaUpload.1', [UploadedFile::fake()->image('r2.jpg', 800, 600)])
            ->call('guardar')->assertHasNoErrors();

        return RegistoDespesa::latest('id')->firstOrFail();
    }

    public function test_o_aprovador_recusa_uma_linha_com_motivo_e_aprova_a_outra(): void
    {
        $rui = $this->utilizador('Rui Pereira', 'rpereira@nxs.pt');
        $paulo = $this->utilizador('Paulo Gouveia', 'pgouveia@nxs.pt');
        $registo = $this->registo($rui);
        [$linha1, $linha2] = $registo->linhasOrdenadas()->all();

        $ficha = Livewire::actingAs($paulo)->test(Ficha::class, ['registo' => $registo])
            ->assertSee('Aprovar')
            ->set("aprovarLinha.{$linha2->id}", false)
            ->assertSee('Aprovar parcialmente (92,90 € de 113,40 €)')
            ->call('aprovarParcialmente')
            ->assertHasErrors("motivosRecusa.{$linha2->id}");                    // sem motivo não passa
        $this->assertSame(EstadoDespesa::Pendente, $registo->fresh()->estado);

        $ficha->set("motivosRecusa.{$linha2->id}", 'Jantar já incluído no dia 25')
            ->call('aprovarParcialmente')
            ->assertHasNoErrors()
            ->assertSee('Recusada: Jantar já incluído no dia 25');

        $registo->refresh();
        $this->assertSame(EstadoDespesa::AprovadaParcialmente, $registo->estado);
        $this->assertSame($paulo->id, $registo->decidido_por);
        $this->assertFalse($linha1->fresh()->recusada);
        $this->assertTrue($linha2->fresh()->recusada);
        $this->assertSame('Jantar já incluído no dia 25', $linha2->fresh()->motivo_recusa);
        $this->assertSame(113.4, $registo->total());
        $this->assertSame(92.9, $registo->totalAprovado());
        $this->assertFalse($registo->podeSerEditado());                          // fechada como uma aprovada
        $this->assertSame(1, Auditoria::where('acao', 'despesa_aprovada_parcialmente')->count());

        // Emails: o colaborador vê a linha recusada e o motivo; a contabilidade só as aprovadas.
        Notification::assertSentTo($rui, DespesaDecidida::class, function (DespesaDecidida $n) {
            return $n->registo['estado'] === 'aprovada_parcial'
                && count($n->registo['linhas']) === 2
                && collect($n->registo['linhas'])->firstWhere('recusada', true)['motivo_recusa'] === 'Jantar já incluído no dia 25';
        });
        Notification::assertSentOnDemand(DespesaDecidida::class, function (DespesaDecidida $n, array $canais, AnonymousNotifiable $quem) {
            return ($quem->routes['mail'] ?? null) === 'contabilidade@nxs.pt'
                && $n->registo['so_aprovadas'] === true
                && count($n->registo['linhas']) === 1
                && $n->registo['total_aprovado'] === 92.9;
        });
        $email = (new DespesaDecidida(['id' => 1, 'colaborador' => 'Rui', 'estado' => 'aprovada_parcial', 'total' => 113.4, 'total_aprovado' => 92.9,
            'motivo' => null, 'decisor' => 'Paulo', 'decidido_em' => '30/09/2026 10:00', 'linhas' => [], 'url' => 'x']))->toMail($rui);
        $this->assertStringContainsString('APROVADA PARCIALMENTE', $email->subject);
        $this->assertStringContainsString('92,90 €', $email->subject);

        // A ficha e o PDF mostram o aprovado; a linha recusada sai riscada e fora dos totais.
        Livewire::actingAs($rui)->test(Ficha::class, ['registo' => $registo])
            ->assertSee('Aprovada parcialmente')->assertSee('aprovado')->assertSee('92,90 €');
        $html = app(PdfRegistoDespesas::class)->html($registo);
        $this->assertStringContainsString('RECUSADA:', $html);
        $this->assertStringContainsString('Total aprovado', $html);

        // Fechada: o editor não abre.
        Livewire::actingAs($rui)->test(Editor::class, ['registo' => $registo])->assertRedirect(route('despesas.registo.ficha', $registo));
    }

    public function test_regras_da_aprovacao_parcial(): void
    {
        $rui = $this->utilizador('Rui Pereira', 'rpereira@nxs.pt');
        $paulo = $this->utilizador('Paulo Gouveia', 'pgouveia@nxs.pt');
        $registo = $this->registo($rui);
        [$linha1, $linha2] = $registo->linhasOrdenadas()->all();
        $fluxo = app(FluxoAprovacaoDespesas::class);

        // Recusar TODAS → é «Rejeitar» (a UI avisa; o serviço recusa).
        Livewire::actingAs($paulo)->test(Ficha::class, ['registo' => $registo])
            ->set("aprovarLinha.{$linha1->id}", false)->set("aprovarLinha.{$linha2->id}", false)
            ->set("motivosRecusa.{$linha1->id}", 'x')->set("motivosRecusa.{$linha2->id}", 'y')
            ->call('aprovarParcialmente')
            ->assertHasErrors('aprovarLinha');

        foreach ([
            [],                                             // nada recusado
            [$linha1->id => 'a', $linha2->id => 'b'],       // tudo recusado
            [$linha2->id => '   '],                         // sem motivo
            [999999 => 'linha de outro registo'],           // id que não é deste registo
        ] as $recusadas) {
            try {
                $fluxo->decidirParcial($registo->fresh(), $paulo, $recusadas);
                $this->fail('Devia ter recusado: '.json_encode($recusadas));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Só o aprovador.
        $this->expectException(AuthorizationException::class);
        $fluxo->decidirParcial($registo->fresh(), $rui, [$linha2->id => 'x']);
    }

    public function test_quem_nao_aprova_nao_ve_as_caixas_de_aprovar(): void
    {
        $rui = $this->utilizador('Rui Pereira', 'rpereira@nxs.pt');
        $registo = $this->registo($rui);

        Livewire::actingAs($rui)->test(Ficha::class, ['registo' => $registo])
            ->assertDontSee('Aprovar parcialmente')
            ->assertDontSeeHtml('title="Aprovar esta linha"');
    }
}
