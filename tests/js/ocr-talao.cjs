// Ensaio do OCR do talão (o mesmo motor e os mesmos dados de português que o
// resources/js/ocr-leitor.js publica para o browser), fora do browser e sem rede.
//
// Recortes de uma fotografia verdadeira (Mercadona de Moreira, 23/09/2026): o cabeçalho e a linha
// da data de emissão — de propósito SEM a zona do pagamento (dados do cartão). O que se tira do
// texto (descrição, tipo, hora) prova-se do lado do servidor, em DespesaTalaoTest.php.
//
// Correr: npm run testa-scanner   (ou só este: node tests/js/ocr-talao.cjs)

const fs = require('fs');
const path = require('path');
const { createWorker } = require('tesseract.js');

(async () => {
    const langPath = path.dirname(require.resolve('@tesseract.js-data/por/4.0.0_best_int/por.traineddata.gz'));
    const w = await createWorker('por', 1, { langPath, cacheMethod: 'none' });

    let falhas = 0;
    const caso = async (ficheiro, esperados) => {
        const t0 = Date.now();
        const { data } = await w.recognize(fs.readFileSync(path.join(__dirname, 'fixtures', ficheiro)));
        const ms = Date.now() - t0;
        const faltam = esperados.filter((e) => !data.text.includes(e));
        if (faltam.length) falhas++;
        console.log(`${faltam.length ? 'FALHA' : 'ok   '} ${ficheiro}  (${ms} ms)${faltam.length ? `\n      faltou: ${faltam.join(' | ')}\n      lido:\n${data.text}` : ''}`);
    };

    await caso('talao-mercadona-cabecalho.jpg', ['MERCADONA', 'MOREIRA', 'FREDERICO ULRICH', 'VILA NOVA DE GAIA']);
    await caso('talao-mercadona-emissao.jpg', ['emissão', '13:11']);

    await w.terminate();
    console.log(falhas ? `\n${falhas} FALHA(S)` : '\nOCR do talão: tudo dentro do esperado');
    process.exit(falhas ? 1 : 0);
})();
