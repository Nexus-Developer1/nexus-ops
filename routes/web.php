<?php

use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');

    // Segunda etapa do login (MFA por email): introduzir o código enviado.
    Route::get('/verificar-codigo', \App\Livewire\Auth\VerificarCodigo::class)->name('mfa.verificar');

    // Recuperação de palavra-passe (broker nativo do Laravel).
    Route::get('/esqueci-password', \App\Livewire\Auth\EsqueciPassword::class)->name('password.request');
    Route::get('/redefinir-password/{token}', \App\Livewire\Auth\RedefinirPassword::class)->name('password.reset');

    // Aceitar convite: definir a password de uma conta nova (broker 'invites', uso único).
    Route::get('/convite/{token}', \App\Livewire\Auth\AceitarConvite::class)->name('convite.definir');
});

// Feed iCal de um técnico — URL assinada, acessível por apps de calendário
// externas (sem sessão). CLAUDE.md §6.
Route::get('/agenda/ical/{tecnico}.ics', function (\App\Models\User $tecnico, \App\Services\Agenda\GeradorIcal $gerador) {
    return response($gerador->paraTecnico($tecnico))
        ->header('Content-Type', 'text/calendar; charset=utf-8')
        ->header('Content-Disposition', 'inline; filename="agenda-' . $tecnico->id . '.ics"');
})->middleware('signed')->name('agenda.ical');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Função partilhada: serve o PDF de um relatório (gera-o on-demand se necessário).
$servirPdf = function (\App\Models\Relatorio $relatorio, \App\Services\GeradorRelatorio $gerador) {
    $disco = \Illuminate\Support\Facades\Storage::disk();

    if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
        $gerador->gerarPdf($relatorio);
        $relatorio->refresh();
    }

    return response($disco->get($relatorio->pdf_path))
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
};

// ---- Gestão da operação (admin + técnico) — dashboard, contratos, alertas, ativos, despesas.
// O técnico tem as mesmas permissões que o admin, EXCETO gerir utilizadores (grupo abaixo). ----
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () {
    Route::get('/dashboard', \App\Livewire\DashboardGestao::class)->name('dashboard');

    Route::get('/ativos', \App\Livewire\Equipamentos\Listagem::class)->name('ativos');
    // Associar um equipamento existente a um local (rota literal ANTES de /ativos/{equipamento}).
    Route::get('/ativos/associar/{equipamento?}', \App\Livewire\Equipamentos\Associar::class)->name('equipamentos.associar');

    // Contratos (rota /novo declarada ANTES de /{contrato} para não colidir).
    Route::get('/contratos', \App\Livewire\Contratos\Listagem::class)->name('contratos');
    Route::get('/contratos/novo', \App\Livewire\Contratos\Editor::class)->name('contratos.novo');
    Route::get('/contratos/{contrato}/editar', \App\Livewire\Contratos\Editor::class)->name('contratos.editar');
    Route::get('/contratos/{contrato}', \App\Livewire\Contratos\Ficha::class)->name('contratos.ficha');

    // Alertas proativos (renovações, baterias, visitas em atraso, SLA).
    Route::get('/alertas', \App\Livewire\Alertas\Painel::class)->name('alertas');
    // Auditoria: o componente barra os técnicos (abort_unless ehAdmin em mount+render).
    Route::get('/auditoria', \App\Livewire\Auditoria\Listagem::class)->name('auditoria');

    // Despesas: REGISTOS (documento com linhas, como a folha da empresa). Rotas literais/
    // compostas ANTES de /{despesa} para não colidir.
    Route::get('/despesas', \App\Livewire\Despesas\Listagem::class)->name('despesas');
    Route::get('/despesas/nova', \App\Livewire\Despesas\Editor::class)->name('despesas.nova');
    Route::get('/despesas/registo/{registo}/editar', \App\Livewire\Despesas\Editor::class)->name('despesas.registo.editar');

    // PDF do registo (layout da folha da empresa, logótipo Nexus) — transferível.
    Route::get('/despesas/registo/{registo}/pdf', function (\App\Models\RegistoDespesa $registo) {
        $html = view('pdf.registo-despesas', ['registo' => $registo])->render();
        $dompdf = new \Dompdf\Dompdf(['enable_remote' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'landscape'); // a folha é larga (7 colunas de valores)
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="registo-despesas-' . $registo->id . '.pdf"',
        ]);
    })->name('despesas.registo.pdf');

    // Caminho antigo (despesa individual): abre o registo a que a linha pertence.
    Route::get('/despesas/{despesa}/editar', function (\App\Models\Despesa $despesa) {
        abort_unless($despesa->registo_despesa_id !== null, 404);

        return redirect()->route('despesas.registo.editar', $despesa->registo_despesa_id);
    })->name('despesas.editar');
});

