// Ensaia o TRATAMENTO DE IMAGEM do scanner de recibos sobre fotografias simuladas
// (inclinação, perspetiva, sombra, reflexo, mesa sem contraste) e grava os PNGs em
// tests/js/saida para se ver o resultado. Correr com: npm run testa-scanner
//
// O que se exige: os cantos do papel a menos de 2% da largura do sítio certo, e o papel a
// sair branco. Foi assim que se apanhou o recorte torto de set. 2026 (um reflexo encostado
// à folha puxava um canto).
const fs = require('fs');
const path = require('path');

const { Tela, escreverPNG } = require('./canvas-falso.cjs');

global.document = { createElement: () => new Tela() };

// ============================================================ código real do scanner
const fonte = fs.readFileSync(path.join(process.cwd(), 'resources/js/app.js'), 'utf8');

function metodo(nome) {
  const i = fonte.indexOf('\n        ' + nome + '(');
  if (i < 0) throw new Error('metodo nao encontrado: ' + nome);
  let n = 0;
  for (let k = fonte.indexOf('{', i); k < fonte.length; k++) {
    if (fonte[k] === '{') n++;
    else if (fonte[k] === '}') { n--; if (!n) return fonte.slice(i + 1, k + 1); }
  }
  throw new Error('chavetas: ' + nome);
}

const NOMES = ['detetarPapel', 'quadrilateroDoPapel', 'encostarReta', 'ajustarReta', 'limiarOtsu', 'corrigirPerspetiva', 'recortar', 'aplicar', 'filtroDocumento', 'fundoLocal', 'janela'];
const scanner = eval('({' + NOMES.map(metodo).join(',') + '})');

// ============================================================ fotografia simulada
// Recibo desenhado à parte e depois projetado na foto (inclinação + perspetiva), sobre uma
// secretária, com iluminação desigual, sombra do lado e ruído.
function desenharRecibo(w, h) {
  const t = new Tela(w, h);
  const d = t.dados;
  const por = (x, y, v) => { const i = (y * w + x) * 4; d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255; };
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) por(x, y, 252);

  const barra = (x0, y0, x1, y1, v) => {
    for (let y = Math.max(0, y0 | 0); y < Math.min(h, y1 | 0); y++) {
      for (let x = Math.max(0, x0 | 0); x < Math.min(w, x1 | 0); x++) por(x, y, v);
    }
  };

  // Cabeçalho (logótipo a cheio) e nome da casa.
  barra(w * 0.28, h * 0.035, w * 0.72, h * 0.075, 25);
  barra(w * 0.2, h * 0.095, w * 0.8, h * 0.112, 60);
  barra(w * 0.3, h * 0.125, w * 0.7, h * 0.138, 90);

  // Linhas de artigos: descrição à esquerda, valor à direita.
  let y = h * 0.2;
  for (let linha = 0; linha < 16; linha++) {
    const largura = 0.28 + ((linha * 37) % 30) / 100;
    barra(w * 0.08, y, w * (0.08 + largura), y + h * 0.014, 35);
    barra(w * 0.74, y, w * 0.92, y + h * 0.014, 35);
    y += h * 0.038;
  }

  // Total e rodapé.
  barra(w * 0.08, h * 0.86, w * 0.92, h * 0.864, 70);
  barra(w * 0.5, h * 0.875, w * 0.72, h * 0.9, 20);
  barra(w * 0.74, h * 0.875, w * 0.92, h * 0.9, 20);
  barra(w * 0.2, h * 0.94, w * 0.8, h * 0.952, 110);

  return t;
}

// Homografia foto → recibo, a partir dos 4 cantos na foto.
function homografia(cantos, rw, rh) {
  // Resolve H (3x3, h33=1) tal que H * (px,py,1) ~ (rx,ry,1).
  const alvo = [[0, 0], [rw, 0], [rw, rh], [0, rh]];
  const A = [], b = [];
  for (let i = 0; i < 4; i++) {
    const [px, py] = [cantos[i].x, cantos[i].y];
    const [rx, ry] = alvo[i];
    A.push([px, py, 1, 0, 0, 0, -px * rx, -py * rx]); b.push(rx);
    A.push([0, 0, 0, px, py, 1, -px * ry, -py * ry]); b.push(ry);
  }
  // Eliminação de Gauss com pivotagem.
  for (let c = 0; c < 8; c++) {
    let melhor = c;
    for (let r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[melhor][c])) melhor = r;
    [A[c], A[melhor]] = [A[melhor], A[c]];
    [b[c], b[melhor]] = [b[melhor], b[c]];
    for (let r = 0; r < 8; r++) {
      if (r === c || !A[r][c]) continue;
      const f = A[r][c] / A[c][c];
      for (let k = c; k < 8; k++) A[r][k] -= f * A[c][k];
      b[r] -= f * b[c];
    }
  }
  const h = b.map((v, i) => v / A[i][i]);
  return (x, y) => {
    const den = h[6] * x + h[7] * y + 1;
    return [(h[0] * x + h[1] * y + h[2]) / den, (h[3] * x + h[4] * y + h[5]) / den];
  };
}

