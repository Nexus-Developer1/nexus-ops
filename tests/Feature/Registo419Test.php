<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Tests\TestCase;

// Um 419 a meio de uma sessão viva é um sintoma: fica no log com o caminho e o componente,
// nunca com os tokens. (A 21/09 um técnico apanhou três seguidos na agenda sem rasto nenhum.)
//
// Nos testes o Laravel não verifica o CSRF, por isso a exceção é lançada por uma rota de
// ensaio — o que se prova aqui é o registo, não a verificação.
class Registo419Test extends TestCase
{
    use RefreshDatabase;

    public function test_um_419_no_livewire_fica_no_log_com_o_componente_e_sem_tokens(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        Route::post('/livewire/update', fn () => throw new TokenMismatchException('CSRF token mismatch.'))->middleware('web');

        Log::spy();

        $snapshot = json_encode(['memo' => ['name' => 'agenda.calendario']]);

        $this->actingAs($admin)
            ->withSession(['_token' => 'token-da-sessao'])
            ->withHeaders(['X-Livewire' => '1', 'X-CSRF-TOKEN' => 'token-errado'])
            ->postJson('/livewire/update', [
                'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'reagendar', 'params' => []]], 'updates' => ['formInicio' => '2026-10-05']]],
            ])
            ->assertStatus(419);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $mensagem, array $contexto) {
            return str_starts_with($mensagem, 'Resposta 419')
                && $contexto['caminho'] === 'livewire/update'
                && $contexto['livewire'] === true
                && $contexto['sessao_tem_utilizador'] === true
                && $contexto['pedido_traz_token'] === true
                && $contexto['tokens_iguais'] === false
                && $contexto['componentes'][0]['componente'] === 'agenda.calendario'
                && $contexto['componentes'][0]['metodos'] === ['reagendar']
                && $contexto['componentes'][0]['atualizacoes'] === ['formInicio']
                && ! str_contains(json_encode($contexto), 'token-errado')
                && ! str_contains(json_encode($contexto), 'token-da-sessao');
        });
    }

    public function test_o_419_de_versao_do_livewire_tambem_fica_no_log(): void
    {
        Route::post('/_ensaio-419', fn () => throw new LivewireReleaseTokenMismatchException)->middleware('web');

        Log::spy();

        $this->withSession(['_token' => 'x'])->post('/_ensaio-419')->assertStatus(419);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => str_starts_with($m, 'Resposta 419') && $c['caminho'] === '_ensaio-419' && $c['livewire'] === false && $c['componentes'] === []);
    }
}
