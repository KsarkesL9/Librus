// assets/js/teacher.js - WERSJA Z OBSŁUGĄ EDYCJI I USUWANIA KOLUMN
(function () {
    const root = document.getElementById('teacher-root');
    if (!root) return;

    const BASE_URL = root.dataset.baseUrl;
    const API_URL = root.dataset.apiUrl;
    const CSRF_TOKEN = root.dataset.csrfToken;

    const openPopup = (url, isLarge = false) => {
        const width = isLarge ? 900 : 600;
        const height = isLarge ? 800 : 500;
        const features = `width=${width},height=${height},scrollbars=yes,resizable=yes`;
        
        window.open(url, 'gradePopupWindow', features);
    };

    // Otwórz okno "Wystaw / Dodaj kolumnę"
    document.getElementById('add-grade-btn').addEventListener('click', () => {
        const classId = root.dataset.classId;
        const subjectId = root.dataset.subjectId;
        const termId = root.dataset.termId;
        if (!classId || !subjectId) {
            alert('Proszę najpierw wybrać klasę i przedmiot.');
            return;
        }
        const url = `${BASE_URL}/teacher_add_grade.php?class_id=${classId}&subject_id=${subjectId}&term_id=${termId}`;
        openPopup(url, true);
    });

    // Delegacja zdarzeń dla całej siatki ocen
    root.querySelector('.grd-wrap').addEventListener('click', (e) => {
        const target = e.target;
        
        // --- Operacje na ocenach (w komórkach) ---
        const studentId = target.dataset.studentId;
        const assessmentIdForGrade = target.dataset.assessmentId;
        const gradeId = target.dataset.gradeId;

        if (studentId && assessmentIdForGrade) {
            let url;
            if (gradeId) {
                // Edycja istniejącej oceny
                url = `${BASE_URL}/teacher_edit_grade.php?grade_id=${gradeId}&student_id=${studentId}&assessment_id=${assessmentIdForGrade}`;
            } else {
                // Dodawanie nowej oceny
                url = `${BASE_URL}/teacher_edit_grade.php?student_id=${studentId}&assessment_id=${assessmentIdForGrade}`;
            }
            openPopup(url);
            return;
        }

        // --- Operacje na kolumnach (w nagłówku) ---
        const editBtn = target.closest('.edit-col');
        if (editBtn) {
            const assessmentId = editBtn.dataset.assessmentId;
            const url = `${BASE_URL}/teacher_edit_column.php?assessment_id=${assessmentId}`;
            openPopup(url);
            return;
        }

        const deleteBtn = target.closest('.delete-col');
        if (deleteBtn) {
            const assessmentId = deleteBtn.dataset.assessmentId;
            const confirmation = confirm('Czy na pewno chcesz usunąć tę kolumnę? Spowoduje to nieodwracalne usunięcie WSZYSTKICH ocen w tej kolumnie.');
            
            if (confirmation) {
                deleteColumn(assessmentId);
            }
            return;
        }
    });

    async function deleteColumn(assessmentId) {
        const fd = new FormData();
        fd.append('action', 'ass_delete_column');
        fd.append('csrf', CSRF_TOKEN);
        fd.append('assessment_id', assessmentId);

        try {
            const response = await fetch(API_URL, { method: 'POST', body: fd });
            const json = await response.json();
            if (!response.ok || !json.ok) {
                throw new Error(json.error || 'Wystąpił nieznany błąd.');
            }
            window.location.reload();
        } catch (err) {
            alert('Błąd podczas usuwania kolumny: ' + err.message);
        }
    }

})();