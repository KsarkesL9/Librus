<?php
// teacher_edit_column.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$me = current_user();
if (!$me || !in_array('nauczyciel', $me['roles'] ?? [])) {
    http_response_code(403);
    exit("Brak uprawnień.");
}

$APP_BODY_CLASS = 'app';
$teacherId = (int)$me['id'];

$assessmentId = (int)($_GET['assessment_id'] ?? 0);

if (!$assessmentId) {
    include __DIR__ . '/includes/header.php';
    echo '<main class="container"><div class="alert">Błąd: Brak ID kolumny do edycji.</div></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM assessments WHERE id = :id AND teacher_id = :tid");
$stmt->execute([':id' => $assessmentId, ':tid' => $teacherId]);
$ass = $stmt->fetch();

if (!$ass) {
    include __DIR__ . '/includes/header.php';
    echo '<main class="container"><div class="alert">Błąd: Nie znaleziono kolumny lub brak uprawnień.</div></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$cats = $pdo->query("SELECT id, name FROM grade_categories ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/teacher.css">
<main class="container">
    <div class="t-card" id="edit-column-root"
         data-api="<?php echo $BASE_URL; ?>/teacher_api.php"
         data-csrf="<?php echo csrf_token(); ?>"
         data-assessment-id="<?php echo $assessmentId; ?>">
        <h1>Edytuj kolumnę ocen</h1>
        <div id="flash-container"></div>

        <form id="form-edit-column" class="form">
            <div class="grid two">
                <div class="input-wrap">
                    <label>Tytuł kolumny *</label>
                    <input name="title" required value="<?php echo sanitize($ass['title']); ?>">
                </div>
                <div class="input-wrap">
                    <label>Kategoria</label>
                    <select name="category_id">
                        <option value="">— inna —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ($ass['category_id'] == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-wrap">
                    <label>Data</label>
                    <input type="date" name="issue_date" value="<?php echo sanitize($ass['issue_date']); ?>">
                </div>
                <div class="input-wrap">
                    <label>Waga</label>
                    <input type="number" name="weight" step="0.5" min="0.5" value="<?php echo sanitize($ass['weight']); ?>">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn primary">Zapisz i zamknij</button>
                <button type="button" id="btn-cancel" class="btn">Anuluj</button>
            </div>
        </form>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('edit-column-root');
    const API = root.dataset.api;
    const CSRF = root.dataset.csrf;
    const ASSESSMENT_ID = root.dataset.assessmentId;

    const showFlash = (type, message) => {
        const container = document.getElementById('flash-container');
        container.innerHTML = `<div class="${type === 'success' ? 'success' : 'alert'}">${message}</div>`;
    };

    document.getElementById('form-edit-column').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        const fd = new FormData();
        fd.append('action', 'ass_update_column');
        fd.append('csrf', CSRF);
        fd.append('assessment_id', ASSESSMENT_ID);
        for (const key in data) {
            fd.append(key, data[key]);
        }

        try {
            const response = await fetch(API, { method: 'POST', body: fd });
            const json = await response.json();

            if (!response.ok || !json.ok) {
                throw new Error(json.error || 'Wystąpił nieznany błąd.');
            }
            
            if (window.opener) {
                window.opener.location.reload();
            }
            window.close();
        } catch (err) {
            showFlash('alert', err.message);
        }
    });

    document.getElementById('btn-cancel').addEventListener('click', () => window.close());
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>