// ---- Gestão de utilizadores (ÚNICA área exclusiva do admin) ----
// Middleware admin,tecnico para o técnico CHEGAR ao componente e levar um 403 real do Gate
// 'gerir-utilizadores' (o middleware papel:admin redirecionaria, não daria 403). A guarda
// verdadeira é o abort_unless(Gate) no componente.
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () {
    Route::get('/utilizadores/adicionar', \App\Livewire\Utilizadores\Adicionar::class)->name('utilizadores.adicionar');
});

// ---- Operação de campo (admin + técnico) — agenda, intervenções, relatórios ----
// O técnico é um espelho do admin: vê toda a operação (sem filtro por técnico). O único
// isolamento na camada de dados é por CLIENTE, no portal (global scope RestritoAoCliente).
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () use ($servirPdf) {
    // Consulta de clientes (só leitura — origem ERP).
    Route::get('/clientes', \App\Livewire\Clientes\Index::class)->name('clientes');
    Route::get('/clientes/{cliente}', \App\Livewire\Clientes\Detalhe::class)->name('clientes.detalhe');
    Route::get('/clientes/{cliente}/equipamentos', \App\Livewire\Clientes\Equipamentos::class)->name('clientes.equipamentos');
    Route::get('/clientes/{cliente}/contratos', \App\Livewire\Clientes\Contratos::class)->name('clientes.contratos');
    Route::get('/clientes/{cliente}/relatorios', \App\Livewire\Clientes\Relatorios::class)->name('clientes.relatorios');
    Route::get('/clientes/{cliente}/faturacao', \App\Livewire\Clientes\Faturacao::class)->name('clientes.faturacao');
    Route::get('/clientes/{cliente}/faturacao/{linha}', \App\Livewire\Clientes\Fatura::class)->name('clientes.fatura');

    // Registo manual de equipamento (não vindo do ERP). /novo ANTES de {equipamento} para não colidir.
    Route::get('/ativos/novo', \App\Livewire\Equipamentos\Novo::class)->name('equipamentos.novo');

    // Ficha de equipamento (leitura em campo — ex.: QR code).
    Route::get('/ativos/{equipamento}', \App\Livewire\Equipamentos\Ficha::class)->name('equipamentos.ficha');
    // Etiqueta QR (90x50mm) para imprimir e colar no equipamento — o QR contém o URL da
    // ficha (qualquer câmara o abre; o login é pedido se a sessão tiver caducado).
    Route::get('/ativos/{equipamento}/etiqueta', function (\App\Models\Equipamento $equipamento, \App\Services\GeradorQrEquipamento $qr) {
        $html = view('pdf.etiqueta-equipamento', ['equipamento' => $equipamento, 'qrPng' => $qr->pngDataUri($equipamento)])->render();
        $dompdf = new \Dompdf\Dompdf(['enable_remote' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 255.12, 141.73]); // 90 x 50 mm em pontos
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiqueta-equipamento-' . $equipamento->id . '.pdf"',
        ]);
    })->name('equipamentos.etiqueta');

    // "Abrir intervenção": o trabalho preenche-se no EDITOR DE RELATÓRIO (fonte única — abas
    // de equipamento, ficha de medições, etc.). Garante um rascunho ligado e redireciona.
    Route::get('/intervencoes/{intervencao}', function (\App\Models\Intervencao $intervencao) {
        // withTrashed: distingue relatório VIVO / ELIMINADO / inexistente numa só query. O
        // "nulls first" faz um relatório vivo ganhar a um eliminado (dados já corrompidos pela
        // bug antiga não bloqueiam indevidamente).
        $relatorio = $intervencao->relatorio()->withTrashed()
            ->orderByRaw('deleted_at asc nulls first')
            ->first();

        // Relatório eliminado → NÃO ressuscita nem cria fantasma. Explica e volta à listagem.
        if ($relatorio && $relatorio->trashed()) {
            return redirect()->route('relatorios')
                ->with('erro', 'O relatório desta intervenção foi eliminado.');
        }

        // Vivo → abre esse; nunca existiu → cria o rascunho (ponto único, à prova de corrida).
        $relatorio ??= $intervencao->garantirRascunho();

        return redirect()->route('relatorios.editar', $relatorio);
    })->name('intervencoes.formulario');

    // Agenda (calendário de visitas e intervenções).
    Route::get('/agenda', \App\Livewire\Agenda\Calendario::class)->name('agenda');

    Route::get('/relatorios', \App\Livewire\Relatorios\Listagem::class)->name('relatorios');
    // /novo ANTES de qualquer rota com parâmetro para não colidir.
    Route::get('/relatorios/novo', \App\Livewire\Relatorios\Novo::class)->name('relatorios.novo');
    Route::get('/relatorios/{relatorio}/editar', \App\Livewire\Relatorios\Novo::class)->name('relatorios.editar');
    Route::get('/relatorios/{relatorio}/enviar', \App\Livewire\Relatorios\Enviar::class)->name('relatorios.enviar');
    Route::get('/relatorios/{relatorio}/pdf', $servirPdf)->name('relatorios.pdf');

    // Proxy aos anexos no object storage (evita expor o MinIO ao browser).
    Route::get('/anexos/{anexo}', function (\App\Models\Anexo $anexo) {
        $disco = \Illuminate\Support\Facades\Storage::disk();
        abort_unless($disco->exists($anexo->storage_key), 404);

        // Nome sanitizado para o cabeçalho (sem aspas/quebras de linha → sem header injection).
        $nome = preg_replace('/[^\w.\- ]/u', '_', basename($anexo->nome_ficheiro ?? 'anexo')) ?: 'anexo';

        return response($disco->get($anexo->storage_key))
            ->header('Content-Type', $anexo->mime ?? 'application/octet-stream')
            // nosniff: impede o browser de reinterpretar o conteúdo como HTML/JS (o upload já
            // valida `image`, mas é defesa em profundidade); inline com nome legível.
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    })->name('anexos.ver');
});

