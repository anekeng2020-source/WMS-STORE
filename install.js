/* PWA installer — "Download / install this app" for desktop PC + mobile.
   Drop next to index.html and load early in <head>:  <script src="install.js"></script>

   Triggers:  any element with the attribute  data-pwa-install  (event delegation — works
              with React/Vue/plain HTML, even if the element is rendered later), or call
              window.pwaInstall() from your own code.
   Hiding:    when running as the installed app, <html> gets class "pwa-installed" and
              every [data-pwa-install] element is hidden automatically.
   Behaviour: Chrome/Edge (Android + PC) → native install prompt.
              iPhone/iPad, Mac Safari, Firefox, or no prompt available → step-by-step sheet.
   Config (optional, set BEFORE this script):
     window.PWA_INSTALL = { name: 'My App', icon: 'icon-192.png', lang: 'th' | 'en', sw: 'sw.js' } */
(function () {
  var cfg = window.PWA_INSTALL || {};
  var root = document.documentElement;
  var NAME = cfg.name || (document.querySelector('meta[name="apple-mobile-web-app-title"]') || {}).content || document.title || 'App';
  var ICON = cfg.icon || 'icon-192.png';
  var LANG = cfg.lang || (/^th/i.test(root.lang || navigator.language || '') ? 'th' : 'en');
  var deferred = null;

  function isInstalled() {
    var mm = window.matchMedia;
    return (mm && (mm('(display-mode: standalone)').matches || mm('(display-mode: window-controls-overlay)').matches))
      || window.navigator.standalone === true;
  }
  function markInstalled() { root.classList.add('pwa-installed'); }
  if (isInstalled()) markInstalled();

  var secure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
  if ('serviceWorker' in navigator && secure && cfg.sw !== false) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(cfg.sw || 'sw.js').catch(function () {});
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; });
  window.addEventListener('appinstalled', function () { deferred = null; markInstalled(); closeSheet(); });

  function platform() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'ios';
    if (/Android/.test(ua)) return 'android';
    if (/Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg\//.test(ua)) return 'macsafari';
    if (/Firefox\//.test(ua)) return 'firefox';
    return 'desktop';
  }

  var T = {
    th: {
      sub: 'เปิดได้เหมือนแอปทั่วไป', ok: 'เข้าใจแล้ว', close: 'ปิด',
      https: 'หน้านี้ไม่ได้เปิดผ่าน <b>https://</b> — เบราว์เซอร์ติดตั้งเป็นแอปเต็มรูปแบบได้เฉพาะเว็บ https',
      ios: ['ติดตั้งบน iPhone / iPad', [
        'เปิดหน้านี้ด้วย <b>Safari</b> (ถ้าเปิดจากแอปอื่น เช่น LINE ให้กด “เปิดใน Safari” ก่อน)',
        'แตะปุ่ม <b>แชร์</b> <span class="pwa-k">⬆︎</span> ที่แถบล่าง (iPad อยู่มุมขวาบน)',
        'เลื่อนลงแล้วแตะ <b>“เพิ่มไปยังหน้าจอโฮม”</b>',
        'แตะ <b>“เพิ่ม”</b> — ไอคอนแอปจะอยู่บนหน้าจอโฮม']],
      android: ['ติดตั้งบน Android', [
        'เปิดหน้านี้ด้วย <b>Chrome</b>', 'แตะเมนู <b>⋮</b> มุมขวาบน',
        'แตะ <b>“ติดตั้งแอป”</b> หรือ <b>“เพิ่มลงในหน้าจอหลัก”</b>',
        'แตะ <b>“ติดตั้ง”</b> — ไอคอนจะอยู่ในหน้าจอหลักและลิ้นชักแอป']],
      desktop: ['ติดตั้งบนคอมพิวเตอร์', [
        'เปิดหน้านี้ด้วย <b>Google Chrome</b> หรือ <b>Microsoft Edge</b>',
        'คลิกไอคอน <b>ติดตั้ง</b> <span class="pwa-k">⊕</span> ท้ายช่อง URL ด้านบน',
        'หรือเมนู <b>⋮ / …</b> → <b>“ติดตั้ง ' + NAME + '”</b> (Edge: <b>แอป → ติดตั้งไซต์นี้เป็นแอป</b>)',
        'กด <b>“ติดตั้ง”</b> — แอปจะมีไอคอนบนเดสก์ท็อป / Start menu และเปิดเป็นหน้าต่างของตัวเอง']],
      macsafari: ['ติดตั้งบน Mac (Safari)', [
        'เปิดเมนู <b>File (ไฟล์)</b> หรือปุ่ม <b>แชร์</b>', 'เลือก <b>“Add to Dock (เพิ่มไปยัง Dock)”</b>',
        'กด <b>“เพิ่ม”</b> — แอปจะอยู่ใน Dock และ Launchpad']],
      firefox: ['ติดตั้งแอป', [
        'Firefox บนคอมพิวเตอร์ยังติดตั้งเว็บแอปไม่ได้',
        'เปิดลิงก์นี้ด้วย <b>Chrome</b> หรือ <b>Edge</b> แล้วกดติดตั้งอีกครั้ง']]
    },
    en: {
      sub: 'Opens like a regular app', ok: 'Got it', close: 'Close',
      https: 'This page is not served over <b>https://</b> — browsers only fully install secure (https) sites.',
      ios: ['Install on iPhone / iPad', [
        'Open this page in <b>Safari</b> (from LINE/Facebook etc., choose “Open in Safari” first)',
        'Tap <b>Share</b> <span class="pwa-k">⬆︎</span> in the bottom bar (top-right on iPad)',
        'Scroll down and tap <b>“Add to Home Screen”</b>',
        'Tap <b>“Add”</b> — the app icon appears on your home screen']],
      android: ['Install on Android', [
        'Open this page in <b>Chrome</b>', 'Tap the <b>⋮</b> menu (top-right)',
        'Tap <b>“Install app”</b> or <b>“Add to Home screen”</b>',
        'Tap <b>“Install”</b> — the icon appears on your home screen and app drawer']],
      desktop: ['Install on your computer', [
        'Open this page in <b>Google Chrome</b> or <b>Microsoft Edge</b>',
        'Click the <b>install</b> icon <span class="pwa-k">⊕</span> at the end of the address bar',
        'Or open the <b>⋮ / …</b> menu → <b>“Install ' + NAME + '”</b> (Edge: <b>Apps → Install this site as an app</b>)',
        'Click <b>“Install”</b> — you get a desktop / Start-menu icon and its own window']],
      macsafari: ['Install on Mac (Safari)', [
        'Open the <b>File</b> menu or the <b>Share</b> button', 'Choose <b>“Add to Dock”</b>',
        'Click <b>“Add”</b> — the app appears in the Dock and Launchpad']],
      firefox: ['Install the app', [
        'Firefox on desktop cannot install web apps yet',
        'Open this link in <b>Chrome</b> or <b>Edge</b> and press install again']]
    }
  }[LANG];

  var CSS =
    'html.pwa-installed [data-pwa-install]{display:none!important}' +
    '.pwa-bd{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center}' +
    '.pwa-sh{position:relative;width:100%;max-width:520px;background:#fff;color:#1f2937;border-radius:22px 22px 0 0;padding:20px 18px calc(env(safe-area-inset-bottom) + 18px);font:inherit;font-size:14px;line-height:1.5;box-sizing:border-box;animation:pwaUp .22s ease-out}' +
    '@keyframes pwaUp{from{transform:translateY(30px);opacity:0}}' +
    '@media (min-width:600px){.pwa-bd{align-items:center}.pwa-sh{border-radius:22px;max-width:440px}}' +
    '.pwa-x{position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:50%;background:#f3f4f6;font-size:20px;color:#6b7280;cursor:pointer}' +
    '.pwa-hd{display:flex;gap:12px;align-items:center;margin-bottom:14px;padding-right:36px}.pwa-hd img{width:48px;height:48px;border-radius:12px;flex:none}' +
    '.pwa-hd b{display:block;font-size:17px;font-weight:800}.pwa-hd small{font-size:12px;color:#6b7280}' +
    '.pwa-nt{font-size:12.5px;background:#fff7ed;color:#c2410c;border-radius:10px;padding:9px 11px;margin-bottom:12px}' +
    '.pwa-sh ol{list-style:none;counter-reset:s;display:flex;flex-direction:column;gap:10px;margin:0 0 16px;padding:0}' +
    '.pwa-sh li{counter-increment:s;display:flex;gap:10px}' +
    '.pwa-sh li:before{content:counter(s);flex:none;width:24px;height:24px;border-radius:50%;background:var(--pwa-soft,#eef2ff);color:var(--pwa-accent,#4f46e5);font-weight:800;font-size:12.5px;display:flex;align-items:center;justify-content:center}' +
    '.pwa-k{display:inline-block;padding:0 5px;border-radius:5px;background:#f3f4f6;border:1px solid #e5e7eb}' +
    '.pwa-ok{width:100%;padding:13px;border:0;border-radius:13px;background:var(--pwa-accent,#4f46e5);color:#fff;font:inherit;font-weight:800;font-size:15px;cursor:pointer}';
  var st = document.createElement('style');
  st.textContent = CSS;
  (document.head || root).appendChild(st);

  var sheet = null;
  function closeSheet() { if (sheet) { sheet.remove(); sheet = null; } }
  function showSteps(key, note) {
    closeSheet();
    var s = T[key] || T.desktop;
    sheet = document.createElement('div');
    sheet.className = 'pwa-bd';
    sheet.innerHTML =
      '<div class="pwa-sh" role="dialog" aria-modal="true" aria-label="' + s[0] + '">' +
        '<button type="button" class="pwa-x" aria-label="' + T.close + '">×</button>' +
        '<div class="pwa-hd"><img src="' + ICON + '" alt=""><div><b>' + s[0] + '</b><small>' + NAME + ' · ' + T.sub + '</small></div></div>' +
        (note ? '<div class="pwa-nt">' + note + '</div>' : '') +
        // each step wrapped in <span>: the <li> is flex, so bare <b> tags would become separate flex items and break the line
        '<ol>' + s[1].map(function (t) { return '<li><span>' + t + '</span></li>'; }).join('') + '</ol>' +
        '<button type="button" class="pwa-ok">' + T.ok + '</button>' +
      '</div>';
    sheet.addEventListener('click', function (e) {
      if (e.target === sheet || e.target.closest('.pwa-x, .pwa-ok')) closeSheet();
    });
    document.body.appendChild(sheet);
  }

  window.pwaInstall = function () {
    if (isInstalled()) { markInstalled(); return; }
    if (deferred) {
      var p = deferred;
      deferred = null;                                   // a prompt event can only be used once
      p.prompt();
      p.userChoice.then(function (r) { if (r && r.outcome === 'accepted') markInstalled(); }).catch(function () {});
      return;
    }
    showSteps(platform(), secure ? '' : T.https);
  };

  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-pwa-install]');
    if (el) { e.preventDefault(); window.pwaInstall(); }
  });
})();
