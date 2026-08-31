<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

// "Manter sessão iniciada": quem volta pelo cookie de lembrar entra sem passar pelo formulário
// e a sessão nascia SEM a marca `autenticado_em` — o SessaoValida tomava-a por anterior à
// última mudança de password e expulsava a pessoa a cada visita. O listener do evento Login
// (AppServiceProvider) marca o instante quando falta. Trazido do servidor para o git a 28/08.
class SessaoLembrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_por_lembrar_ganha_a_marca_e_nao_e_expulso(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        // A password mudou há uma hora — uma sessão SEM marca contaria como anterior a isto.
        $user->forceFill(['password_alterada_em' => now()->subHour()])->save();

        // Login "de lembrar" (sem formulário, sem MFA): dispara o evento Login sem marca prévia.
        $this->assertFalse(session()->has('autenticado_em'));
        Auth::login($user, remember: true);

        $this->assertTrue(session()->has('autenticado_em'));
        $this->assertGreaterThanOrEqual($user->password_alterada_em->timestamp, session('autenticado_em'));

        // Continua autenticado numa página protegida (antes: redirect para o login).
        $this->get('/equipamentos')->assertOk();
    }

    public function test_marca_existente_nao_e_reescrita_pelo_listener(): void
    {
        $user = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Marca antiga já na sessão (ex.: login pós-MFA de há um mês): o listener não a avança —
        // senão uma sessão antiga "rejuvenescia" a cada login e escapava à invalidação.
        session(['autenticado_em' => 1000]);
        Auth::login($user);

        $this->assertSame(1000, session('autenticado_em'));
    }
}