// Portal do cliente (só leitura). O isolamento por cliente é imposto na camada de
// dados (global scope RestritoAoCliente) — aqui é defesa em profundidade (§7).
Route::middleware(['auth', 'papel:cliente'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', \App\Livewire\Portal\Dashboard::class)->name('dashboard');
    Route::get('/equipamentos', \App\Livewire\Portal\Equipamentos::class)->name('equipamentos');
    Route::get('/relatorios', \App\Livewire\Portal\Relatorios::class)->name('relatorios');

    // PDF do relatório — o route-model-binding aplica o global scope: um cliente
    // só resolve relatórios seus (os de outro cliente dão 404). SÓ ENVIADOS: um relatório
    // reaberto para edição (rascunho/finalizado) é trabalho interno — servi-lo aqui dava ao
    // cliente versões a meio da edição (11.ª revisão de segurança). 404 e não 403 para não
    // revelar a existência de trabalho em curso.
    Route::get('/relatorios/{relatorio}/pdf', function (\App\Models\Relatorio $relatorio, \App\Services\GeradorRelatorio $gerador) {
        abort_unless($relatorio->estado === \App\Enums\EstadoRelatorio::Enviado, 404);

        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
            $gerador->gerarPdf($relatorio);
            $relatorio->refresh();
        }

        return response($disco->get($relatorio->pdf_path))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
    })->name('relatorios.pdf');
});
