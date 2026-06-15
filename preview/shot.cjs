// Uso: node preview/shot.cjs <ficheiro.html> <saida.png> [largura] [altura]
const puppeteer = require('puppeteer');
const path = require('path');

const htmlArg = process.argv[2] || 'index.html';
const outArg = process.argv[3] || 'shot.png';
const width = parseInt(process.argv[4] || '1600', 10);
const height = parseInt(process.argv[5] || '1280', 10);

const file = 'file://' + path.resolve(__dirname, htmlArg).replace(/\\/g, '/');

(async () => {
    const browser = await puppeteer.launch({ headless: 'new' });
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(file, { waitUntil: 'networkidle0' });
    await new Promise(r => setTimeout(r, 700)); // fontes + Alpine
    const scrollY = parseInt(process.argv[6] || '0', 10);
    if (scrollY) { await page.evaluate((y) => window.scrollTo(0, y), scrollY); await new Promise(r => setTimeout(r, 250)); }
    await page.screenshot({ path: path.resolve(__dirname, outArg) });
    await browser.close();
    console.log('OK -> ' + outArg);
})();