function fotografar({ W, H, cantos, luzEsquerda, luzDireita, reflexo, ruido, fundoTom }) {
  const rw = 620, rh = 1100;
  const recibo = desenharRecibo(rw, rh);
  const rd = recibo.dados;
  const paraRecibo = homografia(cantos, rw, rh);

  const foto = new Tela(W, H);
  const d = foto.dados;

  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const [rx, ry] = paraRecibo(x + 0.5, y + 0.5);
      let v;
      if (rx >= 0 && ry >= 0 && rx < rw - 1 && ry < rh - 1) {
        // Amostragem bilinear do recibo.
        const xi = rx | 0, yi = ry | 0, fx = rx - xi, fy = ry - yi;
        const p = (yy, xx) => rd[(yy * rw + xx) * 4];
        v = p(yi, xi) * (1 - fx) * (1 - fy) + p(yi, xi + 1) * fx * (1 - fy) +
            p(yi + 1, xi) * (1 - fx) * fy + p(yi + 1, xi + 1) * fx * fy;
      } else {
        // Secretária: tom com veios.
        v = fundoTom + 8 * Math.sin(x / 23) + 5 * Math.sin(y / 61);
      }

      // Iluminação: rampa da esquerda para a direita + queda nos cantos.
      const rampa = luzEsquerda + (luzDireita - luzEsquerda) * (x / W);
      const cantoX = 1 - 0.18 * Math.pow((2 * x / W - 1), 2);
      const cantoY = 1 - 0.18 * Math.pow((2 * y / H - 1), 2);
      v *= rampa * cantoX * cantoY;

      // Reflexo da lâmpada.
      if (reflexo) {
        const dx = (x - W * 0.62) / (W * 0.16), dy = (y - H * 0.2) / (H * 0.12);
        const r2 = dx * dx + dy * dy;
        if (r2 < 1) v += 90 * (1 - r2);
      }

      v += (Math.random() - 0.5) * ruido;
      const i = (y * W + x) * 4;
      d[i] = d[i + 1] = d[i + 2] = Math.max(0, Math.min(255, v));
      d[i + 3] = 255;
    }
  }

  // Desfoque ligeiro (lente + mão).
  const copia = Uint8ClampedArray.from(d);
  for (let y = 1; y < H - 1; y++) {
    for (let x = 1; x < W - 1; x++) {
      let soma = 0;
      for (let j = -1; j <= 1; j++) for (let k = -1; k <= 1; k++) soma += copia[((y + j) * W + x + k) * 4];
      const i = (y * W + x) * 4;
      d[i] = d[i + 1] = d[i + 2] = soma / 9;
    }
  }

  return foto;
}

// ============================================================ utilitários de saída
function paraPNG(tela, ficheiro, larguraMax = 620) {
  const esc = Math.min(1, larguraMax / tela.width);
  const w = Math.max(1, Math.round(tela.width * esc)), h = Math.max(1, Math.round(tela.height * esc));
  const pequena = new Tela(w, h);
  pequena.getContext().drawImage(tela, 0, 0, w, h);
  escreverPNG(ficheiro, w, h, pequena.dados);
  return `${ficheiro} (${tela.width}x${tela.height} → ${w}x${h})`;
}

function comQuadrilatero(tela, quad, caixa) {
  const copia = new Tela(tela.width, tela.height);
  copia.dados.set(tela.dados);
  const d = copia.dados, W = copia.width, H = copia.height;
  const ponto = (x, y, cor) => {
    for (let j = -3; j <= 3; j++) for (let k = -3; k <= 3; k++) {
      const px = Math.round(x) + k, py = Math.round(y) + j;
      if (px < 0 || py < 0 || px >= W || py >= H) continue;
      const i = (py * W + px) * 4;
      d[i] = cor[0]; d[i + 1] = cor[1]; d[i + 2] = cor[2];
    }
  };
  const linha = (a, b, cor) => {
    const n = Math.ceil(Math.hypot(b.x - a.x, b.y - a.y));
    for (let t = 0; t <= n; t++) ponto(a.x + (b.x - a.x) * t / n, a.y + (b.y - a.y) * t / n, cor);
  };
  if (quad) for (let i = 0; i < 4; i++) linha(quad[i], quad[(i + 1) % 4], [255, 0, 0]);
  if (caixa) {
    const c = [{ x: caixa.x, y: caixa.y }, { x: caixa.x + caixa.w, y: caixa.y },
      { x: caixa.x + caixa.w, y: caixa.y + caixa.h }, { x: caixa.x, y: caixa.y + caixa.h }];
    for (let i = 0; i < 4; i++) linha(c[i], c[(i + 1) % 4], [0, 120, 255]);
  }
  return copia;
}

