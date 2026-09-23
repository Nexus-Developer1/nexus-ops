// Ensaio da leitura do QR code das faturas (resources/js/qr-fatura.js), fora do browser.
//
// Duas partes:
//  - TALÕES VERDADEIROS (tests/js/fixtures): recortes de fotografias de um Galaxy A34 — QR
//    denso, papel térmico ondulado, marca de água por trás. Foi com estes que o jsQR, a
//    primeira versão, falhou (lia 1 fotografia em 5). É a parte que mais conta.
//  - Imagens fabricadas com o QR de uma fatura, para casos que as fotografias não cobrem
//    (sem QR, QR de publicidade, fotografia enorme com o QR minúsculo).
//
// Correr: npm run testa-scanner   (ou só este: node tests/js/qr-fatura.cjs)

const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const QRCode = require('qrcode');
const jpeg = require('jpeg-js');

const EXEMPLO = 'A:516520741*B:509101143*C:PT*D:FR*E:N*F:20260921*G:FR COVILHA26/41625*H:J6M3CZ6D-41625*I1:PT*I7:64.23*I8:14.77*N:14.77*O:79.00*Q:SM2T*R:192';

// O que o talão da Mercadona (23/09/2026, 4,50 €) tem no QR — confirmado à vista no papel.
const MERCADONA = 'A:514038942*B:999999990*C:PT*D:FS*E:N*F:20260923*G:FS 70720232026001/123411*H:J6JFB6F9-123411*I1:PT*I5:3.98*I6:0.52*N:0.52*O:4.50*Q:AqQZ*R:2794';

function aleatorio(semente) {
    let s = semente >>> 0;

    return () => {
        s = (s * 1664525 + 1013904223) >>> 0;

        return s / 4294967296;
    };
}

function imagem(W, H, cinza) {
    const d = new Uint8ClampedArray(W * H * 4);
    for (let i = 0; i < d.length; i += 4) {
        d[i] = d[i + 1] = d[i + 2] = cinza;
        d[i + 3] = 255;
    }

    return d;
}

function retangulo(d, W, x0, y0, w, h, cinza) {
    for (let y = y0; y < y0 + h; y++) {
        for (let x = x0; x < x0 + w; x++) {
            const i = (y * W + x) * 4;
            d[i] = d[i + 1] = d[i + 2] = cinza;
        }
    }
}

function pintarQr(d, W, texto, px, ox, oy, tinta) {
    const qr = QRCode.create(texto, { errorCorrectionLevel: 'M' });
    const n = qr.modules.size;
    for (let my = 0; my < n; my++) {
        for (let mx = 0; mx < n; mx++) {
            if (qr.modules.get(my, mx)) retangulo(d, W, ox + mx * px, oy + my * px, px, px, tinta);
        }
    }
}

function texto(d, W, x0, y0, largura, linhas, altura, tinta, rnd) {
    for (let l = 0; l < linhas; l++) {
        retangulo(d, W, x0, y0 + l * altura * 2, Math.floor(largura * (0.4 + 0.6 * rnd())), altura, tinta);
    }
}

function ruido(d, amplitude, rnd) {
    for (let i = 0; i < d.length; i += 4) {
        const v = (rnd() - 0.5) * 2 * amplitude;
        d[i] += v;
        d[i + 1] += v;
        d[i + 2] += v;
    }
}

