// Génère les icônes PNG et l'image de partage par défaut à partir de icon.svg (node tools/render-images.js)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const A = path.join(__dirname, '..', 'assets');
const svg = fs.readFileSync(path.join(A, 'icon.svg'), 'utf8');
const lora = 'data:font/ttf;base64,' + fs.readFileSync(path.join(A, 'fonts/Lora-Italic.ttf')).toString('base64');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage();
  for (const [name, size, maskable] of [['icon-180', 180], ['icon-192', 192], ['icon-512', 512], ['icon-maskable-512', 512, true]]) {
    await p.setViewportSize({ width: size, height: size });
    const inner = maskable ? svg.replace('rx="112"', 'rx="0"') : svg;
    await p.setContent(`<style>html,body{margin:0;background:transparent}svg{width:${size}px;height:${size}px;display:block}</style>${inner}`);
    await p.screenshot({ path: path.join(A, name + '.png'), omitBackground: true });
  }
  await p.setViewportSize({ width: 1200, height: 630 });
  await p.setContent(`<style>@font-face{font-family:L;src:url(${lora})}html,body{margin:0}
    body{width:1200px;height:630px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;
    background:radial-gradient(circle at 88% 15%,#d5e2d9,transparent 40%),radial-gradient(circle at 8% 95%,#eadcc4,transparent 40%),linear-gradient(#eef3ef,#f6efe4);
    font-family:L,serif;color:#2f3a34}svg{width:150px;height:150px}h1{font-size:76px;margin:0;font-weight:500}p{font-size:34px;margin:0;color:#5f7f6e}</style>
    ${svg}<h1>Sophro du jour</h1><p>Un instant de sophrologie, chaque jour</p>`);
  await p.screenshot({ path: path.join(A, 'og-default.png') });
  await b.close();
})();
