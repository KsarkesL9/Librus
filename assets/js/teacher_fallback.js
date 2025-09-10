// assets/js/teacher_fallback.js — v1 (fallback bez inline, z debugami)
(function () {
  const tag = (lvl, ...a) => console[lvl].apply(console, ['%c[tfb]', 'color:#059669;font-weight:700', ...a]);
  const log = (...a)=>tag('log', ...a), warn=(...a)=>tag('warn', ...a), err=(...a)=>tag('error', ...a);

  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('teacher-root');
    if (!root) { err('root #teacher-root NOT FOUND — fallback inactive'); return; }
    const API  = root.getAttribute('data-api')  || '';
    const CSRF = root.getAttribute('data-csrf') || '';
    log('boot ok', { api: API, csrfLen: CSRF.length });

    function cCell(el){ return el && el.closest ? el.closest('.cell') : null; }
    function fdBase(){ const fd=new FormData(); fd.append('csrf', CSRF); return fd; }
    function esc(s){ return (s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

    // Delegacja klików w całym module
    root.addEventListener('click', async (e) => {
      const t = e.target;

      // 1) Otwórz edycję
      if (t.closest('.edit-btn')) {
        const cell = cCell(t); log('edit click', !!cell);
        if (!cell) return;
        root.querySelectorAll('.cell.editing').forEach(x=>x.classList.remove('editing'));
        cell.classList.add('editing');
        const inp = cell.querySelector('.input-grade'); if (inp){ inp.focus(); try{ inp.select(); }catch(_){} }
        return;
      }

      // 2) Anuluj edycję
      if (t.closest('.cancel-btn')) {
        const cell = cCell(t); log('cancel click', !!cell);
        if (cell) cell.classList.remove('editing');
        return;
      }

      // 3) Ustaw szybkie wartości
      const qg = t.closest('.qg button[data-val]');
      if (qg) {
        const cell = cCell(qg); const inp = cell?.querySelector('.input-grade');
        if (inp) { inp.value = qg.getAttribute('data-val') || ''; inp.focus(); }
        log('quick set', inp ? inp.value : null);
        return;
      }

      // 4) Zapisz ocenę
      if (t.closest('.save-btn')) {
        const cell = cCell(t);
        if (!cell) { err('save: no cell'); return; }
        if (!API){ alert('Brak endpointu API'); return; }

        const g   = cell.querySelector('.input-grade');
        const com = cell.querySelector('.input-comment');

        const fd  = fdBase();
        fd.append('action','grade_set');
        fd.append('assessment_id', cell.getAttribute('data-assessment-id') || '');
        fd.append('student_id',    cell.getAttribute('data-student-id')    || '');
        fd.append('grade_id',      cell.getAttribute('data-grade-id')      || '');
        fd.append('value_text',    (g   ? g.value.trim()   : ''));
        fd.append('comment',       (com ? com.value.trim() : ''));

        log('POST grade_set', Object.fromEntries(fd.entries()));

        try{
          const res = await fetch(API, { method:'POST', body:fd });
          const txt = await res.text();
          let j = null; try { j = JSON.parse(txt); } catch(_){}
          log('resp grade_set', res.status, j || txt);
          if (!res.ok || !j || !j.ok) { alert((j && j.error) || 'Błąd zapisu'); return; }

          // Aktualizacja komórki i średniej
          cell.setAttribute('data-grade-id', j.grade.id);
          const view = cell.querySelector('.view'); if (view) view.innerHTML = j.grade.display_html;
          const avg  = cell.closest('tr').querySelector('.avg'); if (avg) avg.innerHTML = esc(j.avg);
          cell.classList.remove('editing');
        } catch(ex) {
          err('save exception', ex);
          alert('Błąd sieci');
        }
        return;
      }

      // 5) Poprawa oceny
      if (t.closest('.impr-btn')) {
        const cell = cCell(t);
        if (!cell) return;
        const oldId = cell.getAttribute('data-grade-id');
        if (!oldId){ warn('improve: no grade_id'); alert('Brak oceny do poprawy.'); return; }
        const val = prompt('Nowa ocena (np. 3+, 5-, +, -):'); if (!val) return;

        const fd = fdBase();
        fd.append('action','grade_improve');
        fd.append('grade_id', oldId);
        fd.append('value_text', val.trim());

        log('POST grade_improve', Object.fromEntries(fd.entries()));
        try{
          const res = await fetch(API, { method:'POST', body:fd });
          const txt = await res.text();
          let j=null; try { j=JSON.parse(txt);}catch(_){}
          log('resp grade_improve', res.status, j || txt);
          if (!res.ok || !j || !j.ok) { alert((j && j.error) || 'Błąd poprawy'); return; }

          cell.setAttribute('data-grade-id', j.grade.id);
          const view = cell.querySelector('.view'); if (view) view.innerHTML = j.grade.display_html;
          const avg  = cell.closest('tr').querySelector('.avg'); if (avg) avg.innerHTML = esc(j.avg);
        } catch(ex) {
          err('improve exception', ex); alert('Błąd sieci');
        }
        return;
      }

      // 6) Usuwanie oceny
      if (t.closest('.del-btn')) {
        const cell = cCell(t);
        if (!cell) return;
        const gid = cell.getAttribute('data-grade-id');
        if (!gid) return;
        if (!confirm('Usunąć tę ocenę?')) return;

        const fd = fdBase();
        fd.append('action','grade_delete');
        fd.append('grade_id', gid);

        log('POST grade_delete', Object.fromEntries(fd.entries()));
        try{
          const res = await fetch(API, { method:'POST', body:fd });
          const txt = await res.text();
          let j=null; try { j=JSON.parse(txt);}catch(_){}
          log('resp grade_delete', res.status, j || txt);
          if (!res.ok || !j || !j.ok) { alert((j && j.error) || 'Błąd usuwania'); return; }

          cell.setAttribute('data-grade-id', '');
          const view = cell.querySelector('.view');
          if (view) view.innerHTML = '<span class="small-muted">—</span><button type="button" class="btn small edit-btn">Edytuj</button>';
          const avg = cell.closest('tr').querySelector('.avg'); if (avg) avg.innerHTML = esc(j.avg);
        } catch(ex) {
          err('delete exception', ex); alert('Błąd sieci');
        }
        return;
      }
    });
  });
})();
