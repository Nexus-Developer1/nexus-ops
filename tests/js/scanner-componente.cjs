// Ensaia o COMPONENTE do scanner de recibos (o objeto Alpine do resources/js/app.js)
// fora do browser. Correr com: npm run testa-scanner
//
// Existe por uma razao concreta: em set. 2026 a digitalizacao deixou de funcionar porque o
// pedido da fotografia do sensor nunca respondia numa webcam de portatil, e o botao ficava
// preso em "A capturar...". Os ensaios de imagem nao apanhavam isso.
//
// Ensaia o COMPONENTE do scanner (o objeto Alpine inteiro, tal como está no app.js) fora do
// browser: câmara falsa, vídeo falso, canvas falso. Serve para apanhar erros de execução no
// caminho abrir → capturar → recorte → usar, que os ensaios de imagem não veem.
const fs = require('fs');
const path = require('path');

const { Tela } = require('./canvas-falso.cjs');

const ficheiro = path.join(process.cwd(), 'resources/js/app.js');
const fonte = fs.readFileSync(ficheiro, 'utf8');

// ---- extrair o objeto do componente -------------------------------------------------
const marca = "window.Alpine.data('scannerRecibo', () => (";
const i = fonte.indexOf(marca);
if (i < 0) throw new Error('componente não encontrado');
let n = 0, fim = -1;
for (let k = i + marca.length; k < fonte.length; k++) {
  if (fonte[k] === '(') n++;
  else if (fonte[k] === ')') { if (!n) { fim = k; break; } n--; }
}
const corpo = fonte.slice(i + marca.length, fim);

// ---- cenário ------------------------------------------------------------------------
function ambiente({ comImageCapture, takePhoto, larguraVideo = 1280, alturaVideo = 720 }) {
  const eventos = [];

  const video = new Tela(larguraVideo, alturaVideo);
  video.videoWidth = larguraVideo;
  video.videoHeight = alturaVideo;
  video.play = async () => {};
  // Frame do vídeo: recibo claro sobre mesa escura, para a deteção ter o que apanhar.
  for (let y = 0; y < alturaVideo; y++) {
    for (let x = 0; x < larguraVideo; x++) {
      const dentro = x > larguraVideo * 0.32 && x < larguraVideo * 0.68 && y > alturaVideo * 0.1 && y < alturaVideo * 0.9;
      const v = dentro ? (y % 40 < 6 ? 40 : 240) : 90;
      const p = (y * larguraVideo + x) * 4;
      video.dados[p] = video.dados[p + 1] = video.dados[p + 2] = v;
      video.dados[p + 3] = 255;
    }
  }

  const faixa = { stop: () => eventos.push('faixa parada'), getSettings: () => ({ width: larguraVideo, height: alturaVideo }) };

  // O Node já traz um `navigator` só de leitura: tem de ser redefinido à força.
  const camara = {
    mediaDevices: {
      getUserMedia: async () => ({ getVideoTracks: () => [faixa], getTracks: () => [faixa] }),
    },
  };
  Object.defineProperty(globalThis, 'navigator', { value: camara, configurable: true, writable: true });
  global.window = comImageCapture ? { ImageCapture: function () { this.takePhoto = takePhoto; } } : {};
  if (comImageCapture) global.ImageCapture = global.window.ImageCapture;
  else delete global.ImageCapture;
  global.createImageBitmap = async (blob) => blob.imagem;
  global.document = { createElement: () => new Tela() };

  const tela = new Tela(10, 10);
  tela.getBoundingClientRect = () => ({ left: 0, top: 0, width: tela.width, height: tela.height });
  tela.setPointerCapture = () => {};
  tela.releasePointerCapture = () => {};
  tela.toBlob = (cb) => cb({ tipo: 'blob', tamanho: tela.width * tela.height });

  const componente = eval('(() => (' + corpo + '))()');
  componente.$refs = { video, tela };
  componente.$nextTick = (fn) => fn();
  componente.$wire = {
    upload: (nome, ficheiro2, ok) => { eventos.push('enviado: ' + nome); ok?.(); },
  };

  return { componente, eventos, tela, video, camara };
}

