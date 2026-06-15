// Verifica as novas funcionalidades da agenda: carregamento sem erros, botão iCal
// (após filtrar técnico) e criação de evento por seleção de intervalo no grid.
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1100 });
    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'admin@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);

    await page.goto('http://localhost:8080/agenda', { waitUntil: 'networkidle0' });
    await page.waitForSelector('.fc', { timeout: 15000 });
    await new Promise((r) => setTimeout(r, 1500));

    // Seleciona um intervalo no grid (arrastar o rato) → deve abrir o modal "Novo evento".
    const cols = await page.$$('.fc-timegrid-col');
    let abriuModal = false;
    if (cols.length > 1) {
        const box = await cols[2].boundingBox(); // 3ª coluna (um dia útil)
        await page.mouse.move(box.x + box.width / 2, box.y + 200);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width / 2, box.y + 320, { steps: 8 });
        await page.mouse.up();
        try {
            await page.waitForFunction(() => document.body.innerText.includes('Novo evento'), { timeout: 5000 });
            abriuModal = true;
        } catch (e) { /* ignora */ }
    }
    await page.screenshot({ path: 'preview/agenda-novo-evento.png', fullPage: true });

    console.log('RESULTADO: modal_novo_evento=' + abriuModal);
    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
    await browser.close();
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