(async () => {
    const { lerQrDosPixeis, reduzir, prepararMotor, escolher } = await import(pathToFileURL(path.join(__dirname, '../../resources/js/qr-fatura.js')).href);

    // No browser o motor vem do ficheiro servido pela aplicação; aqui lê-se do disco.
    prepararMotor({ wasmBinary: fs.readFileSync(require.resolve('zxing-wasm/reader/zxing_reader.wasm')) });

    let falhas = 0;
    const caso = async (nome, esperado, W, H, d) => {
        const t0 = Date.now();
        const lido = await lerQrDosPixeis(d, W, H);
        const ms = Date.now() - t0;
        const ok = lido === esperado;
        if (!ok) falhas++;
        console.log(`${ok ? 'ok   ' : 'FALHA'} ${nome}  (${W}x${H}, ${ms} ms)${ok ? '' : `\n      esperado: ${esperado}\n      lido:     ${lido}`}`);
    };

    // ---- Talões verdadeiros -------------------------------------------------------------
    for (const ficheiro of ['talao-mercadona-a.jpg', 'talao-mercadona-b.jpg']) {
        const img = jpeg.decode(fs.readFileSync(path.join(__dirname, 'fixtures', ficheiro)), { useTArray: true });
        const d = new Uint8ClampedArray(img.data.buffer, img.data.byteOffset, img.data.length);
        await caso(`talão verdadeiro (${ficheiro}): QR denso, papel ondulado, marca de água`, MERCADONA, img.width, img.height, d);
    }

    // ---- Imagens fabricadas -------------------------------------------------------------

    // Talão digitalizado pelo scanner da app: recorte de 1200x2200, papel quase branco.
    {
        const rnd = aleatorio(1);
        const W = 1200, H = 2200, d = imagem(W, H, 242);
        texto(d, W, 80, 120, 1040, 30, 14, 40, rnd);
        pintarQr(d, W, EXEMPLO, 5, 380, 1500, 25);
        ruido(d, 6, rnd);
        await caso('talão digitalizado (papel branco)', EXEMPLO, W, H, d);
    }

    // Fotografia de 12 MP com o talão pequeno na mesa e o QR MINÚSCULO (2 px por módulo).
    {
        const rnd = aleatorio(5);
        const W = 3000, H = 4000, d = imagem(W, H, 120);
        retangulo(d, W, 900, 400, 1200, 3200, 236);
        texto(d, W, 980, 520, 1040, 40, 16, 50, rnd);
        pintarQr(d, W, EXEMPLO, 2, 1300, 2600, 30);
        await caso('fotografia de 12 MP, QR minúsculo', EXEMPLO, W, H, d);
    }

    // Papel térmico acinzentado, tinta desbotada, fotografia com grão.
    {
        const rnd = aleatorio(3);
        const W = 1400, H = 2400, d = imagem(W, H, 196);
        texto(d, W, 90, 140, 1200, 34, 14, 110, rnd);
        pintarQr(d, W, EXEMPLO, 6, 420, 1650, 88);
        ruido(d, 18, rnd);
        await caso('papel acinzentado, pouco contraste, com grão', EXEMPLO, W, H, d);
    }

    // Talão sem QR code: não inventa nada.
    {
        const rnd = aleatorio(4);
        const W = 1200, H = 2000, d = imagem(W, H, 240);
        texto(d, W, 80, 120, 1040, 50, 14, 40, rnd);
        ruido(d, 6, rnd);
        await caso('talão sem QR code', null, W, H, d);
    }

    // Só um QR, e não é de fatura (publicidade): lê-se tal como está — quem o recusa é o
    // servidor (QrFatura::ler), não o browser.
    {
        const url = 'https://www.exemplo.pt/promo-outubro';
        const W = 900, H = 1400, d = imagem(W, H, 240);
        pintarQr(d, W, url, 8, 250, 500, 20);
        await caso('QR de publicidade (lê-se; o servidor recusa)', url, W, H, d);
    }

    // DOIS QR no talão — publicidade em cima, fatura em baixo: fica o da fatura.
    {
        const W = 1200, H = 2400, d = imagem(W, H, 242);
        pintarQr(d, W, 'https://www.exemplo.pt/promo', 7, 380, 200, 20);
        pintarQr(d, W, EXEMPLO, 5, 380, 1500, 25);
        await caso('dois QR no talão (publicidade + fatura): fica o da fatura', EXEMPLO, W, H, d);
    }

    // A escolha, sozinha: fatura antes de publicidade; inválidos ignorados; nada → null.
    {
        const fatura = { isValid: true, text: EXEMPLO };
        const pub = { isValid: true, text: 'https://x.pt' };
        const partido = { isValid: false, text: EXEMPLO };
        const ok = escolher([pub, fatura]) === EXEMPLO && escolher([pub]) === 'https://x.pt'
            && escolher([partido]) === null && escolher([]) === null;
        if (!ok) falhas++;
        console.log(`${ok ? 'ok   ' : 'FALHA'} escolha entre vários QR (fatura primeiro, inválidos fora)`);
    }

    // A redução faz a média dos blocos.
    {
        const d = imagem(4, 2, 255);
        retangulo(d, 4, 0, 0, 2, 2, 0);
        const r = reduzir(d, 4, 2, 2);
        const ok = r.largura === 2 && r.altura === 1 && r.dados[0] === 0 && r.dados[4] === 255;
        if (!ok) falhas++;
        console.log(`${ok ? 'ok   ' : 'FALHA'} redução por média dos blocos (4x2 → ${r.largura}x${r.altura})`);
    }

    console.log(falhas ? `\n${falhas} FALHA(S)` : '\nQR code: tudo dentro do esperado');
    process.exit(falhas ? 1 : 0);
})();
