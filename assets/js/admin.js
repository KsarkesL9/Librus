// assets/js/admin.js — Tabs + DnD zapisów do klasy + filtr

(function(){
  /* --- Zakładki --- */
  const tabBtns = Array.from(document.querySelectorAll('.admin-tab'));
  const panels  = Array.from(document.querySelectorAll('.admin-panel'));
  function activate(tab){
    tabBtns.forEach(b => b.classList.toggle('active', b === tab));
    const id = tab.getAttribute('data-tab');
    panels.forEach(p => p.classList.toggle('hidden', p.id !== 'tab-'+id));
    history.replaceState(null, '', location.pathname + location.search + '#' + id);
  }
  const start = tabBtns.find(b => '#'+b.dataset.tab === location.hash) || tabBtns[0];
  if (start) activate(start);
  tabBtns.forEach(btn => btn.addEventListener('click', () => activate(btn)));

  /* --- Potwierdzanie delete w klasycznych formach --- */
  document.addEventListener('submit', function(e){
    const form = e.target.closest('.confirm-delete');
    if (!form) return;
    if (!confirm('Na pewno usunąć?')) e.preventDefault();
  });

  /* --- Autosubmit wyboru klasy --- */
  document.querySelectorAll('form.autosubmit select').forEach(sel => {
    sel.addEventListener('change', e => e.target.form.submit());
  });

  /* --- DnD zapisów --- */
  const root      = document.getElementById('admin-root');
  const API       = root ? root.dataset.api : null;
  const CSRF      = root ? root.dataset.csrf : null;
  const unassigned= document.getElementById('unassigned-list');
  const roster    = document.getElementById('roster-list');
  const dropzone  = document.getElementById('dropzone');
  const filterInp = document.getElementById('filter-students');

  if (unassigned && roster && dropzone && API) {
    // Drag start on student from left list
    unassigned.addEventListener('dragstart', (e) => {
      const li = e.target.closest('.student-item');
      if (!li) return;
      e.dataTransfer.setData('text/plain', li.dataset.id);
      e.dataTransfer.effectAllowed = 'copy';
      li.classList.add('dragging');
    });
    unassigned.addEventListener('dragend', (e) => {
      const li = e.target.closest('.student-item');
      if (li) li.classList.remove('dragging');
    });

    // Dropzone (class roster)
    ['dragover','dragenter'].forEach(ev => dropzone.addEventListener(ev, (e)=>{
      e.preventDefault(); dropzone.classList.add('dragover'); e.dataTransfer.dropEffect='copy';
    }));
    ;['dragleave','dragend','drop'].forEach(ev => dropzone.addEventListener(ev, ()=>dropzone.classList.remove('dragover')));

    dropzone.addEventListener('drop', async (e) => {
      e.preventDefault();
      const studentId = parseInt(e.dataTransfer.getData('text/plain') || '0', 10);
      const classId = parseInt(dropzone.dataset.classId || '0', 10);
      if (!studentId || !classId) return;

      const li = unassigned.querySelector(`.student-item[data-id="${studentId}"]`);
      if (!li) return;

      // AJAX enroll
      const fd = new FormData();
      fd.append('action','enroll'); fd.append('csrf', CSRF);
      fd.append('student_id', String(studentId));
      fd.append('class_id', String(classId));

      const res = await fetch(API, { method:'POST', body: fd });
      const j = await res.json().catch(()=>({ok:false,error:'Błąd sieci'}));
      if (!j.ok) { alert(j.error || 'Nie udało się dodać.'); return; }

      // Dodaj do roster
      const s = j.enrollment;
      const item = document.createElement('li');
      item.className = 'roster-item';
      item.dataset.enrId = String(s.enr_id);
      item.dataset.id = String(s.student_id);
      item.dataset.first = (s.first_name || '').toLowerCase();
      item.dataset.last  = (s.last_name || '').toLowerCase();
      item.innerHTML = `
        <span class="avatar-sm" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 3-9 6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1c0-3-4-6-9-6Z"/></svg>
        </span>
        <span class="primary">${escapeHTML(s.last_name+' '+s.first_name)}</span>
        <span class="muted small">(${escapeHTML(s.login)})</span>
        <button class="icon-btn remove-enr" type="button" title="Usuń z klasy" data-enr-id="${s.enr_id}">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 3h6a1 1 0 0 1 1 1v1h4v2H4V5h4V4a1 1 0 0 1 1-1Zm-3 6h12l-1 11a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 9Z"/></svg>
        </button>`;
      roster.appendChild(item);
      sortList(roster);

      // Usuń z lewego panelu
      li.remove();
      // Jeśli lewy panel pusty – dodaj komunikat
      if (!unassigned.querySelector('.student-item')) {
        const msg = document.createElement('li');
        msg.className = 'muted pad'; msg.textContent = 'Brak uczniów do przypisania.';
        unassigned.appendChild(msg);
      }
    });

    // Remove from class (click trash)
    roster.addEventListener('click', async (e) => {
      const btn = e.target.closest('.remove-enr');
      if (!btn) return;
      const enrId = parseInt(btn.dataset.enrId || '0', 10);
      if (!enrId) return;
      if (!confirm('Usunąć ucznia z klasy?')) return;

      const fd = new FormData();
      fd.append('action','unenroll'); fd.append('csrf', CSRF);
      fd.append('enr_id', String(enrId));
      const res = await fetch(API, { method:'POST', body: fd });
      const j = await res.json().catch(()=>({ok:false,error:'Błąd sieci'}));
      if (!j.ok) { alert(j.error || 'Nie udało się usunąć.'); return; }

      // Usuń z roster
      const li = btn.closest('.roster-item');
      const parentEmptyBefore = !unassigned.querySelector('.student-item');
      li.remove();

      // Dodaj z powrotem na listę „bez klasy”
      const st = j.student;
      // usuń ewentualny komunikat „brak…”
      if (parentEmptyBefore) unassigned.querySelector('.muted.pad')?.remove();
      const el = document.createElement('li');
      el.className = 'student-item';
      el.draggable = true;
      el.dataset.id = String(st.id);
      el.dataset.first = (st.first_name || '').toLowerCase();
      el.dataset.last  = (st.last_name || '').toLowerCase();
      el.dataset.search= (st.first_name+' '+st.last_name).toLowerCase();
      el.innerHTML = `
        <span class="avatar-sm" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 3-9 6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1c0-3-4-6-9-6Z"/></svg>
        </span>
        <span class="primary">${escapeHTML(st.last_name+' '+st.first_name)}</span>
        <span class="muted small">(${escapeHTML(st.login)})</span>`;
      unassigned.appendChild(el);
      sortList(unassigned);
    });

    // Filtr (min 0-2 litery – filtruje natychmiast)
    filterInp?.addEventListener('input', () => {
      const q = deburr((filterInp.value || '').toLowerCase().trim());
      const items = Array.from(unassigned.querySelectorAll('.student-item'));
      items.forEach(li => {
        const t = deburr(li.dataset.search || '');
        li.style.display = t.includes(q) ? '' : 'none';
      });
    });
  }

  function sortList(ul){
    const items = Array.from(ul.children).filter(li => li.matches('.student-item, .roster-item'));
    items.sort((a,b)=>{
      const la = (a.dataset.last || ''), lb=(b.dataset.last || '');
      if (la < lb) return -1; if (la > lb) return 1;
      const fa = (a.dataset.first || ''), fb=(b.dataset.first || '');
      if (fa < fb) return -1; if (fa > fb) return 1;
      return 0;
    });
    items.forEach(li => ul.appendChild(li));
  }
  function escapeHTML(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
  function deburr(s){ return s.normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
})();
