<?php
// admin.php — Panel administratora z DnD zapisami do klasy
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$me = current_user();
if (!$me || !in_array('admin', $me['roles'] ?? [])) {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
  if ($base === '') { $base = '.'; }
  header("Location: $base/dashboard.php");
  exit;
}

$APP_BODY_CLASS = 'app'; // jasny motyw

$errors = [];
$success = [];

/* ===== Helpers ===== */
function required($v){ return isset($v) && trim($v) !== ''; }
function getTeachers(PDO $pdo){
  return $pdo->query("
    SELECT u.id, CONCAT(u.first_name,' ',u.last_name,' (',u.login,')') AS label
    FROM users u
    JOIN user_roles ur ON ur.user_id=u.id
    JOIN roles r ON r.id=ur.role_id
    WHERE r.name='nauczyciel'
    ORDER BY u.last_name, u.first_name
   ")->fetchAll();
}
function getStudents(PDO $pdo){
  return $pdo->query("
    SELECT u.id, CONCAT(u.first_name,' ',u.last_name,' (',u.login,')') AS label
    FROM users u
    JOIN user_roles ur ON ur.user_id=u.id
    JOIN roles r ON r.id=ur.role_id
    WHERE r.name='uczeń'
    ORDER BY u.last_name, u.first_name
  ")->fetchAll();
}

/* ===== Operacje formularzowe (przedmioty/klasy/przydziały) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!verify_csrf($csrf)) {
    $errors[] = 'Nieprawidłowy token formularza.';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      switch ($action) {
        case 'add_subject':
          $name = trim($_POST['name'] ?? '');
          $short = trim($_POST['short_name'] ?? '');
          if (!required($name)) { $errors[] = 'Nazwa przedmiotu jest wymagana.'; break; }
          $stmt = $pdo->prepare("INSERT INTO subjects (name, short_name) VALUES (:n, :s)");
          $stmt->execute([':n'=>$name, ':s'=>($short !== '' ? $short : null)]);
          $success[] = 'Dodano przedmiot.';
        break;

        case 'delete_subject':
          $id = (int)($_POST['id'] ?? 0);
          if ($id <= 0) { $errors[] = 'Brak ID przedmiotu.'; break; }
          $pdo->prepare("DELETE FROM subjects WHERE id=:id")->execute([':id'=>$id]);
          $success[] = 'Usunięto przedmiot.';
        break;

        case 'add_class':
          $name = trim($_POST['name'] ?? '');
          if (!required($name)) { $errors[] = 'Nazwa klasy jest wymagana.'; break; }
          $pdo->prepare("INSERT INTO school_classes (name) VALUES (:n)")->execute([':n'=>$name]);
          $success[] = 'Dodano klasę.';
        break;

        case 'assign_teacher':
          $class_id   = (int)($_POST['class_id'] ?? 0);
          $subject_id = (int)($_POST['subject_id'] ?? 0);
          $teacher_id = (int)($_POST['teacher_id'] ?? 0);
          if ($class_id<=0 || $subject_id<=0 || $teacher_id<=0) { $errors[]='Wybierz klasę, przedmiot i nauczyciela.'; break; }
          $chk = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                                WHERE ur.user_id=:id AND r.name='nauczyciel' LIMIT 1");
          $chk->execute([':id'=>$teacher_id]);
          if (!$chk->fetch()) { $errors[]='Wybrany użytkownik nie ma roli „nauczyciel”.'; break; }
          $sel = $pdo->prepare("SELECT id FROM teacher_subjects WHERE class_id=:c AND subject_id=:s LIMIT 1");
          $sel->execute([':c'=>$class_id, ':s'=>$subject_id]);
          $row = $sel->fetch();
          if ($row) {
            $pdo->prepare("UPDATE teacher_subjects SET teacher_id=:t WHERE id=:id")
                ->execute([':t'=>$teacher_id, ':id'=>$row['id']]);
            $success[] = 'Zaktualizowano przypisanie nauczyciela.';
          } else {
            $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, class_id, subject_id) VALUES (:t,:c,:s)")
                ->execute([':t'=>$teacher_id, ':c'=>$class_id, ':s'=>$subject_id]);
            $success[] = 'Przypisano nauczyciela do przedmiotu w klasie.';
          }
        break;

        case 'delete_assignment':
          $id = (int)($_POST['id'] ?? 0);
          if ($id<=0){ $errors[]='Brak ID przypisania.'; break; }
          $pdo->prepare("DELETE FROM teacher_subjects WHERE id=:id")->execute([':id'=>$id]);
          $success[] = 'Usunięto przypisanie.';
        break;
      }
    } catch (Throwable $e) {
      $errors[] = 'Operacja nie powiodła się.';
    }
  }
}

/* ===== Dane do widoku ===== */
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
$classes  = $pdo->query("SELECT * FROM school_classes ORDER BY name")->fetchAll();
$teachers = getTeachers($pdo);

// klasa wybrana w GET
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($classes[0]['id'] ?? 0);

// uczniowie bez żadnej klasy (lista źródłowa do DnD)
$unassigned = $pdo->query("
  SELECT u.id, u.first_name, u.last_name, u.login
  FROM users u
  JOIN user_roles ur ON ur.user_id=u.id
  JOIN roles r ON r.id=ur.role_id
  LEFT JOIN enrollments e ON e.student_id=u.id
  WHERE r.name='uczeń' AND e.id IS NULL
  ORDER BY u.last_name, u.first_name
")->fetchAll();

// uczniowie zapisani do wybranej klasy
$enrollments = [];
$assignments = [];
if ($selectedClassId) {
  $stmt = $pdo->prepare("
    SELECT e.id AS enr_id, u.id AS user_id, u.first_name, u.last_name, u.login, e.enrolled_at
    FROM enrollments e
    JOIN users u ON u.id=e.student_id
    WHERE e.class_id=:c
    ORDER BY u.last_name, u.first_name
  ");
  $stmt->execute([':c'=>$selectedClassId]);
  $enrollments = $stmt->fetchAll();

  $assignmentsStmt = $pdo->prepare("
    SELECT ts.id, s.name AS subject, s.id AS subject_id,
           t.first_name, t.last_name, t.login, t.id AS teacher_id
    FROM teacher_subjects ts
    JOIN subjects s ON s.id=ts.subject_id
    JOIN users t ON t.id=ts.teacher_id
    WHERE ts.class_id=:c
    ORDER BY s.name
  ");
  $assignmentsStmt->execute([':c'=>$selectedClassId]);
  $assignments = $assignmentsStmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>
<main class="container">
  <section class="grades-card" id="admin-root"
           data-api="<?php echo sanitize($BASE_URL . '/admin_api.php'); ?>"
           data-csrf="<?php echo csrf_token(); ?>">
    <h1>Panel administratora</h1>

    <?php if (!empty($errors)): ?>
      <div class="alert"><?php foreach ($errors as $e) echo '<p>'.sanitize($e).'</p>'; ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="success"><?php foreach ($success as $m) echo '<p>'.sanitize($m).'</p>'; ?></div>
    <?php endif; ?>

    <div class="admin-tabs">
      <button class="admin-tab active" data-tab="subjects">Przedmioty</button>
      <button class="admin-tab" data-tab="classes">Klasy</button>
      <button class="admin-tab" data-tab="enrollments">Uczniowie w klasie</button>
      <button class="admin-tab" data-tab="assignments">Przydziały nauczycieli</button>
    </div>

    <!-- Przedmioty -->
    <div class="admin-panel" id="tab-subjects">
      <div class="grid two">
        <div>
          <h2>Dodaj przedmiot</h2>
          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="add_subject">
            <label for="subj_name">Nazwa</label>
            <div class="input-wrap"><input id="subj_name" name="name" required /></div>
            <label for="subj_short">Skrót (opcjonalny)</label>
            <div class="input-wrap"><input id="subj_short" name="short_name" /></div>
            <button class="btn primary" type="submit">Dodaj</button>
          </form>
        </div>
        <div>
          <h2>Lista przedmiotów</h2>
          <div class="table-wrap">
            <table class="adm-table">
              <thead><tr><th>#</th><th>Nazwa</th><th>Skrót</th><th style="width:1%"></th></tr></thead>
              <tbody>
              <?php foreach ($subjects as $s): ?>
                <tr>
                  <td><?php echo (int)$s['id']; ?></td>
                  <td><?php echo sanitize($s['name']); ?></td>
                  <td><?php echo sanitize($s['short_name'] ?? '—'); ?></td>
                  <td>
                    <form method="post" class="inline confirm-delete">
                      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                      <input type="hidden" name="action" value="delete_subject">
                      <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                      <button class="btn danger small" type="submit" title="Usuń">Usuń</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; if (!$subjects): ?>
                <tr><td colspan="4" class="muted">Brak przedmiotów</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Klasy -->
    <div class="admin-panel hidden" id="tab-classes">
      <div class="grid two">
        <div>
          <h2>Dodaj klasę</h2>
          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="add_class">
            <label for="class_name">Nazwa klasy</label>
            <div class="input-wrap"><input id="class_name" name="name" required placeholder="np. 1A" /></div>
            <button class="btn primary" type="submit">Dodaj</button>
          </form>
        </div>
        <div>
          <h2>Lista klas</h2>
          <div class="table-wrap">
            <table class="adm-table">
              <thead><tr><th>#</th><th>Nazwa</th></tr></thead>
              <tbody>
              <?php foreach ($classes as $c): ?>
                <tr><td><?php echo (int)$c['id']; ?></td><td><?php echo sanitize($c['name']); ?></td></tr>
              <?php endforeach; if (!$classes): ?>
                <tr><td colspan="2" class="muted">Brak klas</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Uczniowie w klasie (DnD) -->
    <div class="admin-panel hidden" id="tab-enrollments">
      <div class="grid two dnd-grid">
        <div>
          <h2>Uczniowie bez klasy</h2>
          <div class="input-wrap">
            <input id="filter-students" type="text" placeholder="Szukaj (imię/nazwisko)…" autocomplete="off">
          </div>

          <ul id="unassigned-list" class="students-list" aria-label="Uczniowie bez klasy">
            <?php foreach ($unassigned as $u): 
              $label = $u['last_name'].' '.$u['first_name'];
            ?>
              <li class="student-item"
                  draggable="true"
                  data-id="<?php echo (int)$u['id']; ?>"
                  data-first="<?php echo sanitize(mb_strtolower($u['first_name'])); ?>"
                  data-last="<?php echo sanitize(mb_strtolower($u['last_name'])); ?>"
                  data-search="<?php echo sanitize(mb_strtolower($u['first_name'].' '.$u['last_name'])); ?>">
                <span class="avatar-sm" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 3-9 6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1c0-3-4-6-9-6Z"/></svg>
                </span>
                <span class="primary"><?php echo sanitize($label); ?></span>
                <span class="muted small">(<?php echo sanitize($u['login']); ?>)</span>
              </li>
            <?php endforeach; if (!$unassigned): ?>
              <li class="muted pad">Brak uczniów do przypisania.</li>
            <?php endif; ?>
          </ul>
        </div>

        <div>
          <form method="get" class="form autosubmit">
            <label for="class_sel">Klasa</label>
            <div class="input-wrap">
              <select id="class_sel" name="class_id">
                <?php foreach ($classes as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo $selectedClassId===$c['id']?'selected':''; ?>>
                    <?php echo sanitize($c['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>

          <h2 class="mt">Uczniowie w klasie</h2>
          <div id="dropzone" class="dropzone" data-class-id="<?php echo (int)$selectedClassId; ?>">
            <p class="hint">Przeciągnij ucznia z lewej listy tutaj, aby dodać do klasy.</p>
            <ul id="roster-list" class="roster-list">
              <?php foreach ($enrollments as $e): 
                $label = $e['last_name'].' '.$e['first_name'];
              ?>
                <li class="roster-item"
                    data-enr-id="<?php echo (int)$e['enr_id']; ?>"
                    data-id="<?php echo (int)$e['user_id']; ?>"
                    data-first="<?php echo sanitize(mb_strtolower($e['first_name'])); ?>"
                    data-last="<?php echo sanitize(mb_strtolower($e['last_name'])); ?>">
                  <span class="avatar-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 3-9 6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1c0-3-4-6-9-6Z"/></svg>
                  </span>
                  <span class="primary"><?php echo sanitize($label); ?></span>
                  <span class="muted small">(<?php echo sanitize($e['login']); ?>)</span>
                  <button class="icon-btn remove-enr" type="button" title="Usuń z klasy" data-enr-id="<?php echo (int)$e['enr_id']; ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 3h6a1 1 0 0 1 1 1v1h4v2H4V5h4V4a1 1 0 0 1 1-1Zm-3 6h12l-1 11a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 9Z"/></svg>
                  </button>
                </li>
              <?php endforeach; if (!$enrollments): ?>
                <li class="muted pad">Brak uczniów w tej klasie.</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Przydziały nauczycieli -->
    <div class="admin-panel hidden" id="tab-assignments">
      <div class="grid two">
        <div>
          <h2>Wybierz klasę</h2>
          <form method="get" class="form autosubmit">
            <label for="class_sel2">Klasa</label>
            <div class="input-wrap">
              <select id="class_sel2" name="class_id">
                <?php foreach ($classes as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo $selectedClassId===$c['id']?'selected':''; ?>>
                    <?php echo sanitize($c['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>

          <h2 class="mt">Przypisz nauczyciela</h2>
          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="assign_teacher">
            <input type="hidden" name="class_id" value="<?php echo (int)$selectedClassId; ?>">

            <label for="assign_subj">Przedmiot</label>
            <div class="input-wrap">
              <select id="assign_subj" name="subject_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($subjects as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo sanitize($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <label for="assign_teacher">Nauczyciel</label>
            <div class="input-wrap">
              <select id="assign_teacher" name="teacher_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>"><?php echo sanitize($t['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button class="btn primary" type="submit">Zapisz przypisanie</button>
          </form>
        </div>

        <div>
          <h2>Przydziały w klasie</h2>
          <div class="table-wrap">
            <table class="adm-table">
              <thead><tr><th>#</th><th>Przedmiot</th><th>Nauczyciel</th><th style="width:1%"></th></tr></thead>
              <tbody>
                <?php foreach ($assignments as $a): ?>
                  <tr>
                    <td><?php echo (int)$a['id']; ?></td>
                    <td><?php echo sanitize($a['subject']); ?></td>
                    <td><?php echo sanitize($a['last_name'].' '.$a['first_name'].' ('.$a['login'].')'); ?></td>
                    <td>
                      <form method="post" class="inline confirm-delete">
                        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_assignment">
                        <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                        <button class="btn danger small" type="submit">Usuń</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; if (!$assignments): ?>
                  <tr><td colspan="4" class="muted">Brak przydziałów w tej klasie</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </section>
</main>

<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/admin.css">
<script src="<?php echo $BASE_URL; ?>/assets/js/admin.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
