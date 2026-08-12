<?php

namespace App\Services;

use App\Models\Equipamento;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// QR do equipamento (CLAUDE.md §6: "QR code por equipamento → abre a ficha"). O código
// contém o URL DA FICHA — qualquer câmara de telemóvel o abre diretamente (não é preciso
// scanner na app; o login é pedido se a sessão tiver caducado). ECC H: as etiquetas coladas
// em UPS/geradores riscam-se e sujam-se — o nível alto de correção mantém-nas legíveis.
class GeradorQrEquipamento
{
    /** SVG inline para o ecrã (ficha do equipamento). */
    public function svg(Equipamento $equipamento): string
    {
        $opcoes = new QROptions;
        $opcoes->outputInterface = QRMarkupSVG::class;
        $opcoes->eccLevel = EccLevel::H;
        $opcoes->outputBase64 = false;
        $opcoes->quietzoneSize = 1; // a margem branca no ecrã é dada pelo layout

        return (new QRCode($opcoes))->render($this->url($equipamento));
    }

    /** PNG (data URI) para o PDF da etiqueta — o dompdf não renderiza SVG de forma fiável. */
    public function pngDataUri(Equipamento $equipamento): string
    {
        $opcoes = new QROptions;
        $opcoes->outputInterface = QRGdImagePNG::class;
        $opcoes->eccLevel = EccLevel::H;
        $opcoes->outputBase64 = true; // data URI — o dompdf corre com enable_remote=false
        $opcoes->scale = 10;          // nitidez de impressão
        $opcoes->quietzoneSize = 2;

        return (new QRCode($opcoes))->render($this->url($equipamento));
    }

    private function url(Equipamento $equipamento): string
    {
        return route('equipamentos.ficha', $equipamento);
    }
}
