// assets/js/teacher.js - nowa, przepisana wersja
(function () {
  const root = document.getElementById('teacher-root');
  if (!root) {
    console.error('[teacher.js] BŁĄD: Nie znaleziono elementu #teacher-root. Skrypt nie działa.');
    return;
  }

  const API = root.dataset.api;
  const CSRF = root.dataset.csrf;
  const CLASS_ID = root.dataset.classId;
  const SUBJECT_ID = root.dataset.subjectId;
  const TERM_ID = root.dataset.termId;

  const log = (msg, ...args) => console.log(`%c[teacher.js] ${msg}`, 'color:#7c3aed;font-weight:700', ...args);
  const showFlashMessage = (type, message) => {
    const flashDiv = document.createElement('div');
    flashDiv.className = type === 'success' ? 'success' : 'alert';
    flashDiv.style.margin = '0 1.25rem 1rem';
    flashDiv.innerHTML = `<p>${message}</p>`;
    root.prepend(flashDiv);
    setTimeout(() => flashDiv.remove(), 5000);
  };

  async function postData(action, data) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('class_id', CLASS_ID);
    fd.append('subject_id', SUBJECT_ID);
    if (TERM_ID) fd.append('term_id', TERM_ID);
    fd.append('action', action);
    for (const key in data) {
      if (data.hasOwnProperty(key)) {
        fd.append(key, data[key]);
      }
    }
    log(`Wysyłam żądanie POST: ${action}`, Object.fromEntries(fd.entries()));
    const response = await fetch(API, { method: 'POST', body: fd });
    const json = await response.json();
    log(`Odpowiedź z serwera dla ${action}`, json);
    if (!response.ok || !json.ok) {
      throw new Error(json.error || `Błąd serwera: ${response.status}`);
    }
    return json;
  }

  const renderGradeView = (grade, hasImproved) => {
    if (!grade || grade.value_text === '') {
      return '<span class="small-muted">—</span><button type="button" class="btn small edit-btn">Edytuj</button>';
    }
    const imprClass = hasImproved ? ' impr' : '';
    const imprText = hasImproved ? ' ↻' : '';
    return `<span class="pill${imprClass}">${grade.value_text}${imprText}</span>` +
           `<button type="button" class="btn small impr-btn">Popraw</button>` +
           `<button type="button" class="btn small del-btn">Usuń</button>`;
  };

  const renderEditForm = (gradeId, value, comment) => {
    const gradeHtml = value ? escapeHtml(value) : '';
    const commentHtml = comment ? escapeHtml(comment) : '';
    const qgButtons = ['6','6+','5','5-','5+','4','4-','4+','3','3-','3+','2','2-','2+','1','1+','0','+','-']
      .map(q => `<button type="button" data-val="${escapeHtml(q)}">${escapeHtml(q)}</button>`)
      .join('');
    
    return `<div class="edit-form" data-grade-id="${gradeId}">
      <div class="input-wrap">
        <input class="input-grade" placeholder="np. 4+, 3, +, -" value="${gradeHtml}">
      </div>
      <div class="qg">${qgButtons}</div>
      <div class="input-wrap">
        <input class="input-comment" placeholder="Komentarz" value="${commentHtml}">
      </div>
      <div class="qg">
        <button type="button" class="btn primary save-btn">Zapisz</button>
        <button type="button" class="btn cancel-btn">Anuluj</button>
      </div>
    </div>`;
  };
  
  const escapeHtml = (s) => (s || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  // Logika interakcji z tabelą
  document.addEventListener('click', async (e) => {
    const target = e.target;
    const cell = target.closest('.cell');
    if (!cell) return;

    if (target.closest('.edit-btn')) {
      const gradeId = cell.dataset.gradeId;
      const gradeText = cell.querySelector('.pill')?.textContent || '';
      const commentText = '';
      
      const formHtml = renderEditForm(gradeId, gradeText, commentText);
      cell.innerHTML = formHtml;
    } else if (target.closest('.cancel-btn')) {
      const gradeId = cell.dataset.gradeId;
      const gradeText = cell.querySelector('.input-grade')?.value || '';
      const hasImproved = gradeText.includes('↻');
      
      const viewHtml = renderGradeView({ value_text: gradeText }, hasImproved);
      cell.innerHTML = viewHtml;
    } else if (target.closest('.save-btn')) {
      const gradeId = cell.dataset.gradeId;
      const assId = cell.dataset.assessmentId;
      const studentId = cell.closest('tr').dataset.studentId;
      
      const valueText = cell.querySelector('.input-grade')?.value || '';
      const comment = cell.querySelector('.input-comment')?.value || '';
      
      try {
        const result = await postData('grade_set', {
          assessment_id: assId,
          student_id: studentId,
          grade_id: gradeId,
          value_text: valueText,
          comment: comment
        });
        
        cell.dataset.gradeId = result.grade.id;
        cell.innerHTML = renderGradeView(result.grade, result.grade.improved_of_id !== null);
        const avgCell = cell.closest('tr').querySelector('.avg');
        if (avgCell) avgCell.textContent = result.avg;
        showFlashMessage('success', 'Ocena zapisana pomyślnie.');
      } catch (error) {
        showFlashMessage('alert', error.message);
      }
    } else if (target.closest('.impr-btn')) {
      const gradeId = cell.dataset.gradeId;
      const studentId = cell.closest('tr').dataset.studentId;
      const newValue = prompt('Wpisz nową ocenę:');
      if (newValue === null) return;
      try {
        const result = await postData('grade_improve', {
          grade_id: gradeId,
          value_text: newValue
        });
        cell.dataset.gradeId = result.grade.id;
        cell.innerHTML = renderGradeView(result.grade, true);
        const avgCell = cell.closest('tr').querySelector('.avg');
        if (avgCell) avgCell.textContent = result.avg;
        showFlashMessage('success', 'Ocena poprawiona.');
      } catch (error) {
        showFlashMessage('alert', error.message);
      }
    } else if (target.closest('.del-btn')) {
      const gradeId = cell.dataset.gradeId;
      const studentId = cell.closest('tr').dataset.studentId;
      if (!gradeId || !confirm('Usunąć tę ocenę?')) return;
      try {
        const result = await postData('grade_delete', { grade_id: gradeId });
        cell.dataset.gradeId = '';
        cell.innerHTML = renderGradeView({ value_text: '' }, false);
        const avgCell = cell.closest('tr').querySelector('.avg');
        if (avgCell) avgCell.textContent = result.avg;
        showFlashMessage('success', 'Usunięto ocenę.');
      } catch (error) {
        showFlashMessage('alert', error.message);
      }
    } else if (target.closest('.qg button')) {
      const input = cell.querySelector('.input-grade');
      if (input) {
        input.value = target.dataset.val;
        input.focus();
      }
    }
  });

  document.querySelectorAll('.summary-input').forEach(input => {
    // Tutaj należy dodać obsługę zapisu ocen podsumowujących,
    // jeśli jest taka potrzeba. Obecnie brakuje do tego logiki.
  });
})();