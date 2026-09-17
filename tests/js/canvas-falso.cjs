// Peças partilhadas pelos ensaios: escrita de PNG e um arremedo de <canvas>.
const fs = require('fs');
const zlib = require('zlib');

// ============================================================ PNG (escrita)
const tabelaCrc = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();

function crc32(buf) {
  let c = ~0;
  for (let i = 0; i < buf.length; i++) c = tabelaCrc[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return ~c >>> 0;
}

function bloco(tipo, dados) {
  const tam = Buffer.alloc(4);
  tam.writeUInt32BE(dados.length, 0);
  const corpo = Buffer.concat([Buffer.from(tipo, 'ascii'), dados]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(corpo), 0);
  return Buffer.concat([tam, corpo, crc]);
}

function escreverPNG(ficheiro, w, h, rgba) {
  const linhas = Buffer.alloc((w * 4 + 1) * h);
  const origem = Buffer.from(rgba.buffer, rgba.byteOffset, rgba.length);
  for (let y = 0; y < h; y++) {
    linhas[y * (w * 4 + 1)] = 0;
    origem.copy(linhas, y * (w * 4 + 1) + 1, y * w * 4, (y + 1) * w * 4);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  fs.writeFileSync(ficheiro, Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    bloco('IHDR', ihdr),
    bloco('IDAT', zlib.deflateSync(linhas, { level: 6 })),
    bloco('IEND', Buffer.alloc(0)),
  ]));
}

// ============================================================ arremedo de <canvas>
class Contexto {
  constructor(tela) { this.tela = tela; this.imageSmoothingEnabled = true; this.imageSmoothingQuality = 'high'; }

  getImageData(x, y, w, h) {
    const out = new Uint8ClampedArray(w * h * 4);
    const W = this.tela.width;
    for (let j = 0; j < h; j++) {
      const inicio = ((y + j) * W + x) * 4;
      out.set(this.tela.dados.subarray(inicio, inicio + w * 4), j * w * 4);
    }
    return { data: out, width: w, height: h };
  }

  putImageData(img, x = 0, y = 0) {
    const W = this.tela.width;
    for (let j = 0; j < img.height; j++) {
      this.tela.dados.set(img.data.subarray(j * img.width * 4, (j + 1) * img.width * 4), ((y + j) * W + x) * 4);
    }
  }

  createImageData(w, h) { return { data: new Uint8ClampedArray(w * h * 4), width: w, height: h }; }

  // Desenho vetorial: aqui só precisa de não rebentar (o que interessa medir são os píxeis).
  save() {}
  restore() {}
  beginPath() { this.caminho = (this.caminho || 0) + 1; }
  closePath() {}
  rect() {}
  moveTo() {}
  lineTo() {}
  arc() {}
  fill() { this.pintou = (this.pintou || 0) + 1; }
  stroke() { this.tracou = (this.tracou || 0) + 1; }

  // drawImage(img, dx, dy) | (img, dx, dy, dw, dh) | (img, sx, sy, sw, sh, dx, dy, dw, dh)
  drawImage(img, ...a) {
    let sx = 0, sy = 0, sw = img.width, sh = img.height, dx = 0, dy = 0, dw = sw, dh = sh;
    if (a.length === 2) { [dx, dy] = a; }
    else if (a.length === 4) { [dx, dy, dw, dh] = a; }
    else if (a.length === 8) { [sx, sy, sw, sh, dx, dy, dw, dh] = a; }
    const src = img.dados, SW = img.width;
    const dst = this.tela.dados, DW = this.tela.width, DH = this.tela.height;
    const escX = sw / dw, escY = sh / dh;

    for (let y = 0; y < dh; y++) {
      const py = dy + y;
      if (py < 0 || py >= DH) continue;
      const y0 = sy + y * escY, y1 = y0 + escY;
      for (let x = 0; x < dw; x++) {
        const px = dx + x;
        if (px < 0 || px >= DW) continue;
        const x0 = sx + x * escX, x1 = x0 + escX;
        let r = 0, g = 0, b = 0, a2 = 0, n = 0;
        // Média da área de origem (é o que o browser faz ao reduzir).
        const iy0 = Math.max(0, Math.floor(y0)), iy1 = Math.max(iy0 + 1, Math.min(img.height, Math.ceil(y1)));
        const ix0 = Math.max(0, Math.floor(x0)), ix1 = Math.max(ix0 + 1, Math.min(SW, Math.ceil(x1)));
        for (let yy = iy0; yy < iy1; yy++) {
          for (let xx = ix0; xx < ix1; xx++) {
            const i = (yy * SW + xx) * 4;
            r += src[i]; g += src[i + 1]; b += src[i + 2]; a2 += src[i + 3]; n++;
          }
        }
        const o = (py * DW + px) * 4;
        dst[o] = r / n; dst[o + 1] = g / n; dst[o + 2] = b / n; dst[o + 3] = a2 / n;
      }
    }
  }
}

class Tela {
  constructor(w = 300, h = 150) { this._w = w; this._h = h; this.dados = new Uint8ClampedArray(w * h * 4); }
  get width() { return this._w; }
  set width(v) { this._w = Math.max(1, v | 0); this.dados = new Uint8ClampedArray(this._w * this._h * 4); }
  get height() { return this._h; }
  set height(v) { this._h = Math.max(1, v | 0); this.dados = new Uint8ClampedArray(this._w * this._h * 4); }
  getContext() { return this._ctx || (this._ctx = new Contexto(this)); }
}


module.exports = { Tela, Contexto, escreverPNG };