// ---- ensaios ------------------------------------------------------------------------
let falhas = 0;
const ok = (cond, texto) => { console.log((cond ? '   ok   ' : '   FALHA') + ' ' + texto); if (!cond) falhas++; };

async function ensaio(nome, cfg, passos) {
  console.log('\n== ' + nome);
  const cenario = ambiente(cfg);
  try {
    await passos(cenario);
  } catch (e) {
    console.log('   FALHA rebentou: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
    falhas++;
  }
}

const comTempo = async (promessa, ms) => {
  let terminou = false;
  const marcar = promessa.then((v) => { terminou = true; return v; });
  const relogio = new Promise((r) => setTimeout(() => r('DEMOROU'), ms));
  const r = await Promise.race([marcar, relogio]);
  return { r, terminou };
};

(async () => {
  await ensaio('sem ImageCapture (o caso mais comum: iPhone/Safari)', { comImageCapture: false }, async ({ componente, eventos }) => {
    await componente.abrir();
    ok(componente.erro === '', 'abriu a câmara sem erro: "' + componente.erro + '"');
    await componente.capturar();
    ok(componente.fase === 'recorte', 'ficou no passo do recorte (fase=' + componente.fase + ')');
    ok(!!componente.bruta && componente.bruta.width === 1280, 'guardou a fotografia (' + componente.bruta?.width + 'px)');
    ok(Array.isArray(componente.quad) && componente.quad.length === 4, 'tem 4 cantos');
    componente.confirmarRecorte();
    ok(componente.fase === 'pronto' && !!componente.plana, 'endireitou e passou a pronto');
    componente.alternarFiltro();
    componente.alternarFiltro();
    componente.usar();
    ok(eventos.includes('enviado: reciboDigitalizado'), 'enviou o ficheiro ao servidor');
  });

  await ensaio('ImageCapture que funciona', {
    comImageCapture: true,
    takePhoto: async () => {
      const grande = new Tela(2400, 1800);
      grande.dados.fill(200);
      return { imagem: grande };
    },
  }, async ({ componente }) => {
    await componente.abrir();
    await componente.capturar();
    ok(componente.bruta?.width === 2400, 'usou a fotografia do sensor (' + componente.bruta?.width + 'px)');
    ok(componente.fase === 'recorte', 'ficou no passo do recorte');
  });

  await ensaio('ImageCapture que rebenta', {
    comImageCapture: true,
    takePhoto: async () => { throw new Error('NotSupportedError'); },
  }, async ({ componente }) => {
    await componente.abrir();
    await componente.capturar();
    ok(componente.bruta?.width === 1280, 'caiu no frame do vídeo (' + componente.bruta?.width + 'px)');
    ok(componente.fase === 'recorte', 'ficou no passo do recorte');
    ok(componente.aCapturar === false, 'destrancou o botão');
  });

  await ensaio('ImageCapture que NUNCA responde (webcam de portátil)', {
    comImageCapture: true,
    takePhoto: () => new Promise(() => {}),
  }, async ({ componente }) => {
    await componente.abrir();
    const { r, terminou } = await comTempo(componente.capturar(), 6000);
    ok(terminou, 'a captura terminou em menos de 6s (senão fica "A capturar…" para sempre)');
    ok(componente.fase === 'recorte', 'ficou no passo do recorte (fase=' + componente.fase + ')');
    ok(componente.aCapturar === false, 'destrancou o botão');
  });

  await ensaio('arrastar um canto', { comImageCapture: false }, async ({ componente, tela }) => {
    await componente.abrir();
    await componente.capturar();
    const antes = { ...componente.quad[0] };
    componente.agarrar({ clientX: antes.x * componente.escala, clientY: antes.y * componente.escala, pointerId: 1, preventDefault() {} });
    ok(componente.arrastar === 0, 'agarrou o canto de cima à esquerda (índice ' + componente.arrastar + ')');
    componente.mover({ clientX: 10 * componente.escala, clientY: 20 * componente.escala, pointerId: 1, preventDefault() {} });
    ok(Math.round(componente.quad[0].x) === 10 && Math.round(componente.quad[0].y) === 20, 'o canto foi para onde o dedo o pôs');
    componente.largar({ pointerId: 1 });
    ok(componente.arrastar === -1, 'largou');
    componente.confirmarRecorte();
    ok(componente.fase === 'pronto', 'endireitou com os cantos mexidos');
  });

  await ensaio('câmara que recusa as exigências altas (webcam simples)', { comImageCapture: false }, async ({ componente, camara }) => {
    let pedidos = 0;
    const original = camara.mediaDevices.getUserMedia;
    camara.mediaDevices.getUserMedia = async (pedido) => {
      pedidos++;
      // Só aceita o pedido sem exigências nenhumas (video: true).
      if (pedido.video !== true) throw new Error('OverconstrainedError');

      return original(pedido);
    };
    await componente.abrir();
    ok(componente.erro === '', 'abriu na mesma, ao fim de ' + pedidos + ' tentativas: "' + componente.erro + '"');
    await componente.capturar();
    ok(componente.fase === 'recorte', 'e digitalizou');
  });

  await ensaio('câmara recusada', { comImageCapture: false }, async ({ componente, camara }) => {
    camara.mediaDevices.getUserMedia = async () => { throw new Error('NotAllowedError'); };
    await componente.abrir();
    ok(componente.erro !== '', 'avisou: "' + componente.erro + '"');
  });

  // ---- QR code das faturas: a LIGAÇÃO entre o scanner e o servidor ----
  // A leitura dos píxeis prova-se em qr-fatura.cjs; aqui substitui-se só essa parte
  // (textoDaTela) e confirma-se que o texto chega ao servidor, para a linha certa.
  const QR = 'A:516520741*F:20260921*O:79.00';
  const esperar = () => new Promise((r) => setTimeout(r, 0));

  await ensaio('QR code: «Usar digitalização» manda o QR ao servidor, para a linha do botão', { comImageCapture: false }, async ({ componente }) => {
    const chamadas = [];
    componente.$wire.linhaDigitalizacao = 2;
    componente.$wire.lerQr = async (linha, texto) => { chamadas.push([linha, texto]); };
    componente.textoDaTela = async () => QR;
    await componente.abrir();
    await componente.capturar();
    componente.confirmarRecorte();
    componente.usar();
    await esperar();
    ok(chamadas.length === 1 && chamadas[0][0] === 2 && chamadas[0][1] === QR, 'mandou o QR da linha 2: ' + JSON.stringify(chamadas));
    ok(componente.bruta === null && componente.plana === null, 'e largou as telas ao fechar (a leitura usou as suas cópias)');
  });

  await ensaio('QR code: sem QR legível manda texto vazio (o servidor diz «preencha à mão»)', { comImageCapture: false }, async ({ componente }) => {
    const chamadas = [];
    componente.$wire.linhaDigitalizacao = 0;
    componente.$wire.lerQr = async (linha, texto) => { chamadas.push([linha, texto]); };
    componente.textoDaTela = async () => null;
    await componente.abrir();
    await componente.capturar();
    componente.confirmarRecorte();
    componente.usar();
    await esperar();
    ok(chamadas.length === 1 && chamadas[0][1] === '', 'mandou texto vazio: ' + JSON.stringify(chamadas));
  });

  await ensaio('QR code: foto da galeria com várias imagens — vale a primeira que tiver QR', { comImageCapture: false }, async ({ componente }) => {
    const chamadas = [];
    const lidas = [];
    componente.$wire.lerQr = async (linha, texto) => { chamadas.push([linha, texto]); };
    componente.telaDoFicheiro = async (f) => f.tela;
    componente.textoDaTela = async (t) => { lidas.push(t.nome); return t.qr ?? null; };
    const evento = { target: { files: [
      { tela: { nome: 'sem-qr' } },
      { tela: { nome: 'com-qr', qr: QR } },
      { tela: { nome: 'terceira', qr: 'nao-devia-chegar-aqui' } },
    ] } };
    await componente.lerQrDoFicheiro(evento, 1);
    ok(chamadas.length === 1 && chamadas[0][0] === 1 && chamadas[0][1] === QR, 'mandou o QR da linha 1: ' + JSON.stringify(chamadas));
    ok(lidas.join(',') === 'sem-qr,com-qr', 'parou na primeira com QR (leu: ' + lidas.join(',') + ')');
  });

  // ---- Texto do talão (OCR): depois do QR, o texto vai para lerTalao, da mesma linha ----
  // O OCR em si prova-se em ocr-talao.cjs; aqui substitui-se (textoDoTalao).
  const TALAO = 'MERCADONA\nR ENG. FREDERICO ULRICH, 3621\nMOREIRA';

  await ensaio('Talão: «Usar digitalização» lê o texto do recorte e manda-o para a linha, depois do QR', { comImageCapture: false }, async ({ componente }) => {
    const chamadas = [];
    let lida = null;
    componente.$wire.linhaDigitalizacao = 2;
    componente.$wire.lerQr = async (linha) => { chamadas.push(['qr', linha]); };
    componente.$wire.lerTalao = async (linha, texto) => { chamadas.push(['talao', linha, texto]); };
    componente.textoDaTela = async () => QR;
    componente.textoDoTalao = async (tela) => { lida = tela; ok(componente.aLer[2] === true, 'mostra «A ler o talão…» na linha 2'); return TALAO; };
    await componente.abrir();
    await componente.capturar();
    componente.confirmarRecorte();
    const plana = componente.plana;
    componente.usar();
    for (let k = 0; k < 5; k++) await esperar();
    ok(JSON.stringify(chamadas) === JSON.stringify([['qr', 2], ['talao', 2, TALAO]]), 'QR e depois o texto, na linha 2: ' + JSON.stringify(chamadas));
    ok(lida === plana, 'leu o recorte endireitado');
    ok(componente.aLer[2] === false, 'e tirou o «A ler o talão…»');
  });

  await ensaio('Talão: foto da galeria — lê o texto da fotografia que tinha o QR', { comImageCapture: false }, async ({ componente }) => {
    const lidas = [];
    componente.$wire.lerQr = async () => {};
    componente.$wire.lerTalao = async () => {};
    componente.telaDoFicheiro = async (f) => f.tela;
    componente.textoDaTela = async (t) => t.qr ?? null;
    componente.textoDoTalao = async (t) => { lidas.push(t.nome); return TALAO; };
    await componente.lerQrDoFicheiro({ target: { files: [{ tela: { nome: 'sem-qr' } }, { tela: { nome: 'com-qr', qr: QR } }] } }, 0);
    ok(lidas.join(',') === 'com-qr', 'leu a do QR (leu: ' + lidas.join(',') + ')');
  });

  await ensaio('Talão: foto sem QR — lê o texto na mesma (a descrição e o tipo não precisam do QR)', { comImageCapture: false }, async ({ componente }) => {
    const chamadas = [];
    componente.$wire.lerQr = async (linha, texto) => { chamadas.push(['qr', texto]); };
    componente.$wire.lerTalao = async (linha, texto) => { chamadas.push(['talao', texto]); };
    componente.telaDoFicheiro = async (f) => f.tela;
    componente.textoDaTela = async () => null;
    componente.textoDoTalao = async () => TALAO;
    await componente.lerQrDoFicheiro({ target: { files: [{ tela: { nome: 'so-foto' } }] } }, 0);
    ok(JSON.stringify(chamadas) === JSON.stringify([['qr', ''], ['talao', TALAO]]), 'mandou os dois: ' + JSON.stringify(chamadas));
  });

  await ensaio('Talão: OCR que falha (sem rede, telemóvel antigo) não manda nada e não prende o aviso', { comImageCapture: false }, async ({ componente }) => {
    let chamou = false;
    componente.$wire.lerQr = async () => {};
    componente.$wire.lerTalao = async () => { chamou = true; };
    componente.telaDoFicheiro = async (f) => f.tela;
    componente.textoDaTela = async () => QR;
    componente.textoDoTalao = async () => { throw new Error('sem motor'); };
    await componente.lerQrDoFicheiro({ target: { files: [{ tela: {} }] } }, 3);
    ok(!chamou, 'não chamou o lerTalao');
    ok(componente.aLer[3] === false, 'tirou o «A ler o talão…»');
  });

  console.log(falhas ? `\nFALHAS: ${falhas}` : '\nTudo a funcionar');
  process.exit(falhas ? 1 : 0);
})();
