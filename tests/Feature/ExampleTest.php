<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Um visitante não autenticado é encaminhado para o login.
     */
    public function test_visitante_anonimo_e_redirecionado_para_login(): void
    {
        $response = $this->get('/');

        // '/' → dashboard (protegido) → login.
        $response->assertRedirect();
        $this->followRedirects($response)->assertSee('login', false);
    }
}
