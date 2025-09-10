<?php
// teacher_edit_grade.php – nowe okno z walidacją parametrów
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$me = current_user();
if (!$me || !in_array('nauczyciel', $me['roles'] ?? [])) {
    http_response_code(403); exit("Brak uprawnień.");
}

$APP_BODY_CLASS = 'app';
$teacherId = (int)$me['id'];

$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$studentId = (int)($_GET['student_id'] ?? 0);
$gradeId = (int)($_GET['grade_id'] ?? 0);

if (empty($assessmentId) || empty($studentId)) {
    include __DIR__ . '/includes/header.php';
    echo '<main class="container"><div class="alert">Błąd: Brak wystarczających danych do wystawienia oceny. Proszę zamknąć to okno i spróbować ponownie.</div></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$grade = null;
if ($gradeId) {
    $stmt = $pdo->prepare("SELECT * FROM grades WHERE id = :gid AND teacher_id = :tid");
    $stmt->execute([':gid' => $gradeId, ':tid' => $teacherId]);
    $grade = $stmt->fetch();
}

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/teacher.css">
<main class="container">
    <div class="t-card" id="edit-grade-root"
         data-api="<?php echo $BASE_URL; ?>/teacher_api.php"
         data-csrf="<?php echo csrf_token(); ?>"
         data-assessment-id="<?php echo $assessmentId; ?>"
         data-student-id="<?php echo $studentId; ?>"
         data-grade-id="<?php echo $gradeId; ?>">
        <h1><?php echo $gradeId ? 'Edytuj ocenę' : 'Dodaj nową ocenę'; ?></h1>
        <div id="flash-container"></div>

        <form id="form-edit-grade" class="form">
            <div class="input-wrap">
                <label>Ocena *</label>
                <input name="value_text" required value="<?php echo sanitize($grade['value_text'] ?? ''); ?>" placeholder="np. 5, 4+, np">
            </div>
            <div class="input-wrap">
                <label>Komentarz (opcjonalnie)</label>
                <input name="comment" value="<?php echo sanitize($grade['comment'] ?? ''); ?>" placeholder="Dodatkowe informacje">
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn primary">Zapisz i zamknij</button>
                <?php if ($gradeId): ?>
                    <button type="button" id="btn-delete" class="btn danger">Usuń ocenę</button>
                <?php endif; ?>
                <button type="button" id="btn-cancel" class="btn">Anuluj</button>
            </div>
        </form>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('edit-grade-root');
    const API = root.dataset.api;
    const CSRF = root.dataset.csrf;
    const ASSESSMENT_ID = root.dataset.assessmentId;
    const STUDENT_ID = root.dataset.studentId;
    const GRADE_ID = root.dataset.gradeId;

    const showFlash = (type, message) => {
        const container = document.getElementById('flash-container');
        container.innerHTML = `<div class="${type === 'success' ? 'success' : 'alert'}">${message}</div>`;
    };

    async function postData(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', CSRF);
        fd.append('assessment_id', ASSESSMENT_ID);
        fd.append('student_id', STUDENT_ID);
        if (GRADE_ID && GRADE_ID !== '0') {
            fd.append('grade_id', GRADE_ID);
        }
        
        for (const key in data) fd.append(key, data[key]);

        const response = await fetch(API, { method: 'POST', body: fd });
        const json = await response.json();
        if (!response.ok || !json.ok) throw new Error(json.error || 'Wystąpił nieznany błąd.');
        return json;
    }

    document.getElementById('form-edit-grade').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        const isUpdate = GRADE_ID && GRADE_ID !== '0';
        const action = isUpdate ? 'grade_update' : 'grade_add_or_improve';

        try {
            await postData(action, data);
            if (window.opener) { window.opener.location.reload(); }
            window.close();
        } catch (err) {
            showFlash('alert', err.message);
        }
    });

    const deleteBtn = document.getElementById('btn-delete');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const confirmationMessage = 'Czy na pewno chcesz usunąć tę ocenę?\nSpowoduje to usunięcie również oceny oryginalnej lub poprawionej powiązanej z tą kolumną.';
            if (confirm(confirmationMessage)) {
                try {
                    await postData('grade_delete', {});
                    if (window.opener) { window.opener.location.reload(); }
                    window.close();
                } catch (err) {
                    showFlash('alert', err.message);
                }
            }
        });
    }

    document.getElementById('btn-cancel').addEventListener('click', () => window.close());
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>