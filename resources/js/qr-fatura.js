import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';

// Leitura do QR code das faturas portuguesas (Portaria 195/2020) a partir dos píxeis de uma
// fotografia do recibo.
//
// Motor: zxing-cpp compilado para WebAssembly. A primeira versão usava o jsQR, que nos ensaios
// com imagens fabricadas lia tudo mas em talões VERDADEIROS — QR denso, papel térmico ondulado,
// marca de água impressa por trás — lia 1 fotografia em 5. O zxing-cpp leu as 5, a todas as
// escalas, em milissegundos (medido a 23/09/2026 com fotografias de um Galaxy A34; o ZXing em
// JavaScript puro foi pior do que o jsQR).
//
// Só LÊ: devolve o texto tal como está no QR code, ou null. Quem o interpreta é o servidor
// (App\Services\Despesas\QrFatura), porque o texto vem do browser e não se confia nele.

/**
 * Onde o motor está. No browser é o qr-leitor.js que o aponta para o ficheiro .wasm servido
 * pela própria aplicação; nos ensaios em Node passa-se o ficheiro já lido.
 */
export function prepararMotor(overrides) {
    prepareZXingModule({ overrides });
}

// Tenta primeiro a imagem como vem; se não der, uma cópia reduzida — reduzir alisa o grão do
// papel térmico, e com o jsQR houve fotografias que só se liam assim.
const REDUCAO = 1600;

/**
 * @param {Uint8ClampedArray} dados RGBA
 * @returns {Promise<string|null>}
 */
export async function lerQrDosPixeis(dados, largura, altura) {
    // A cópia reduzida faz-se ANTES de entregar os píxeis ao motor.
    const tentativas = [{ dados, largura, altura }];
    if (Math.max(largura, altura) > REDUCAO) tentativas.push(reduzir(dados, largura, altura, REDUCAO));

    for (const img of tentativas) {
        const codigos = await readBarcodes(
            { data: img.dados, width: img.largura, height: img.altura, colorSpace: 'srgb' },
            { formats: ['QRCode'], tryHarder: true, maxNumberOfSymbols: 4 },
        );
        const texto = escolher(codigos);
        if (texto) return texto;
    }

    return null;
}

/**
 * Um talão pode ter mais do que um QR (o do MB WAY, publicidade): fica o que tem a forma de
 * fatura portuguesa; se nenhum tiver, o primeiro — e o servidor diz que não é de fatura.
 */
export function escolher(codigos) {
    const validos = codigos.filter((c) => c.isValid && c.text);
    const fatura = validos.find((c) => /^A:\d{9}\*/.test(c.text));

    return (fatura ?? validos[0])?.text ?? null;
}

/**
 * Cópia reduzida para o lado maior medir `lado` píxeis. Cada píxel novo é a MÉDIA do bloco
 * que representa — reduzir saltando píxeis desfazia os módulos finos do QR.
 */
export function reduzir(dados, largura, altura, lado) {
    const s = lado / Math.max(largura, altura);
    const L = Math.max(1, Math.round(largura * s));
    const A = Math.max(1, Math.round(altura * s));
    const saida = new Uint8ClampedArray(L * A * 4);

    for (let y = 0; y < A; y++) {
        const y0 = Math.floor(y / s);
        const y1 = Math.min(altura, Math.max(y0 + 1, Math.floor((y + 1) / s)));
        for (let x = 0; x < L; x++) {
            const x0 = Math.floor(x / s);
            const x1 = Math.min(largura, Math.max(x0 + 1, Math.floor((x + 1) / s)));
            let r = 0, g = 0, b = 0, n = 0;
            for (let yy = y0; yy < y1; yy++) {
                let i = (yy * largura + x0) * 4;
                for (let xx = x0; xx < x1; xx++, i += 4) {
                    r += dados[i];
                    g += dados[i + 1];
                    b += dados[i + 2];
                    n++;
                }
            }
            const o = (y * L + x) * 4;
            saida[o] = r / n;
            saida[o + 1] = g / n;
            saida[o + 2] = b / n;
            saida[o + 3] = 255;
        }
    }

    return { dados: saida, largura: L, altura: A };
}
