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

  // Nadaj kolory wszystkim .ass-color na podstawie data-title
  function paintAllColors() {
    document.querySelectorAll('.ass-color').forEach(dot => {
      const title = dot.getAttribute('data-title') || '';
      dot.style.background = colorFromString(title.trim());
    });
  }
  paintAllColors();

  // --- obsługa prostego AJAX dla formularza dodawania (opcja) ---
  const addForm = document.getElementById('ass-add-form');
  if (addForm) {
    addForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      const formData = new FormData(addForm);
      // Wyślij fetch do teacher_api.php
      fetch('teacher_api.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(json => {
        if (!json.ok) throw new Error(json.error || 'Błąd serwera');
        // jeżeli API zwrócił nowy wiersz — dodaj go do tabeli
        const row = json.row;
        if (row) {
          const tbody = document.querySelector('#ass-table tbody');
          const tr = document.createElement('tr');
          tr.setAttribute('data-id', row.id);
          tr.innerHTML = `
            <td>
              <div class="ass-head">
                <span class="ass-color" data-title="${escapeHtml(row.title)}"></span>
                <strong>${escapeHtml(row.title)}</strong>
              </div>
            </td>
            <td>${escapeHtml(row.cat_name || '—')}</td>
            <td>${escapeHtml(row.weight)}</td>
            <td>${row.counts_to_avg ? 'Tak' : 'Nie'}</td>
            <td>${escapeHtml(row.issue_date)}</td>
            <td>
              <button class="btn-edit" data-id="${row.id}">Edytuj</button>
              <button class="btn-delete" data-id="${row.id}">Usuń</button>
            </td>
          `;
          tbody.prepend(tr);
          paintAllColors();
          addForm.reset();
        } else {
          // fallback: jeśli API nie dał row, to odśwież stronę
          location.reload();
        }
      })
      .catch(err => {
        alert('Błąd: ' + err.message);
      });
    });
  }

  // --- delegacja: obsługa przycisków Usuń / Edytuj ---
  document.body.addEventListener('click', (ev) => {
    const del = ev.target.closest('.btn-delete');
    if (del) {
      const id = del.getAttribute('data-id');
      if (!confirm('Na pewno usunąć kolumnę?')) return;
      fetch('teacher_api.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'delete', id })
      })
      .then(r => r.json())
      .then(json => {
        if (!json.ok) throw new Error(json.error || 'Błąd');
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        if (tr) tr.remove();
      })
      .catch(err => alert('Błąd: ' + err.message));
    }

    const ed = ev.target.closest('.btn-edit');
    if (ed) {
      const id = ed.getAttribute('data-id');
      // prosty edytor modalny prompt (szybkie rozwiązanie). Można zamienić na formularz.
      const tr = document.querySelector(`tr[data-id="${id}"]`);
      if (!tr) return;
      const oldTitle = tr.querySelector('.ass-head strong')?.textContent || '';
      const newTitle = prompt('Nowy tytuł kolumny:', oldTitle);
      if (newTitle === null) return;
      // można też edytować wagę/kategorię - tutaj tylko tytuł dla prostoty
      fetch('teacher_api.php', {
        method: 'POST',
        body: new URLSearchParams({
          action: 'edit',
          id,
          title: newTitle,
          // zachowujemy pozostałe wartości: pobierz z tr
          category_id: '', // leave as empty (server will accept)
          weight: tr.children[2]?.textContent?.trim() || 1,
          counts_to_avg: tr.children[3]?.textContent?.trim() === 'Tak' ? 1 : 0,
          issue_date: tr.children[4]?.textContent?.trim() || ''
        })
      })
      .then(r => r.json())
      .then(json => {
        if (!json.ok) throw new Error(json.error || 'Błąd');
        // update UI
        tr.querySelector('.ass-head strong').textContent = newTitle;
        tr.querySelector('.ass-color').setAttribute('data-title', newTitle);
        paintAllColors();
      })
      .catch(err => alert('Błąd: ' + err.message));
    }
  });

  // Utility: small escaping for insertion into innerHTML
  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // reset button for form
  const resetBtn = document.getElementById('add-reset');
  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      addForm?.reset();
    });
  }

  // --- Tooltip ocen ---
  let tooltipTimeout;
  let tooltip = document.querySelector('.grade-tooltip');
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.className = 'grade-tooltip hidden';
    document.body.appendChild(tooltip);
  }

  document.addEventListener('mouseover', (e) => {
    const gradePill = e.target.closest('.grade-pill');
    if (gradePill) {
      clearTimeout(tooltipTimeout);
      const data = gradePill.dataset;
      
      const content = `
        <div class="row"><strong>Kategoria:</strong><span>${escapeHtml(data.cat)}</span></div>
        <div class="row"><strong>Data:</strong><span>${escapeHtml(data.date)}</span></div>
        <div class="row"><strong>Nauczyciel:</strong><span>${escapeHtml(data.teacher)}</span></div>
        <div class="row"><strong>Waga:</strong><span>${escapeHtml(data.weight)}</span></div>
        <div class="row"><strong>Do średniej:</strong><span>${escapeHtml(data.avg)}</span></div>
        <div class="row"><strong>Komentarz:</strong><span>${escapeHtml(data.comment)}</span></div>
      `;
      tooltip.innerHTML = content;

      tooltipTimeout = setTimeout(() => {
        tooltip.classList.remove('hidden');
        const pillRect = gradePill.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        
        let top = pillRect.bottom + 10;
        let left = pillRect.left + (pillRect.width / 2) - (tooltipRect.width / 2);

        // Adjust position to stay within the viewport
        if (top + tooltipRect.height > window.innerHeight) {
            top = pillRect.top - tooltipRect.height - 10;
        }
        if (left < 0) {
            left = 10;
        } else if (left + tooltipRect.width > window.innerWidth) {
            left = window.innerWidth - tooltipRect.width - 10;
        }

        tooltip.style.top = `${top + window.scrollY}px`;
        tooltip.style.left = `${left + window.scrollX}px`;
      }, 500); // 500ms delay
    } else {
      clearTimeout(tooltipTimeout);
      tooltipTimeout = setTimeout(() => {
        tooltip.classList.add('hidden');
      }, 200); // 200ms delay to hide
    }
  });

  document.addEventListener('mouseout', (e) => {
    const gradePill = e.target.closest('.grade-pill');
    if (!gradePill && !e.relatedTarget.closest('.grade-tooltip')) {
      clearTimeout(tooltipTimeout);
      tooltipTimeout = setTimeout(() => {
        tooltip.classList.add('hidden');
      }, 200);
    }
  });

  tooltip.addEventListener('mouseenter', () => clearTimeout(tooltipTimeout));
  tooltip.addEventListener('mouseleave', () => {
    tooltipTimeout = setTimeout(() => {
      tooltip.classList.add('hidden');
    }, 200);
  });
}); // DOMContentLoaded