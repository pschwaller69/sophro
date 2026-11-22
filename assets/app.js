(() => {
  'use strict';

  // --- Service worker (application installable) ---
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }

  // --- Bouton « Installer l'app » ---
  const installBtn = document.getElementById('install-btn');
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (installBtn) installBtn.hidden = false;
  });
  installBtn?.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    installBtn.hidden = true;
  });
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const hint = document.getElementById('ios-hint');
  if (hint && isIOS && !standalone) hint.hidden = false;

  // --- Mesure d'audience anonyme : « combien de personnes ont vu l'instant » ---
  // Un numéro au hasard propre à l'appareil, gardé sur le téléphone, jamais partagé avec un autre site.
  const viewed = document.querySelector('main[data-view]');
  if (viewed) {
    let vid = '';
    try {
      vid = localStorage.getItem('sophro-vid') || '';
      if (!vid) { vid = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()); localStorage.setItem('sophro-vid', vid); }
    } catch (e) { /* navigation privée : non compté */ }
    const src = new URLSearchParams(location.search).get('source');
    const body = JSON.stringify({
      p: Number(viewed.dataset.view), kind: viewed.dataset.viewKind, v: vid,
      source: src === 'notification' ? 'notification' : (standalone || src === 'pwa' ? 'app' : 'web'),
    });
    if (vid) fetch('/api/hit.php', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => {});
  }

  // --- Pastille sur l'icône : on l'enlève dès que l'app est ouverte ---
  const clearBadge = () => {
    if ('clearAppBadge' in navigator) navigator.clearAppBadge().catch(() => {});
    navigator.serviceWorker?.ready.then((reg) => reg.getNotifications?.().then((ns) => ns.forEach((n) => n.close()))).catch(() => {});
  };
  clearBadge();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) clearBadge(); });

  // --- Notification quotidienne ---
  const notify = document.getElementById('notify');
  const vapid = document.querySelector('meta[name=vapid-key]')?.content;
  const show = (id) => ['notify-on', 'notify-state', 'notify-ios', 'notify-denied'].forEach((x) => {
    const el = document.getElementById(x); if (el) el.hidden = x !== id;
  });
  const keyBytes = (b64) => {
    const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
    return Uint8Array.from(s, (ch) => ch.charCodeAt(0));
  };
  const api = (body) => fetch('/api/subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

  async function initNotify() {
    if (!notify || !vapid) return;
    const pushOk = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!pushOk) {
      if (isIOS && !standalone) { notify.hidden = false; show('notify-ios'); }
      return;
    }
    notify.hidden = false;
    if (Notification.permission === 'denied') return show('notify-denied');
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (sub) { api({ endpoint: sub.endpoint }); show('notify-state'); } else show('notify-on');
  }

  document.getElementById('notify-on')?.addEventListener('click', async () => {
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') return show(perm === 'denied' ? 'notify-denied' : 'notify-on');
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapid) });
      const res = await api({ endpoint: sub.endpoint });
      if (!res.ok) throw new Error('serveur');
      show('notify-state');
    } catch (e) {
      alert("Impossible d'activer les notifications sur cet appareil.");
    }
  });
  document.getElementById('notify-off')?.addEventListener('click', async () => {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (sub) { await api({ action: 'unsubscribe', endpoint: sub.endpoint }); await sub.unsubscribe(); }
    show('notify-on');
  });
  initNotify().catch(() => {});

  // --- Partage ---
  // On prépare l'image à l'avance : Safari exige que navigator.share() soit appelé tout de suite après le clic.
  const files = new Map();
  document.querySelectorAll('[data-share][data-image]').forEach(async (btn) => {
    try {
      const res = await fetch(btn.dataset.image);
      const blob = await res.blob();
      const ext = (blob.type.split('/')[1] || 'jpg').replace('jpeg', 'jpg');
      files.set(btn, new File([blob], 'sophro-du-jour.' + ext, { type: blob.type }));
    } catch (e) { /* tant pis : on partagera le lien seul */ }
  });

  const dialog = document.getElementById('share-dialog');

  function openShareMenu({ url, title, text }) {
    const u = encodeURIComponent(url);
    const t = encodeURIComponent(text || title);
    const links = {
      whatsapp: `https://wa.me/?text=${encodeURIComponent((text || title) + '\n' + url)}`,
      facebook: `https://www.facebook.com/sharer/sharer.php?u=${u}`,
      messenger: `fb-messenger://share/?link=${u}`,
      x: `https://twitter.com/intent/tweet?text=${t}&url=${u}`,
      linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${u}`,
      email: `mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent((text ? text + '\n\n' : '') + url)}`,
    };
    dialog.querySelectorAll('a[data-net]').forEach((a) => { a.href = links[a.dataset.net]; });
    const copy = dialog.querySelector('[data-net=copy]');
    copy.textContent = 'Copier le lien';
    copy.onclick = async () => {
      try { await navigator.clipboard.writeText(url); copy.textContent = 'Lien copié ✔'; }
      catch (e) { prompt('Copiez ce lien :', url); }
    };
    dialog.showModal();
  }

  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-share]');
    if (!btn) return;
    const data = { url: btn.dataset.url, title: btn.dataset.title, text: btn.dataset.text };
    if (navigator.share) {
      try {
        const file = files.get(btn);
        if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: data.title, text: (data.text ? data.text + '\n' : '') + data.url });
        } else {
          await navigator.share(data);
        }
        return;
      } catch (e) {
        if (e.name === 'AbortError') return; // partage annulé par l'utilisateur
      }
    }
    openShareMenu(data);
  });

  // --- Partager l'application (QR code) ---
  const qr = document.getElementById('qr-dialog');
  document.getElementById('share-app')?.addEventListener('click', () => qr?.showModal());
  qr?.querySelector('[data-share-app]')?.addEventListener('click', async (ev) => {
    const d = ev.currentTarget.dataset;
    const data = { url: d.url, title: d.title, text: d.text };
    if (navigator.share) {
      try { await navigator.share(data); return; } catch (e) { if (e.name === 'AbortError') return; }
    }
    qr.close();
    openShareMenu(data);
  });

  // --- Admin : enregistreur audio (micro du téléphone) ---
  function initRecorder(form) {
    const box = document.getElementById('recorder');
    const input = form.querySelector('input[name=audio]');
    if (!box || !input || !window.MediaRecorder || !navigator.mediaDevices?.getUserMedia || !window.DataTransfer) return;
    box.hidden = false;
    const $ = (sel) => box.querySelector(sel);
    const btn = (name) => box.querySelector(`[data-rec=${name}]`);
    // MP4/AAC d'abord : lisible partout (Android, iPhone, ordinateurs)
    const type = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus']
      .find((t) => MediaRecorder.isTypeSupported(t)) || '';
    let rec = null, stream = null, chunks = [], seconds = 0, timer = null, wakeLock = null;

    const show = (state) => {
      btn('start').hidden = state !== 'idle';
      btn('pause').hidden = state !== 'rec';
      btn('resume').hidden = state !== 'paused';
      btn('stop').hidden = state !== 'rec' && state !== 'paused';
      $('.rec-time').hidden = state === 'idle';
      $('.rec-time').classList.toggle('live', state === 'rec');
    };
    const tick = () => { $('.rec-time').textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'); };
    const error = (msg) => { const e = $('.rec-error'); e.textContent = msg; e.hidden = !msg; };

    btn('start').addEventListener('click', async () => {
      error('');
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: true, autoGainControl: true } });
      } catch (e) {
        return error("Impossible d'accéder au micro. Autorisez le micro pour ce site dans les réglages du navigateur.");
      }
      chunks = [];
      rec = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 96000 } : undefined);
      rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
      rec.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        clearInterval(timer);
        wakeLock?.release().catch(() => {});
        const mime = (rec.mimeType || type || 'audio/webm').split(';')[0];
        const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
        const blob = new Blob(chunks, { type: mime });
        const file = new File([blob], `seance-${new Date().toISOString().slice(0, 10)}.${ext}`, { type: mime });
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;               // envoyé avec le formulaire, comme un fichier choisi
        const audio = $('.rec-result audio');
        audio.src = URL.createObjectURL(blob);
        $('.rec-result').hidden = false;
        show('idle');
        btn('start').hidden = true;
      };
      rec.start(1000);
      seconds = 0; tick();
      timer = setInterval(() => { if (rec.state === 'recording') { seconds++; tick(); } }, 1000);
      try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (e) { /* l'écran pourra se mettre en veille */ }
      $('.rec-result').hidden = true;
      show('rec');
    });
    btn('pause').addEventListener('click', () => { rec.pause(); show('paused'); });
    btn('resume').addEventListener('click', () => { rec.resume(); show('rec'); });
    btn('stop').addEventListener('click', () => rec.stop());
    btn('restart').addEventListener('click', () => {
      input.value = '';
      $('.rec-result').hidden = true;
      show('idle');
    });
    show('idle');
  }

  // --- Admin : options selon le mode de publication ---
  const form = document.getElementById('edit-form');
  if (form) {
    const sync = () => {
      const mode = form.querySelector('input[name=mode]:checked')?.value;
      form.querySelectorAll('.mode-opts').forEach((el) => { el.hidden = el.dataset.for !== mode; });
    };
    form.addEventListener('change', sync);
    sync();
    form.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
      const [s, e] = b.dataset.preset.split(',');
      const set = (p, md) => {
        const [m, d] = md.split('-').map(Number);
        form.querySelector(`[name=${p}_m]`).value = m;
        form.querySelector(`[name=${p}_d]`).value = d;
      };
      set('ys', s); set('ye', e);
    }));
    initRecorder(form);
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      btn.textContent = 'Envoi en cours…';
    });
  }
})();
