import jsQR from 'jsqr';

// Leitura do QR code das faturas portuguesas (Portaria 195/2020) a partir dos píxeis de uma
// fotografia do recibo.
//
// Só LÊ: devolve o texto tal como está no QR code, ou null. Quem o interpreta é o servidor
// (App\Services\Despesas\QrFatura), porque o texto vem do browser e não se confia nele.
//
// Carrega-se à parte (import dinâmico no app.js): o leitor só é descarregado quando alguém
// junta um recibo, não em todas as páginas.
//
// Tamanhos: uma fotografia de telemóvel tem 12 MP e o QR de um talão ocupa uma fração
// pequena dela. Tenta-se primeiro numa cópia reduzida (rápido), depois maior, e só em último
// caso na resolução original — pára na primeira que der.
const DEGRAUS = [1600, 2400];

/**
 * @param {Uint8ClampedArray} dados RGBA
 * @returns {string|null}
 */
export function lerQrDosPixeis(dados, largura, altura) {
    const maior = Math.max(largura, altura);
    for (const lado of [...DEGRAUS.filter((l) => l < maior), maior]) {
        const img = lado === maior ? { dados, largura, altura } : reduzir(dados, largura, altura, lado);
        const resultado = jsQR(img.dados, img.largura, img.altura, { inversionAttempts: 'dontInvert' });
        if (resultado && resultado.data) return resultado.data;
    }

    return null;
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
