<?php
// teacher_add_grade.php – nowe okno do wystawiania ocen
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$me = current_user();
if (!$me || !in_array('nauczyciel', $me['roles'] ?? [])) {
    http_response_code(403);
    echo "Brak uprawnień.";
    exit;
}

$APP_BODY_CLASS = 'app';
$teacherId = (int)$me['id'];

// Pobierz parametry z URL
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$termId = !empty($_GET['term_id']) ? (int)$_GET['term_id'] : null;

// Pobierz uczniów z wybranej klasy do listy <select>
$students = [];
if ($classId) {
    $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name
                         FROM enrollments e JOIN users u ON u.id=e.student_id
                         WHERE e.class_id=:c ORDER BY u.last_name, u.first_name");
    $st->execute([':c' => $classId]);
    $students = $st->fetchAll();
}
$cats = $pdo->query("SELECT id, name FROM grade_categories ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/teacher.css">
<main class="container">
    <div class="t-card" id="add-grade-root"
         data-api="<?php echo $BASE_URL; ?>/teacher_api.php"
         data-csrf="<?php echo csrf_token(); ?>"
         data-class-id="<?php echo $classId; ?>"
         data-subject-id="<?php echo $subjectId; ?>"
         data-term-id="<?php echo $termId ?? ''; ?>">
        <h1>Wystawianie ocen</h1>
        <div id="flash-container"></div>

        <form id="form-add-column" class="form">
            <h2>Dodaj kolumnę ocen (np. Sprawdzian, Kartkówka)</h2>
            <div class="grid two">
                <div class="input-wrap">
                    <label>Tytuł kolumny *</label>
                    <input name="title" required placeholder="np. Sprawdzian - Dział 1">
                </div>
                <div class="input-wrap">
                    <label>Kategoria</label>
                    <select name="category_id">
                        <option value="">— inna —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-wrap">
                    <label>Data</label>
                    <input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="input-wrap">
                    <label>Waga</label>
                    <input type="number" name="weight" step="0.5" min="0.5" value="1.0">
                </div>
            </div>
            <button type="submit" class="btn">Dodaj kolumnę i zamknij</button>
        </form>

        <div class="t-sep"></div>

        <form id="form-add-single" class="form">
            <h2>Wystaw pojedynczą ocenę (np. za aktywność)</h2>
            <div class="grid three">
                <div class="input-wrap">
                    <label>Uczeń *</label>
                    <select name="student_id" required>
                        <option value="">— wybierz ucznia —</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo sanitize($s['last_name'] . ' ' . $s['first_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-wrap">
                    <label>Ocena *</label>
                    <input name="value_text" required placeholder="np. 5, 4+, np">
                </div>
                <div class="input-wrap">
                    <label>Tytuł/opis *</label>
                    <input name="title" required placeholder="np. Aktywność na lekcji">
                </div>
                 <div class="input-wrap">
                    <label>Kategoria</label>
                    <select name="category_id">
                        <option value="">— inna —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                 <div class="input-wrap">
                    <label>Waga</label>
                    <input type="number" name="weight" step="0.5" min="0.5" value="1.0">
                </div>
                <div class="input-wrap">
                    <label>Komentarz (opcjonalnie)</label>
                    <input name="comment" placeholder="Dodatkowe informacje">
                </div>
            </div>
            <button type="submit" class="btn">Dodaj ocenę i zamknij</button>
        </form>

        <div class="t-sep"></div>
        
        <div class="form-actions">
            <button id="btn-cancel" class="btn danger">Anuluj i zamknij</button>
        </div>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('add-grade-root');
    const API = root.dataset.api;
    const CSRF = root.dataset.csrf;
    const CLASS_ID = root.dataset.classId;
    const SUBJECT_ID = root.dataset.subjectId;
    const TERM_ID = root.dataset.termId;

    const showFlash = (type, message) => {
        const container = document.getElementById('flash-container');
        container.innerHTML = `<div class="${type === 'success' ? 'success' : 'alert'}">${message}</div>`;
    };

    async function postData(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', CSRF);
        fd.append('class_id', CLASS_ID);
        fd.append('subject_id', SUBJECT_ID);
        if (TERM_ID) fd.append('term_id', TERM_ID);
        
        for (const key in data) {
            fd.append(key, data[key]);
        }

        const response = await fetch(API, { method: 'POST', body: fd });
        if (!response.ok) throw new Error('Błąd serwera: ' + response.statusText);
        
        const json = await response.json();
        if (!json.ok) throw new Error(json.error || 'Wystąpił nieznany błąd.');
        
        return json;
    }

    document.getElementById('form-add-column').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        try {
            await postData('ass_add_column', data);
            window.opener.location.reload();
            window.close();
        } catch (err) {
            showFlash('alert', err.message);
        }
    });

    document.getElementById('form-add-single').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        
        try {
            await postData('grade_add_single', data);
            window.opener.location.reload();
            window.close();
        } catch (err) {
            showFlash('alert', err.message);
        }
    });

    document.getElementById('btn-cancel').addEventListener('click', () => {
        window.close();
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>