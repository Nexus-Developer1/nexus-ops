<?php

namespace App\Http\Controllers;

use App\Models\Anexo;
use App\Models\Despesa;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AnexoController
{
    public function recibo(Anexo $anexo): Response
    {
        abort_unless($anexo->anexavel_type === (new Despesa)->getMorphClass(), 404);
        abort_unless(Despesa::whereKey($anexo->anexavel_id)->whereHas('registo')->exists(), 404);

        return $this->ver($anexo);
    }

    public function ver(Anexo $anexo): Response
    {
        $disco = Storage::disk();
        abort_unless($disco->exists($anexo->storage_key), 404);

        // Nome sanitizado para o cabeçalho (sem aspas/quebras de linha → sem header injection).
        $nome = preg_replace('/[^\w.\- ]/u', '_', basename($anexo->nome_ficheiro ?? 'anexo')) ?: 'anexo';

        // Só imagens (não SVG) e PDF abrem no browser. Qualquer outro tipo — ou um mime que
        // afirme ser HTML/SVG/JS — vai como DOWNLOAD opaco (octet-stream + attachment): o
        // browser nunca o interpreta, mesmo que tenha entrado por uma porta lateral (stored
        // XSS via recibo em HTML, 22.ª revisão de segurança). nosniff em ambos os casos.
        $mime = strtolower(trim((string) ($anexo->mime ?? '')));
        $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp', 'application/pdf'], true);

        return response($disco->get($anexo->storage_key))
            ->header('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Disposition', ($inline ? 'inline' : 'attachment').'; filename="'.$nome.'"');
    }
}
