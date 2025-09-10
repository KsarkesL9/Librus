// app.js
// Front: deterministyczne generowanie koloru oraz prosty AJAX do teacher_api.php
document.addEventListener('DOMContentLoaded', () => {

  // === funkcja: deterministyczny pastelowy kolor z napisu ===
  function colorFromString(str) {
    // prosty 32-bitowy hash
    let h = 2166136261 >>> 0;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 16777619) >>> 0;
    }
    const hue = h % 360;
    // HSL w formacie CSS z separatorem spacją dla kompatybilności modern browsers
    return `hsl(${hue} 65% 82%)`;
  }

  function escapeHtml(s) {
    if (!s && s !== 0) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // === helpers DOM ===
  function qs(sel, ctx = document) { return ctx.querySelector(sel); }
  function qsa(sel, ctx = document) { return Array.from(ctx.querySelectorAll(sel)); }

  // === Inicjalizacja: nadaj kolory nauczycielom ===
  const teacherElems = qsa('.teacher-name');
  teacherElems.forEach(el => {
    const txt = el.textContent.trim();
    const bg = colorFromString(txt);
    el.style.background = bg;
    el.style.color = '#1f2937';
    el.style.padding = '2px 6px';
    el.style.borderRadius = '6px';
    el.style.display = 'inline-block';
  });

  // === AJAX helper (fetch wrapper) ===
  async function apiPost(action, payload = {}) {
    const body = new FormData();
    body.append('action', action);
    for (const k in payload) {
      body.append(k, payload[k]);
    }
    const res = await fetch('teacher_api.php', {
      method: 'POST',
      body,
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('API error: ' + res.status);
    const data = await res.json();
    return data;
  }

  // === Obsługa przycisku dodawania zadania / oceny ===
  const addBtn = qs('#ass-add');
  const addForm = qs('#ass-add-form');
  if (addBtn && addForm) {
    addBtn.addEventListener('click', (e) => {
      e.preventDefault();
      addForm.classList.toggle('hidden');
    });

    addForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(addForm);
      try {
        const result = await apiPost('add_assignment', Object.fromEntries(fd.entries()));
        if (result.success) {
          location.reload();
        } else {
          alert('Błąd: ' + (result.error || 'nieznany'));
        }
      } catch (err) {
        console.error(err);
        alert('Błąd połączenia z serwerem.');
      }
    });
  }

  // === PROSTSZE TOOLTIPS BEZ SKOMPLIKOWANYCH EVENT LISTENERÓW ===
  let tooltip = null;
  let currentPill = null;
  let showTimeout = null;
  let hideTimeout = null;

  function createTooltip() {
    if (tooltip) return tooltip;
    
    tooltip = document.createElement('div');
    tooltip.className = 'grade-tooltip hidden';
    document.body.appendChild(tooltip);
    console.log('[Tooltip] Utworzono tooltip');
    return tooltip;
  }

  function showTooltip(pill) {
    if (!pill) return;
    
    currentPill = pill;
    const tooltip = createTooltip();
    
    const data = {
      cat: pill.dataset.cat || 'Brak danych',
      date: pill.dataset.date || 'Brak danych', 
      teacher: pill.dataset.teacher || 'Brak danych',
      weight: pill.dataset.weight || '1',
      avg: pill.dataset.avg || 'tak',
      comment: pill.dataset.comment || 'Brak komentarza'
    };
    
    console.log('[Tooltip] Pokazuję tooltip dla:', data);
    
    tooltip.innerHTML = `
      <div><strong>Kategoria:</strong> ${escapeHtml(data.cat)}</div>
      <div><strong>Data:</strong> ${escapeHtml(data.date)}</div>
      <div><strong>Nauczyciel:</strong> ${escapeHtml(data.teacher)}</div>
      <div><strong>Waga:</strong> ${escapeHtml(data.weight)}</div>
      <div><strong>Do średniej:</strong> ${escapeHtml(data.avg)}</div>
      <div><strong>Komentarz:</strong> ${escapeHtml(data.comment)}</div>
    `;
    
    // Ustaw widoczność i pozycję
    tooltip.classList.remove('hidden');
    
    const tooltipRect = tooltip.getBoundingClientRect();
    const pillRect = pill.getBoundingClientRect();
    
    let left = pillRect.left + (pillRect.width / 2) - (tooltipRect.width / 2);
    let top = pillRect.top - tooltipRect.height - 8;
    
    if (top < 0) {
      top = pillRect.bottom + 8;
    }
    
    if (left < 8) left = 8;
    if (left + tooltipRect.width > window.innerWidth - 8) {
      left = window.innerWidth - tooltipRect.width - 8;
    }
    
    tooltip.style.left = Math.round(left) + 'px';
    tooltip.style.top = Math.round(top) + 'px';
    
    console.log('[Tooltip] Ustawiam pozycję:', { left: Math.round(left), top: Math.round(top) });
  }

  function hideTooltip() {
    if (tooltip) {
      tooltip.classList.add('hidden');
      currentPill = null;
    }
  }

  function setupTooltips() {
    const pills = document.querySelectorAll('.grade-pill');
    console.log(`[Tooltip] Konfiguracja tooltipów dla ${pills.length} ocen`);
    
    pills.forEach(pill => {
      pill.addEventListener('mouseenter', () => {
        clearTimeout(hideTimeout);
        showTimeout = setTimeout(() => {
          showTooltip(pill);
        }, 500);
      });
      
      pill.addEventListener('mouseleave', () => {
        clearTimeout(showTimeout);
        hideTimeout = setTimeout(() => {
          if (currentPill === pill) {
            hideTooltip();
          }
        }, 200);
      });
    });
    
    if (tooltip) {
      tooltip.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
      tooltip.addEventListener('mouseleave', () => hideTooltip());
    }
  }

  document.addEventListener('scroll', hideTooltip);
  window.addEventListener('resize', hideTooltip);

  setTimeout(() => {
    setupTooltips();
    
    const pills = document.querySelectorAll('.grade-pill');
    console.log(`[Tooltip] Skonfigurowano ${pills.length} ocen`);
    
    if (pills.length > 0) {
      const firstPill = pills[0];
      console.log('[Tooltip] Pierwsza ocena:', {
        text: firstPill.textContent,
        data: firstPill.dataset,
        rect: firstPill.getBoundingClientRect()
      });
    }
  }, 100);

}); // DOMContentLoaded