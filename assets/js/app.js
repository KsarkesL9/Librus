// assets/js/app.js
document.addEventListener('DOMContentLoaded', () => {

  // === funkcja: deterministyczny pastelowy kolor z napisu ===
  function colorFromString(str) {
    let h = 2166136261 >>> 0;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 16777619) >>> 0;
    }
    const hue = h % 360;
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

  // === Inicjalizacja: nadaj kolory nauczycielom (jeśli istnieją) ===
  document.querySelectorAll('.teacher-name').forEach(el => {
    const txt = el.textContent.trim();
    if (txt) {
      el.style.background = colorFromString(txt);
      el.style.color = '#1f2937';
      el.style.padding = '2px 6px';
      el.style.borderRadius = '6px';
      el.style.display = 'inline-block';
    }
  });

  // === LOGIKA TOOLTIPA DLA OCEN ===
  let tooltipElement = null;

  function createTooltip() {
    if (tooltipElement) return;
    tooltipElement = document.createElement('div');
    tooltipElement.className = 'grade-tooltip';
    document.body.appendChild(tooltipElement);
  }

  function showTooltip(pill) {
    createTooltip();
    
    const data = {
      Kategoria: pill.dataset.cat || '—',
      Data: pill.dataset.date || '—',
      Nauczyciel: pill.dataset.teacher || '—',
      Waga: pill.dataset.weight || '—',
      'Do średniej': pill.dataset.avg || '—',
      Komentarz: pill.dataset.comment || '—'
    };

    tooltipElement.innerHTML = Object.entries(data)
      .map(([key, value]) => `
        <div class="row">
          <span>${escapeHtml(key)}:</span>
          <strong>${escapeHtml(value)}</strong>
        </div>
      `).join('');

    const pillRect = pill.getBoundingClientRect();
    tooltipElement.classList.add('visible'); // Dodajemy klasę `visible`
    const tooltipRect = tooltipElement.getBoundingClientRect();

    let top = pillRect.top - tooltipRect.height - 10;
    let left = pillRect.left + (pillRect.width / 2) - (tooltipRect.width / 2);

    if (top < 10) { // Jeśli nie mieści się na górze
      top = pillRect.bottom + 10;
    }
    if (left < 10) { // Korekta lewej krawędzi
      left = 10;
    }
    if (left + tooltipRect.width > window.innerWidth - 10) { // Korekta prawej krawędzi
      left = window.innerWidth - tooltipRect.width - 10;
    }

    tooltipElement.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
  }

  function hideTooltip() {
    if (tooltipElement) {
      tooltipElement.classList.remove('visible'); // Usuwamy klasę `visible`
    }
  }

  function setupGradePillListeners() {
    let enterTimeout;
    document.querySelectorAll('.grade-pill').forEach(pill => {
      pill.addEventListener('mouseenter', () => {
        clearTimeout(enterTimeout);
        enterTimeout = setTimeout(() => showTooltip(pill), 150); // Małe opóźnienie przed pokazaniem
      });
      pill.addEventListener('mouseleave', () => {
        clearTimeout(enterTimeout);
        hideTooltip();
      });
    });
  }

  setupGradePillListeners();

  // Ukryj tooltip przy przewijaniu lub zmianie rozmiaru okna
  window.addEventListener('scroll', hideTooltip, true);
  window.addEventListener('resize', hideTooltip, true);
  
  // === NOWA LOGIKA MODALA WYLOGOWANIA ===
  const logoutLink = document.getElementById('logout-link');
  const logoutModal = document.getElementById('logout-modal');

  // Sprawdzamy, czy kluczowe elementy istnieją w DOM
  if (logoutLink && logoutModal) {
    const logoutConfirmBtn = document.getElementById('logout-confirm');
    const logoutCancelBtn = document.getElementById('logout-cancel');

    if (logoutConfirmBtn && logoutCancelBtn) {
      const showLogoutModal = (e) => {
        e.preventDefault(); // Zatrzymujemy domyślną akcję linku
        const logoutUrl = logoutLink.href;
        logoutConfirmBtn.href = logoutUrl;
        logoutModal.classList.add('visible');
      };

      const hideLogoutModal = () => {
        logoutModal.classList.remove('visible');
      };

      // Podpinamy event listenery
      logoutLink.addEventListener('click', showLogoutModal);
      logoutCancelBtn.addEventListener('click', hideLogoutModal);
      logoutModal.addEventListener('click', (e) => {
        // Zamyka modal po kliknięciu w tło
        if (e.target === logoutModal) {
          hideLogoutModal();
        }
      });
      console.log('Zenith Nexus: Logika modala wylogowania została poprawnie załadowana.');
    } else {
      console.error('Zenith Nexus: Nie znaleziono przycisków potwierdzenia lub anulowania w modalu.');
    }
  } else {
    console.error('Zenith Nexus: Nie znaleziono linku wylogowania (#logout-link) lub kontenera modala (#logout-modal).');
  }
});