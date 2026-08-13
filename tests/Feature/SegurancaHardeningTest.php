<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Auth\EsqueciPassword;
use App\Livewire\Concerns\ApenasEquipa;
use App\Livewire\Equipamentos\Novo as EquipamentoNovo;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Endurecimentos de segurança (revisão 2026-07-16): o trait ApenasEquipa barra o papel cliente
// em TODAS as requisições ao componente (não só via middleware da rota), e o "esqueci password"
// não revela se um email existe.
class SegurancaHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 'tec@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function cliente(): User
    {
        $c = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        return User::create(['nome' => 'Portal', 'email' => 'cli@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $c->id, 'ativo' => true]);
    }

    // Descobre TODOS os componentes de equipa (tudo em app/Livewire exceto Auth/*, Portal/* e os
    // traits em Concerns/*). É a lista que TEM de estar coberta pelo trait ApenasEquipa.
    /** @return list<class-string> */
    private function componentesDeEquipa(): array
    {
        $dir = app_path('Livewire');
        $classes = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $ficheiro) {
            $rel = str_replace(['/', '\\'], '\\', Str::of($ficheiro->getRealPath())
                ->after($dir.DIRECTORY_SEPARATOR)->beforeLast('.php')->toString());

            if (Str::startsWith($rel, ['Auth\\', 'Portal\\', 'Concerns\\'])) {
                continue;
            }

            $fqcn = 'App\\Livewire\\'.$rel;
            if (class_exists($fqcn) && is_subclass_of($fqcn, Component::class)) {
                $classes[] = $fqcn;
            }
        }

        sort($classes);

        return $classes;
    }

    // Garante que NENHUM componente de equipa fica sem o trait (uma adição futura sem o trait
    // falha aqui). class_uses_recursive apanha o trait mesmo que venha de um trait-de-trait.
    public function test_todos_os_componentes_de_equipa_usam_o_trait(): void
    {
        $semTrait = array_filter(
            $this->componentesDeEquipa(),
            fn (string $c) => ! in_array(ApenasEquipa::class, class_uses_recursive($c), true),
        );

        $this->assertSame([], array_values($semTrait),
            'Componentes de equipa sem ApenasEquipa: '.implode(', ', $semTrait));
    }

    // Invoca cada componente DIRETAMENTE (Livewire::test contorna o middleware da rota), por isso o
    // 403 prova que o guard vem do trait, não do middleware papel:admin,tecnico. O guard corre no
    // boot (antes do mount), por isso aborta mesmo os componentes que exigem parâmetros de rota.
    public function test_trait_apenas_equipa_bloqueia_cliente_em_todos_os_componentes(): void
    {
        $cliente = $this->cliente();

        foreach ($this->componentesDeEquipa() as $componente) {
            Livewire::actingAs($cliente);
            Livewire::test($componente)->assertForbidden();
        }
    }

    public function test_trait_apenas_equipa_deixa_passar_o_tecnico(): void
    {
        $this->actingAs($this->tecnico());

        // O técnico renderiza normalmente — o guard só barra clientes.
        Livewire::test(EquipamentoNovo::class)->assertOk();
        Livewire::test(Calendario::class)->assertOk();
    }

    public function test_esqueci_password_mostra_mensagem_neutra_para_email_inexistente(): void
    {
        Livewire::test(EsqueciPassword::class)
            ->set('email', 'ninguem@inexistente.pt')
            ->call('enviarLink')
            ->assertHasNoErrors()
            ->assertSet('estado', 'Se existir uma conta com esse email, enviámos um link para redefinir a palavra-passe.');
    }

    // Os cabeçalhos de segurança vêm agora da app (middleware versionado), não só do Apache.
    public function test_cabecalhos_de_seguranca_presentes_em_todas_as_respostas_web(): void
    {
        $resp = $this->get(route('login'));

        $csp = $resp->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP em falta na resposta.');
        // Diretivas-chave da política (anti-XSS/clickjacking).
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $resp->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $resp->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
