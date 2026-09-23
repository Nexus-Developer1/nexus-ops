// Ensaio da leitura do QR code das faturas (resources/js/qr-fatura.js), fora do browser.
//
// Fabrica imagens com o QR de uma fatura verdadeira — um talão digitalizado, uma fotografia
// de 12 MP com o talão pequeno lá dentro, papel acinzentado com pouco contraste — e confirma
// que o código da aplicação o lê. É a parte que não se pode testar em PHP: os píxeis.
//
// Correr: npm run testa-scanner   (ou só este: node tests/js/qr-fatura.cjs)

const path = require('path');
const { pathToFileURL } = require('url');
const QRCode = require('qrcode');

const EXEMPLO = 'A:516520741*B:509101143*C:PT*D:FR*E:N*F:20260921*G:FR COVILHA26/41625*H:J6M3CZ6D-41625*I1:PT*I7:64.23*I8:14.77*N:14.77*O:79.00*Q:SM2T*R:192';

// Aleatório com semente: o ensaio dá sempre o mesmo resultado.
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

// O QR, com `px` píxeis por módulo, a começar em (ox, oy). Devolve o lado em píxeis.
function pintarQr(d, W, texto, px, ox, oy, tinta) {
    const qr = QRCode.create(texto, { errorCorrectionLevel: 'M' });
    const n = qr.modules.size;
    for (let my = 0; my < n; my++) {
        for (let mx = 0; mx < n; mx++) {
            if (qr.modules.get(my, mx)) retangulo(d, W, ox + mx * px, oy + my * px, px, px, tinta);
        }
    }

    return n * px;
}

// Linhas de "texto" do talão: barras escuras de comprimento variável.
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
    const { lerQrDosPixeis, reduzir } = await import(pathToFileURL(path.join(__dirname, '../../resources/js/qr-fatura.js')).href);

    let falhas = 0;
    const caso = (nome, esperado, W, H, d) => {
        const t0 = Date.now();
        const lido = lerQrDosPixeis(d, W, H);
        const ms = Date.now() - t0;
        const ok = lido === esperado;
        if (!ok) falhas++;
        console.log(`${ok ? 'ok   ' : 'FALHA'} ${nome}  (${W}x${H}, ${ms} ms)${ok ? '' : `\n      esperado: ${esperado}\n      lido:     ${lido}`}`);
    };

    // 1. Talão digitalizado pelo scanner da app: recorte de 1200x2200, QR a 5 px por módulo
    //    no fundo, papel quase branco.
    {
        const rnd = aleatorio(1);
        const W = 1200, H = 2200, d = imagem(W, H, 242);
        texto(d, W, 80, 120, 1040, 30, 14, 40, rnd);
        pintarQr(d, W, EXEMPLO, 5, 380, 1500, 25);
        ruido(d, 6, rnd);
        caso('talão digitalizado (QR pequeno, papel branco)', EXEMPLO, W, H, d);
    }

    // 2. Fotografia de 12 MP tirada de pé sobre a mesa: o talão ocupa uma parte e o QR é
    //    pequeno na imagem inteira.
    {
        const rnd = aleatorio(2);
        const W = 3000, H = 4000, d = imagem(W, H, 120);          // mesa
        retangulo(d, W, 900, 400, 1200, 3200, 236);               // talão
        texto(d, W, 980, 520, 1040, 40, 16, 50, rnd);
        pintarQr(d, W, EXEMPLO, 4, 1260, 2600, 30);
        ruido(d, 8, rnd);
        caso('fotografia de 12 MP, talão pequeno na mesa', EXEMPLO, W, H, d);
    }

    // 2b. O mesmo, com o QR MINÚSCULO (2 px por módulo). Medido: a 3 px a cópia de 2400 px
    //     ainda lê; a 2 px nem a de 1600 nem a de 2400 leem, só a resolução original. É este
    //     caso que prova que o último degrau da escada existe e faz falta.
    {
        const rnd = aleatorio(5);
        const W = 3000, H = 4000, d = imagem(W, H, 120);
        retangulo(d, W, 900, 400, 1200, 3200, 236);
        texto(d, W, 980, 520, 1040, 40, 16, 50, rnd);
        pintarQr(d, W, EXEMPLO, 2, 1300, 2600, 30);
        const reduzida = reduzir(d, W, H, 2400);
        if (lerQrDosPixeis(reduzida.dados, reduzida.largura, reduzida.altura) !== null) {
            falhas++;
            console.log('FALHA o caso 2b deixou de pôr à prova o último degrau: a cópia de 2400 px já lê este QR — apertar o tamanho');
        }
        caso('fotografia de 12 MP, QR minúsculo (só a resolução original lê)', EXEMPLO, W, H, d);
    }

    // 3. Papel térmico acinzentado, tinta desbotada, fotografia com grão.
    {
        const rnd = aleatorio(3);
        const W = 1400, H = 2400, d = imagem(W, H, 196);
        texto(d, W, 90, 140, 1200, 34, 14, 110, rnd);
        pintarQr(d, W, EXEMPLO, 6, 420, 1650, 88);
        ruido(d, 18, rnd);
        caso('papel acinzentado, pouco contraste, com grão', EXEMPLO, W, H, d);
    }

    // 4. Talão sem QR code (antigo, ou de um sistema não certificado): não inventa nada.
    {
        const rnd = aleatorio(4);
        const W = 1200, H = 2000, d = imagem(W, H, 240);
        texto(d, W, 80, 120, 1040, 50, 14, 40, rnd);
        ruido(d, 6, rnd);
        caso('talão sem QR code', null, W, H, d);
    }

    // 5. QR que NÃO é de fatura (publicidade no talão): a leitura devolve o texto tal como
    //    está — quem o recusa é o servidor (QrFatura::ler), não o browser.
    {
        const url = 'https://www.exemplo.pt/promo-outubro';
        const W = 900, H = 1400, d = imagem(W, H, 240);
        pintarQr(d, W, url, 8, 250, 500, 20);
        caso('QR de publicidade (lê-se; o servidor recusa)', url, W, H, d);
    }

    // 6. A redução faz a média dos blocos: um quadrado meio preto meio branco fica cinzento,
    //    e as proporções mantêm-se.
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
