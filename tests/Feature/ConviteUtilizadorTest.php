<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Auth\AceitarConvite;
use App\Livewire\Utilizadores\Adicionar;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Notifications\ConviteDefinirPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

// Convidar utilizadores: admin cria (técnico, sem password) + envia convite; o utilizador
// define a password por um link seguro (broker 'invites'). Não-admin não acede.
class ConviteUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'admin@nexus.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 'tec@nexus.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_admin_cria_tecnico_sem_password_e_dispara_convite(): void
    {
        Notification::fake();

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->set('nome', 'Nova Pessoa')
            ->set('email', 'nova@nexus.pt')
            ->call('convidar')
            ->assertHasNoErrors()
            ->assertRedirect(route('utilizadores.adicionar'));

        $novo = User::where('email', 'nova@nexus.pt')->firstOrFail();
        $this->assertSame(PapelUtilizador::Tecnico, $novo->papel); // nasce técnico
        $this->assertNull($novo->password);                        // sem password
        $this->assertTrue((bool) $novo->ativo);

        Notification::assertSentTo($novo, ConviteDefinirPassword::class);
    }

    public function test_lista_de_tecnicos_distingue_convite_pendente_de_aceite(): void
    {
        // Pendente: sem password (convite enviado, ainda não aceitou).
        User::create(['nome' => 'Pendente Silva', 'email' => 'pendente@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        // Aceite: com password (já definiu pelo link do convite).
        User::create(['nome' => 'Aceite Costa', 'email' => 'aceite@nexus.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        // Não-técnicos: não devem aparecer na lista.
        User::create(['nome' => 'Outro Admin', 'email' => 'outroadmin@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        User::create(['nome' => 'Cliente Zé', 'email' => 'clienteze@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->assertSee('Pendente Silva')
            ->assertSee('pendente@nexus.pt')
            ->assertSee('Convite pendente')
            ->assertSee('Aceite Costa')
            ->assertSee('aceite@nexus.pt')
            ->assertSee('Ativo')
            // Só técnicos: admins e clientes ficam de fora.
            ->assertDontSee('outroadmin@nexus.pt')
            ->assertDontSee('clienteze@nexus.pt');
    }

    public function test_admin_reenvia_convite_a_tecnico_pendente(): void
    {
        Notification::fake();
        $pendente = User::create(['nome' => 'Pendente Silva', 'email' => 'pendente@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->call('reenviar', $pendente->id)
            ->assertHasNoErrors();

        Notification::assertSentTo($pendente, ConviteDefinirPassword::class);
        $this->assertNull($pendente->fresh()->password); // continua pendente até aceitar
    }

    public function test_reenviar_nao_dispara_a_quem_ja_aceitou(): void
    {
        Notification::fake();
        $aceite = User::create(['nome' => 'Aceite Costa', 'email' => 'aceite@nexus.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->call('reenviar', $aceite->id)
            ->assertHasNoErrors();

        // Já tem password → nada é reenviado (guarda de negócio).
        Notification::assertNothingSent();
    }

    public function test_tecnico_nao_reenvia_convite(): void
    {
        Notification::fake();
        $pendente = User::create(['nome' => 'Pendente Silva', 'email' => 'pendente@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Um técnico não gere utilizadores: nem chega ao componente (403 no mount).
        Livewire::actingAs($this->tecnico())->test(Adicionar::class)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_admin_elimina_tecnico_e_preserva_historico(): void
    {
        $tecnico = User::create(['nome' => 'A Sair', 'email' => 'sair@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Trabalho do técnico: ao eliminar, a intervenção mantém-se (tecnico_id → null).
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'tecnico_id' => $tecnico->id,
        ]);

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->call('eliminar', $tecnico->id)
            ->assertHasNoErrors();

        $this->assertNull(User::find($tecnico->id));                       // conta apagada
        $this->assertNotNull($intervencao->fresh());                       // histórico preservado…
        $this->assertNull($intervencao->fresh()->tecnico_id);              // …sem técnico associado
    }

    public function test_eliminar_nao_apaga_um_admin(): void
    {
        $outroAdmin = User::create(['nome' => 'Chefe', 'email' => 'chefe@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        // O método só actua sobre técnicos → sobre um admin é no-op silencioso (não apaga).
        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->call('eliminar', $outroAdmin->id)
            ->assertHasNoErrors();

        $this->assertNotNull(User::find($outroAdmin->id)); // continua lá
    }

    public function test_tecnico_nao_elimina(): void
    {
        $alvo = User::create(['nome' => 'Alvo', 'email' => 'alvo@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Técnico nem chega ao componente (403 no mount) → não elimina ninguém.
        Livewire::actingAs($this->tecnico())->test(Adicionar::class)
            ->assertForbidden();

        $this->assertNotNull(User::find($alvo->id));
    }

    public function test_tecnico_leva_403_no_componente(): void
    {
        // Guarda no componente (mount) → 403 direto (não é só esconder o link).
        Livewire::actingAs($this->tecnico())->test(Adicionar::class)
            ->assertForbidden();
    }

    public function test_tecnico_que_adivinha_o_url_leva_403(): void
    {
        // GET real ao URL: o middleware admin,tecnico deixa-o chegar ao componente, que dá 403
        // pelo Gate 'gerir-utilizadores' (um técnico não gere utilizadores).
        $this->actingAs($this->tecnico())
            ->get(route('utilizadores.adicionar'))
            ->assertForbidden();
    }

    public function test_utilizador_sem_password_nao_faz_login(): void
    {
        $semPassword = User::create(['nome' => 'Sem Pass', 'email' => 'sempass@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->assertNull($semPassword->password);

        // Qualquer tentativa de login falha (o hasher rejeita hash nulo).
        $this->assertFalse(Auth::attempt(['email' => 'sempass@nexus.pt', 'password' => 'qualquer']));
        $this->assertFalse(Auth::attempt(['email' => 'sempass@nexus.pt', 'password' => '']));
        $this->assertGuest();
    }

    public function test_convite_define_password_depois_login_funciona_e_token_e_uso_unico(): void
    {
        Notification::fake();
        $novo = User::create(['nome' => 'Convidado', 'email' => 'conv@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Token real do broker de convites (imprevisível, guardado hasheado, uso único).
        $token = Password::broker('invites')->createToken($novo);

        Livewire::test(AceitarConvite::class, ['token' => $token])
            ->set('email', 'conv@nexus.pt')
            ->set('password', 'novaPass123')
            ->set('password_confirmation', 'novaPass123')
            ->call('definir')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        // Password definida → login passa a funcionar.
        $this->assertTrue(Auth::attempt(['email' => 'conv@nexus.pt', 'password' => 'novaPass123']));

        // Uso único: o MESMO token já não serve (foi apagado ao definir).
        Auth::logout();
        Livewire::test(AceitarConvite::class, ['token' => $token])
            ->set('email', 'conv@nexus.pt')
            ->set('password', 'outraPass456')
            ->set('password_confirmation', 'outraPass456')
            ->call('definir')
            ->assertHasErrors('email'); // token inválido/expirado
    }

    public function test_email_de_convite_usa_o_template_verde_do_site(): void
    {
        $user = User::create(['nome' => 'Rui Moreira', 'email' => 'rui@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        $mail = (new ConviteDefinirPassword('tok-123'))->toMail($user);

        // Usa a view HTML própria (não o markdown genérico).
        $this->assertSame('emails.convite', $mail->view);

        // Renderiza → tema verde do site + botão + link com o token + nome.
        $html = view($mail->view, $mail->viewData)->render();
        $this->assertStringContainsString('Definir palavra-passe', $html);
        $this->assertStringContainsString('#16a34a', $html);          // verde do site
        $this->assertStringContainsString('Nexus Infra', $html);
        $this->assertStringContainsString('convite/tok-123', $html);  // link seguro com o token
        $this->assertStringContainsString('Rui Moreira', $html);
    }

    public function test_email_duplicado_e_bloqueado(): void
    {
        Notification::fake();
        User::create(['nome' => 'Existente', 'email' => 'existe@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(Adicionar::class)
            ->set('nome', 'Outro')
            ->set('email', 'existe@nexus.pt')
            ->call('convidar')
            ->assertHasErrors(['email' => 'unique']);

        // Não criou um segundo registo com esse email.
        $this->assertSame(1, User::where('email', 'existe@nexus.pt')->count());
        Notification::assertNothingSent();
    }
}
