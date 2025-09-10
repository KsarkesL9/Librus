// assets/js/teacher.js - WERSJA DIAGNOSTYCZNA Z ALERTAMI
(function () {
    const root = document.getElementById('teacher-root');
    if (!root) return;

    const openPopup = (url, isLarge = false) => {
        const width = isLarge ? 900 : 600;
        const height = isLarge ? 800 : 450;
        const features = `width=${width},height=${height},scrollbars=yes,resizable=yes`;
        
        // --- KROK 1: Wyświetl alert z adresem URL ---
        alert("DEBUG: Otwieram okno z adresem:\n" + url);
        
        window.open(url, 'gradeEditWindow', features);
    };

    document.getElementById('add-grade-btn').addEventListener('click', () => {
        const baseUrl = root.dataset.baseUrl;
        const classId = root.dataset.classId;
        const subjectId = root.dataset.subjectId;
        const termId = root.dataset.termId;
        if (!classId || !subjectId) {
            alert('Proszę najpierw wybrać klasę i przedmiot.');
            return;
        }
        const url = `${baseUrl}/teacher_add_grade.php?class_id=${classId}&subject_id=${subjectId}&term_id=${termId}`;
        openPopup(url, true);
    });

    root.querySelector('.grd-wrap').addEventListener('click', (e) => {
        const target = e.target;
        const baseUrl = root.dataset.baseUrl;
        
        const studentId = target.dataset.studentId;
        const assessmentId = target.dataset.assessmentId;
        const gradeId = target.dataset.gradeId;
        let url;

        if (studentId && assessmentId) {
            if (gradeId) {
                // Edycja istniejącej oceny
                url = `${baseUrl}/teacher_edit_grade.php?grade_id=${gradeId}&student_id=${studentId}&assessment_id=${assessmentId}`;
            } else {
                // Dodawanie nowej oceny
                url = `${baseUrl}/teacher_edit_grade.php?student_id=${studentId}&assessment_id=${assessmentId}`;
            }
            openPopup(url);
        }
    });

})();