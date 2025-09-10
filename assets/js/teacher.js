// assets/js/teacher.js — v5 (max debug + capture listeners)
(function () {
  const tag = (lvl, ...a) => console[lvl].apply(console, ['%c[teacher.js v5]', 'color:#7c3aed;font-weight:700', ...a]);
  const log = (...a)=>tag('log', ...a), warn=(...a)=>tag('warn', ...a), err=(...a)=>tag('error', ...a);

  log('loaded (script file fetched)');

  document.addEventListener('DOMContentLoaded', () => {
    log('DOMContentLoaded fired');
  });

  const root = document.getElementById('teacher-root');
  if (!root) { err('root #teacher-root NOT FOUND — script inactive'); return; }

  const API     = root.dataset.api;
  const CSRF    = root.dataset.csrf;
  const clsSel  = document.getElementById('t-class');
  const subSel  = document.getElementById('t-subject');
  const termSel = document.getElementById('t-term');

  log('boot', { API, csrfLen: (CSRF||'').length, cells: root.querySelectorAll('.cell').length, editBtns: root.querySelectorAll('.edit-btn').length });

  window.addEventListener('error', (e)=>err('GlobalError', e.message, e.filename+':'+e.lineno+':'+e.colno, e.error));
  window.addEventListener('unhandledrejection', (e)=>err('UnhandledPromiseRejection', e.reason));

  function fdBase(){ const fd=new FormData(); fd.append('csrf', CSRF||''); return fd; }
  function esc(s){ return (s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  const closestCell = el => el?.closest('.cell');

  // autosubmit selektorów
  [clsSel, subSel, termSel].forEach(sel => sel?.addEventListener('change', () => { log('selector change', sel.id, sel.value); sel.form.submit(); }));

  // ----- Dodawanie kolumny (AJAX; fallback POST działa w teacher.php) -----
  const addForm = document.getElementById('ass-add-form');
  addForm?.addEventListener('submit', async (e)=>{
    if (!API) { warn('no API -> fallback POST'); return; }
    e.preventDefault();
    try {
      const fd = fdBase();
      fd.append('action','ass_add');
      fd.append('class_id',   (document.querySelector('input[name="class_id"]')?.value || clsSel?.value || '').toString());
      fd.append('subject_id', (document.querySelector('input[name="subject_id"]')?.value || subSel?.value || '').toString());
      fd.append('term_id',    (document.querySelector('input[name="term_id"]')?.value || termSel?.value || '').toString());
      ['title','category_id','weight','counts_to_avg','issue_date','color'].forEach(k=>{
        const el = addForm.querySelector(`[name="${k}"]`);
        if (!el) return;
        if (el.type==='checkbox') fd.append(k, el.checked?'1':'0');
        else fd.append(k, el.value || '');
      });
      log('POST ass_add', Object.fromEntries(fd.entries()));
      const res  = await fetch(API,{method:'POST',body:fd});
      const text = await res.text();
      let j=null; try{ j=JSON.parse(text);}catch(_){}
      if (!res.ok){ err('ass_add HTTP', res.status, text); alert('Błąd serwera: '+res.status); return; }
      if (!j){ err('ass_add invalid JSON', text); alert('Nieprawidłowa odpowiedź z serwera.'); return; }
      if (!j.ok){ err('ass_add error', j); alert(j.error||'Nie udało się dodać kolumny'); return; }
      log('ass_add OK -> reload'); location.reload();
    } catch (e2) { err('ass_add exception', e2); alert('Błąd sieci/JS przy dodawaniu kolumny.'); }
  });

  // ===== DELEGACJA KLIKÓW NA CAŁYM DOKUMENCIE (FAZA CAPTURE) =====
  document.addEventListener('click', (e)=>{
    // surowy log wszystkich klików
    const t = e.target;
    log('document click', { tag:t.tagName, cls:t.className, text:(t.textContent||'').trim().slice(0,20) });
  }, true); // <— CAPTURE!

  // Główna logika (bubbling)
  document.addEventListener('click', async (e)=>{
    const t = e.target;
    const editBtn   = t.closest('.edit-btn');
    const saveBtn   = t.closest('.save-btn');
    const cancelBtn = t.closest('.cancel-btn');
    const imprBtn   = t.closest('.impr-btn');
    const delBtn    = t.closest('.del-btn');

    if (editBtn){
      const cell = closestCell(editBtn);
      log('edit clicked', !!cell, cell);
      if (!cell) return;
      root.querySelectorAll('.cell.editing').forEach(c=>c.classList.remove('editing'));
      cell.classList.add('editing');
      const inp = cell.querySelector('.input-grade');
      if (inp){ inp.focus(); try{ inp.select(); }catch(_){} }
      return;
    }

    if (cancelBtn){
      const cell = closestCell(cancelBtn);
      log('cancel clicked', !!cell);
      if (cell) cell.classList.remove('editing');
      return;
    }

    if (saveBtn){
      const cell = closestCell(saveBtn);
      log('save clicked', !!cell);
      if (!cell) return;
      const input = cell.querySelector('.input-grade');
      const comm  = cell.querySelector('.input-comment');
      const fd = fdBase();
      fd.append('action','grade_set');
      fd.append('assessment_id', cell.dataset.assessmentId||'');
      fd.append('student_id',    cell.dataset.studentId||'');
      fd.append('grade_id',      cell.dataset.gradeId||'');
      fd.append('value_text',    (input?.value||'').trim());
      fd.append('comment',       (comm?.value||'').trim());
      log('POST grade_set', Object.fromEntries(fd.entries()));
      const res  = await fetch(API,{method:'POST',body:fd});
      const text = await res.text(); let j=null; try{ j=JSON.parse(text);}catch(_){}
      if (!res.ok){ err('grade_set HTTP', res.status, text); alert('Błąd serwera: '+res.status); return; }
      if (!j){ err('grade_set invalid JSON', text); alert('Nieprawidłowa odpowiedź'); return; }
      if (!j.ok){ err('grade_set error', j); alert(j.error||'Błąd zapisu'); return; }
      cell.dataset.gradeId = j.grade.id;
      cell.querySelector('.view').innerHTML = j.grade.display_html;
      const avgCell = cell.closest('tr').querySelector('.avg'); if (avgCell) avgCell.innerHTML = esc(j.avg);
      cell.classList.remove('editing');
      log('grade_set OK');
      return;
    }

    if (imprBtn){
      const cell = closestCell(imprBtn);
      log('improve clicked', !!cell);
      if (!cell) return;
      const oldId = cell.dataset.gradeId;
      if (!oldId){ warn('improve: no grade_id'); alert('Brak oceny do poprawy.'); return; }
      const val = prompt('Nowa ocena (np. 3+, 5-, +, -):'); if (!val) return;
      const fd = fdBase(); fd.append('action','grade_improve'); fd.append('grade_id', oldId); fd.append('value_text', val.trim());
      log('POST grade_improve', Object.fromEntries(fd.entries()));
      const res  = await fetch(API,{method:'POST',body:fd});
      const text = await res.text(); let j=null; try{ j=JSON.parse(text);}catch(_){}
      if (!res.ok || !j){ err('grade_improve response', res.status, text); alert('Błąd poprawy'); return; }
      if (!j.ok){ err('grade_improve error', j); alert(j.error||'Nie udało się poprawić'); return; }
      cell.dataset.gradeId = j.grade.id;
      cell.querySelector('.view').innerHTML = j.grade.display_html;
      const avgCell = cell.closest('tr').querySelector('.avg'); if (avgCell) avgCell.innerHTML = esc(j.avg);
      log('grade_improve OK');
      return;
    }

    if (delBtn){
      const cell = closestCell(delBtn);
      log('delete clicked', !!cell);
      if (!cell || !cell.dataset.gradeId) return;
      if (!confirm('Usunąć tę ocenę?')) return;
      const fd = fdBase(); fd.append('action','grade_delete'); fd.append('grade_id', cell.dataset.gradeId);
      log('POST grade_delete', Object.fromEntries(fd.entries()));
      const res  = await fetch(API,{method:'POST',body:fd});
      const text = await res.text(); let j=null; try{ j=JSON.parse(text);}catch(_){}
      if (!res.ok || !j){ err('grade_delete response', res.status, text); alert('Błąd usuwania'); return; }
      if (!j.ok){ err('grade_delete error', j); alert(j.error||'Nie udało się usunąć'); return; }
      cell.dataset.gradeId = '';
      cell.querySelector('.view').innerHTML = '<span class="small-muted">—</span><button type="button" class="btn small edit-btn">Edytuj</button>';
      const avgCell = cell.closest('tr').querySelector('.avg'); if (avgCell) avgCell.innerHTML = esc(j.avg);
      log('grade_delete OK');
      return;
    }
  });
})();