// ============================================================ casos
const CASOS = {
  'direita-boa-luz': {
    W: 1440, H: 1920, fundoTom: 150, luzEsquerda: 0.95, luzDireita: 0.9, reflexo: false, ruido: 6,
    cantos: [{ x: 430, y: 300 }, { x: 1010, y: 300 }, { x: 1010, y: 1650 }, { x: 430, y: 1650 }],
  },
  'inclinada-sombra': {
    W: 1440, H: 1920, fundoTom: 120, luzEsquerda: 1.0, luzDireita: 0.55, reflexo: false, ruido: 8,
    cantos: [{ x: 360, y: 420 }, { x: 980, y: 260 }, { x: 1090, y: 1600 }, { x: 470, y: 1730 }],
  },
  'perspetiva-reflexo': {
    W: 1440, H: 1920, fundoTom: 135, luzEsquerda: 0.8, luzDireita: 1.0, reflexo: true, ruido: 8,
    cantos: [{ x: 500, y: 330 }, { x: 1010, y: 380 }, { x: 1130, y: 1690 }, { x: 330, y: 1600 }],
  },
  // Mesa branca: o papel e a mesa têm o mesmo tom. A deteção TEM de desistir (e não
  // inventar um recorte torto) — fica a fotografia inteira para se arrastarem os cantos.
  'mesa-branca-sem-contraste': {
    W: 1440, H: 1920, fundoTom: 248, luzEsquerda: 0.9, luzDireita: 0.88, reflexo: false, ruido: 6,
    cantos: [{ x: 430, y: 300 }, { x: 1010, y: 320 }, { x: 1000, y: 1650 }, { x: 420, y: 1630 }],
  },
  'mesa-clara-pouca-luz': {
    W: 1440, H: 1920, fundoTom: 205, luzEsquerda: 0.62, luzDireita: 0.7, reflexo: false, ruido: 9,
    cantos: [{ x: 420, y: 260 }, { x: 1040, y: 300 }, { x: 1000, y: 1700 }, { x: 380, y: 1660 }],
  },
};

let falhas = 0;
const saida = path.join(__dirname, 'saida');
fs.mkdirSync(saida, { recursive: true });

const mediana = (a) => { const c = [...a].sort((x, y) => x - y); return c[c.length >> 1]; };

for (const [nome, cfg] of Object.entries(CASOS)) {
  const foto = fotografar(cfg);
  const t0 = Date.now();
  const det = scanner.detetarPapel(foto);
  const zona = det?.caixa ?? { x: 0, y: 0, w: foto.width, h: foto.height };
  const plana = det?.quad ? scanner.corrigirPerspetiva(foto, det.quad) : scanner.recortar(foto, zona);
  const ctx = plana.getContext();
  scanner.filtroDocumento(ctx, plana.width, plana.height);
  const ms = Date.now() - t0;

  // Erro do recorte: distância dos cantos detetados aos verdadeiros.
  let erro = 'sem quadrilátero (recorte pela caixa)';
  let pior = 0;
  if (det?.quad) {
    const dists = det.quad.map((c, i) => Math.hypot(c.x - cfg.cantos[i].x, c.y - cfg.cantos[i].y));
    pior = Math.max(...dists);
    erro = 'cantos a ' + dists.map((v) => Math.round(v)).join('/') + ' px do sítio certo';
  }

  const cinza = [];
  for (let i = 0; i < plana.dados.length; i += 4) cinza.push(plana.dados[i]);
  const claros = cinza.filter((v) => v > 200).length / cinza.length;

  console.log(`\n== ${nome}  (${ms} ms)`);
  console.log('   ' + erro);
  console.log(`   saída ${plana.width}x${plana.height}, ${Math.round(claros * 100)}% do recorte é papel branco, mediana ${mediana(cinza)}`);
  console.log('   ' + paraPNG(comQuadrilatero(foto, det?.quad, det?.caixa), path.join(saida, `${nome}-1-foto.png`)));
  console.log('   ' + paraPNG(plana, path.join(saida, `${nome}-2-resultado.png`)));

  // Sem contraste entre papel e mesa não há recorte possível: aí só se exige que não se
  // invente nada (fica a fotografia inteira). Nos outros, os cantos têm de bater certo.
  if (!nome.includes('sem-contraste')) {
    if (pior > cfg.W * 0.02) { console.log(`   FALHA: cantos a ${Math.round(pior)}px (máximo ${Math.round(cfg.W * 0.02)}px)`); falhas++; }
    if (mediana(cinza) < 235) { console.log(`   FALHA: papel cinzento (${mediana(cinza)})`); falhas++; }
  }

  // Caminho MANUAL: os cantos vêm de quem arrastou (aqui, os verdadeiros).
  const mao = scanner.corrigirPerspetiva(foto, cfg.cantos);
  scanner.filtroDocumento(mao.getContext(), mao.width, mao.height);
  console.log('   ' + paraPNG(mao, path.join(saida, `${nome}-3-cantos-a-mao.png`)));
}

console.log(falhas ? 'FALHAS: ' + falhas : 'Tudo dentro do esperado');
process.exit(falhas ? 1 : 0);
