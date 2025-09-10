// assets/js/app.js

// 1) Show/Hide password (poprawione)
(function () {
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.showpass');
    if (!btn) return;
    const wrap = btn.closest('.input-wrap');
    if (!wrap) return;
    const input = wrap.querySelector('input[type="password"], input[type="text"]');
    if (!input) return;

    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.classList.toggle('on', show);

    const svg = btn.querySelector('svg');
    if (svg) {
      svg.setAttribute('viewBox', '0 0 24 24');
      svg.setAttribute('fill', 'currentColor');
      svg.innerHTML = show
        ? '<path d="M2 12c1-2.5 5-7 10-7 2.1 0 4 .6 5.6 1.6l1.8-1.8 1.4 1.4-1.7 1.7C20.7 9 22 10.6 22 12c-1 2.5-5 7-10 7-2.1 0-4-.6-5.6-1.6L4.6 19.2 3.2 17.8l1.7-1.7C3.3 15 2 13.4 2 12Zm5.3.1c.3 2.5 2.3 4.5 4.8 4.8.9.1 1.8 0 2.6-.3l-7.1-7.1c-.3.8-.4 1.7-.3 2.6Zm9.4-.2a5 5 0 0 0-4.6-4.6c-.9-.1-1.8 0-2.6.3l7.2 7.2c.3-.8.4-1.7.3-2.6Z"/>'
        : '<path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/>';
    }
  });

  document.addEventListener('keydown', function (e) {
    const btn = e.target.closest('.showpass');
    if (!btn) return;
    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      btn.click();
    }
  });
})();

// 2) Tooltip z detalami oceny
(function () {
  const tip = document.createElement('div');
  tip.className = 'grade-tooltip hidden';
  document.body.appendChild(tip);

  let active = null;

  function showTip(el, evt) {
    const content = `
      <div class="row"><span>Kategoria:</span><strong>${escapeHTML(el.dataset.cat || '—')}</strong></div>
      <div class="row"><span>Data:</span><strong>${escapeHTML(el.dataset.date || '—')}</strong></div>
      <div class="row"><span>Nauczyciel:</span><strong>${escapeHTML(el.dataset.teacher || '—')}</strong></div>
      <div class="row"><span>Waga:</span><strong>${escapeHTML(el.dataset.weight || '1')}</strong></div>
      <div class="row"><span>Do średniej:</span><strong>${escapeHTML(el.dataset.avg || 'tak')}</strong></div>
      <div class="row"><span>Komentarz:</span><strong>${escapeHTML(el.dataset.comment || '—')}</strong></div>
    `;
    tip.innerHTML = content;
    tip.classList.remove('hidden');
    position(evt);
    active = el;
  }

  function hideTip() {
    tip.classList.add('hidden');
    active = null;
  }

  function position(evt) {
    const pad = 12;
    let x = evt.clientX + pad;
    let y = evt.clientY + pad;
    const r = tip.getBoundingClientRect();
    if (x + r.width > window.innerWidth - 8) x = evt.clientX - r.width - pad;
    if (y + r.height > window.innerHeight - 8) y = evt.clientY - r.height - pad;
    tip.style.transform = `translate(${x}px, ${y}px)`;
  }

  document.addEventListener('mouseenter', function (e) {
    const pill = e.target.closest('.grade-pill');
    if (!pill) return;
    showTip(pill, e);
  }, true);

  document.addEventListener('mousemove', function (e) {
    if (!active) return;
    position(e);
  }, true);

  document.addEventListener('mouseleave', function (e) {
    const pill = e.target.closest('.grade-pill');
    if (!pill) return;
    hideTip();
  }, true);

  function escapeHTML(s) {
    return (s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
})();
