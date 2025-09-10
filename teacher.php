<?php
// teacher.php – moduł wystawiania/edycji ocen
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$me = current_user();
if (!$me || !in_array('nauczyciel', $me['roles'] ?? [])) {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
  if ($base === '') { $base = '.'; }
  header("Location: $base/dashboard.php");
  exit;
}

$APP_BODY_CLASS = 'app'; // jasny motyw
$teacherId = (int)$me['id'];

function is_teacher_of(PDO $pdo, int $teacherId, int $classId, int $subjectId): bool {
  $s = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id=:t AND class_id=:c AND subject_id=:s LIMIT 1");
  $s->execute([':t'=>$teacherId, ':c'=>$classId, ':s'=>$subjectId]);
  return (bool)$s->fetchColumn();
}

$flash = $_GET['msg'] ?? '';

/* ===== Fallback: dodanie kolumny przez zwykły POST (gdy JS nie działa) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ass_add_fallback'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $qs = http_build_query(['msg' => 'csrf']);
    header('Location: '.$_SERVER['PHP_SELF'].'?'.$qs);
    exit;
  }
  $class_id   = (int)($_POST['class_id'] ?? 0);
  $subject_id = (int)($_POST['subject_id'] ?? 0);
  $term_id    = ($_POST['term_id'] ?? '') !== '' ? (int)$_POST['term_id'] : null;
  $title      = trim($_POST['title'] ?? '');
  $category_id= ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
  $weight     = max(0.01, (float)($_POST['weight'] ?? 1));
  $counts     = ($_POST['counts_to_avg'] ?? '1') === '1' ? 1 : 0;
  $issue_date = $_POST['issue_date'] ?: date('Y-m-d');
  $color      = $_POST['color'] ?: null;

  $qsReload   = http_build_query([
    'class_id'=>$class_id, 'subject_id'=>$subject_id,
    'term_id'=>$term_id ?? '', 'msg' => 'added'
  ]);

  if (!$class_id || !$subject_id || $title==='') {
    header('Location: '.$_SERVER['PHP_SELF'].'?'.$qsReload.'&msg=invalid'); exit;
  }
  if (!is_teacher_of($pdo, $teacherId, $class_id, $subject_id)) {
    header('Location: '.$_SERVER['PHP_SELF'].'?'.$qsReload.'&msg=forbidden'); exit;
  }

  $stmt = $pdo->prepare("INSERT INTO assessments
      (teacher_id,class_id,subject_id,term_id,title,category_id,weight,counts_to_avg,color,issue_date)
      VALUES (:t,:c,:s,:term,:title,:cat,:w,:cnt,:col,:dt)");
  $stmt->execute([
    ':t'=>$teacherId, ':c'=>$class_id, ':s'=>$subject_id, ':term'=>$term_id,
    ':title'=>$title, ':cat'=>$category_id ?: null, ':w'=>$weight, ':cnt'=>$counts,
    ':col'=>$color, ':dt'=>$issue_date
  ]);
  header('Location: '.$_SERVER['PHP_SELF'].'?'.$qsReload);
  exit;
}

/* ===== dane bazowe: klasy i przedmioty tego nauczyciela ===== */
$classes = $pdo->prepare("SELECT DISTINCT c.id, c.name
                          FROM teacher_subjects ts
                          JOIN school_classes c ON c.id=ts.class_id
                          WHERE ts.teacher_id=:t
                          ORDER BY c.name");
$classes->execute([':t'=>$teacherId]);
$classes = $classes->fetchAll();

$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($classes[0]['id'] ?? 0);

$subjects = [];
if ($selectedClassId) {
  $s = $pdo->prepare("SELECT s.id, s.name
                      FROM teacher_subjects ts
                      JOIN subjects s ON s.id=ts.subject_id
                      WHERE ts.teacher_id=:t AND ts.class_id=:c
                      ORDER BY s.name");
  $s->execute([':t'=>$teacherId, ':c'=>$selectedClassId]);
  $subjects = $s->fetchAll();
}
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : ($subjects[0]['id'] ?? 0);

// okresy
$terms = $pdo->query("SELECT id, name, ordinal FROM terms ORDER BY ordinal")->fetchAll();
$selectedTermId = isset($_GET['term_id']) && $_GET['term_id']!=='' ? (int)$_GET['term_id'] : null;

/* ===== uczniowie klasy ===== */
$students = [];
if ($selectedClassId) {
  $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.login
                       FROM enrollments e JOIN users u ON u.id=e.student_id
                       WHERE e.class_id=:c ORDER BY u.last_name, u.first_name");
  $st->execute([':c'=>$selectedClassId]);
  $students = $st->fetchAll();
}

/* ===== kolumny ocen (assessments) tego nauczyciela dla klasy/przedmiotu/okresu ===== */
$ass = [];
if ($selectedClassId && $selectedSubjectId) {
  $q = "SELECT a.*, gc.name AS cat_name
        FROM assessments a
        LEFT JOIN grade_categories gc ON gc.id=a.category_id
        WHERE a.teacher_id=:t AND a.class_id=:c AND a.subject_id=:s";
  $p = [':t'=>$teacherId, ':c'=>$selectedClassId, ':s'=>$selectedSubjectId];
  if ($selectedTermId) { $q .= " AND a.term_id=:term"; $p[':term']=$selectedTermId; }
  $q .= " ORDER BY a.issue_date, a.id";
  $stmt = $pdo->prepare($q); $stmt->execute($p); $ass = $stmt->fetchAll();
}

/* ===== oceny w tych kolumnach ===== */
$gradesByAssSt = []; // [ass_id][student_id] => row
if ($ass) {
  $assIds = array_column($ass,'id');
  $in = implode(',', array_fill(0, count($assIds), '?'));
  $sql = "SELECT * FROM grades WHERE assessment_id IN ($in)";
  $stmt = $pdo->prepare($sql); $stmt->execute($assIds);
  foreach ($stmt as $g) {
    $gradesByAssSt[(int)$g['assessment_id']][(int)$g['student_id']] = $g;
  }
}

/* ===== średnie ważone (regular, counts_to_avg=1) ===== */
function compute_avg_php(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string {
  $sql = "SELECT value_numeric, weight FROM grades
          WHERE student_id=:st AND subject_id=:sub AND kind='regular' AND counts_to_avg=1".
          ($termId ? " AND term_id=:term" : "");
  $stmt = $pdo->prepare($sql);
  $par = [':st'=>$studentId, ':sub'=>$subjectId];
  if ($termId) $par[':term']=$termId;
  $stmt->execute($par);
  $sum=0; $w=0;
  foreach ($stmt as $r){ $sum += (float)$r['value_numeric']*(float)$r['weight']; $w += (float)$r['weight']; }
  if ($w<=0) return '—';
  return number_format($sum/$w, 2, ',', '');
}

/* ===== kategorie i pastelowe kolory ===== */
$cats = $pdo->query("SELECT id, name FROM grade_categories ORDER BY name")->fetchAll();
$palette = ['#FDE68A','#BFDBFE','#A7F3D0','#FBCFE8','#DDD6FE','#FCA5A5','#E5E7EB','#C7D2FE','#FCD34D','#A5F3FC'];

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/teacher.css">
<main class="container">
  <section id="teacher-root" class="t-card"
           data-api="<?php echo $BASE_URL; ?>/teacher_api.php"
           data-csrf="<?php echo csrf_token(); ?>"
           data-subject-id="<?php echo (int)$selectedSubjectId; ?>"
           data-class-id="<?php echo (int)$selectedClassId; ?>">

    <div class="head h1row">
      <div>
        <h1>Wystawianie ocen</h1>
        <div class="small-muted">Nauczyciel: <?php echo sanitize(($me['first_name']??'').' '.($me['last_name']??'')); ?></div>
      </div>
      <div class="right small-muted">Ocena z „+” = +0,5 &nbsp;|&nbsp; „-” = −0,25 &nbsp;|&nbsp; 0..6 (bez 0+/0−, bez 1−).</div>
    </div>

    <?php if ($flash==='added'): ?>
      <div class="success" style="margin:0 1.25rem 1rem">Dodano kolumnę ocen.</div>
    <?php elseif ($flash==='invalid'): ?>
      <div class="alert" style="margin:0 1.25rem 1rem">Uzupełnij wymagane pola.</div>
    <?php elseif ($flash==='forbidden'): ?>
      <div class="alert" style="margin:0 1.25rem 1rem">Nie uczysz tego przedmiotu w tej klasie.</div>
    <?php elseif ($flash==='csrf'): ?>
      <div class="alert" style="margin:0 1.25rem 1rem">Sesja wygasła. Spróbuj ponownie.</div>
    <?php endif; ?>

    <div class="t-toolbar">
      <form method="get" class="form" style="display:contents">
        <div class="input-wrap">
          <label>Klasa</label>
          <select id="t-class" name="class_id">
            <?php foreach ($classes as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo $selectedClassId===$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="input-wrap">
          <label>Przedmiot</label>
          <select id="t-subject" name="subject_id">
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo $selectedSubjectId===$s['id']?'selected':''; ?>><?php echo sanitize($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="input-wrap">
          <label>Okres</label>
          <select id="t-term" name="term_id">
            <option value="">— cały rok —</option>
            <?php foreach ($terms as $t): ?>
              <option value="<?php echo (int)$t['id']; ?>" <?php echo (string)$selectedTermId===(string)$t['id']?'selected':''; ?>><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="t-toolbar">
      <!-- Fallback: jeśli JS nie zadziała, formularz zrobi POST do tego pliku -->
      <form id="ass-add-form" class="form" method="post" action="">
        <input type="hidden" name="ass_add_fallback" value="1">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="class_id" value="<?php echo (int)$selectedClassId; ?>">
        <input type="hidden" name="subject_id" value="<?php echo (int)$selectedSubjectId; ?>">
        <input type="hidden" name="term_id" value="<?php echo $selectedTermId!==null?(int)$selectedTermId:''; ?>">

        <div class="input-wrap"><label>Tytuł kolumny</label><input name="title" required placeholder="np. Sprawdzian 12.03"></div>
        <div class="input-wrap"><label>Kategoria</label>
          <select name="category_id">
            <option value="">—</option>
            <?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo sanitize($c['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="input-wrap"><label>Data</label><input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="input-wrap"><label>Waga</label><input type="number" name="weight" step="0.01" min="0.01" value="1"></div>
        <div class="input-wrap"><label><input type="checkbox" name="counts_to_avg" checked> Licz do średniej</label></div>
        <div class="input-wrap">
          <label>Kolor</label>
          <div class="colors">
            <?php foreach ($palette as $i=>$hex): ?>
              <label><input type="radio" name="color" value="<?php echo $hex; ?>" <?php echo $i===0?'checked':''; ?>><span class="ass-color" style="background:<?php echo $hex; ?>"></span></label>
            <?php endforeach; ?>
            <label><input type="radio" name="color" value=""><span class="ass-color" style="background:#fff"></span> brak</label>
          </div>
        </div>
        <button class="btn primary" type="submit">Dodaj kolumnę</button>
      </form>
    </div>

    <div class="grd-wrap">
      <table class="grd">
        <thead>
          <tr>
            <th class="sticky">Uczeń</th>
            <?php foreach ($ass as $a): ?>
              <th>
                <div class="ass-head">
                  <span class="ass-color" style="background:<?php echo sanitize($a['color'] ?: '#fff'); ?>"></span>
                  <strong><?php echo sanitize($a['title']); ?></strong>
                  <button class="icon-btn" title="Usuń kolumnę" data-del-ass="<?php echo (int)$a['id']; ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 3h6a1 1 0 0 1 1 1v1h4v2H4V5h4V4a1 1 0 0 1 1-1Zm-3 6h12l-1 11a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 9Z"/></svg>
                  </button>
                </div>
                <span class="ass-meta"><?php echo sanitize($a['issue_date']); ?> <?php if($a['cat_name']) echo '• '.sanitize($a['cat_name']); ?> • waga <span class="badge-soft"><?php echo rtrim(rtrim((string)$a['weight'],'0'),'.'); ?></span></span>
              </th>
            <?php endforeach; ?>
            <th>Średnia</th>
            <th>I (prop.)</th><th>I (ost.)</th>
            <th>R (prop.)</th><th>R (ost.)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $st): ?>
            <tr>
              <th class="sticky student"><?php echo sanitize($st['last_name'].' '.$st['first_name']); ?></th>
              <?php foreach ($ass as $a):
                $g = $gradesByAssSt[$a['id']][$st['id']] ?? null;
                $display = $g ? $g['value_text'] : '';
                $impr    = $g && $g['improved_of_id'] ? true : false;
              ?>
                <td class="cell"
                    data-assessment-id="<?php echo (int)$a['id']; ?>"
                    data-student-id="<?php echo (int)$st['id']; ?>"
                    data-grade-id="<?php echo $g ? (int)$g['id'] : ''; ?>">
                  <div class="view">
                    <?php if ($display!==''): ?>
                      <span class="pill <?php echo $impr?'impr':''; ?>"><?php echo sanitize($display); ?><?php if ($impr) echo ' ↻'; ?></span>
                    <?php else: ?>
                      <span class="small-muted">—</span>
                    <?php endif; ?>
                    <button type="button" class="btn small edit-btn">Edytuj</button>
                    <?php if ($g): ?>
                      <button type="button" class="btn small impr-btn">Popraw</button>
                      <button type="button" class="btn small del-btn">Usuń</button>
                    <?php endif; ?>
                  </div>
                  <div class="edit-inline">
                    <div class="input-wrap"><input class="input-grade" placeholder="np. 4+, 3, +, -" value="<?php echo $g?sanitize($g['value_text']):''; ?>"></div>
                    <div class="qg">
                      <?php foreach (['6','6+','5','5-','5+','4','4-','4+','3','3-','3+','2','2-','2+','1','1+','0','+','-'] as $q): ?>
                        <button type="button" data-val="<?php echo $q; ?>"><?php echo $q; ?></button>
                      <?php endforeach; ?>
                    </div>
                    <div class="input-wrap"><input class="input-comment" placeholder="Komentarz" value="<?php echo $g?sanitize($g['comment']):''; ?>"></div>
                    <div class="qg">
                      <button type="button" class="btn primary save-btn">Zapisz</button>
					  <button type="button" class="btn cancel-btn">Anuluj</button>
                    </div>
                  </div>
                </td>
              <?php endforeach; ?>
              <td class="avg"><?php echo compute_avg_php($pdo,(int)$st['id'],$selectedSubjectId,$selectedTermId); ?></td>

              <?php
                $sum = function(string $kind) use($pdo,$st,$selectedSubjectId,$selectedClassId,$selectedTermId){
                  $q="SELECT value_text FROM grades WHERE student_id=:st AND subject_id=:sub AND class_id=:cls AND kind=:k";
                  $p=[':st'=>$st['id'],':sub'=>$selectedSubjectId,':cls'=>$selectedClassId,':k'=>$kind];
                  if (strpos($kind,'midterm')===0) { $q.=" AND term_id=:term"; $p[':term']=$selectedTermId; }
                  $q.=" ORDER BY created_at DESC LIMIT 1";
                  $s=$pdo->prepare($q); $s->execute($p); $r=$s->fetch();
                  return $r ? $r['value_text'] : '';
                };
              ?>
              <td><input class="input" data-summary-kind="midterm_proposed" data-student-id="<?php echo (int)$st['id']; ?>" value="<?php echo sanitize($sum('midterm_proposed')); ?>"></td>
              <td><input class="input" data-summary-kind="midterm"          data-student-id="<?php echo (int)$st['id']; ?>" value="<?php echo sanitize($sum('midterm')); ?>"></td>
              <td><input class="input" data-summary-kind="final_proposed"   data-student-id="<?php echo (int)$st['id']; ?>" value="<?php echo sanitize($sum('final_proposed')); ?>"></td>
              <td><input class="input" data-summary-kind="final"            data-student-id="<?php echo (int)$st['id']; ?>" value="<?php echo sanitize($sum('final')); ?>"></td>
            </tr>
          <?php endforeach; if (!$students): ?>
            <tr><td colspan="<?php echo count($ass)+5; ?>" class="small-muted">Brak uczniów w klasie.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </section>
</main>
<script src="<?php echo $BASE_URL; ?>/assets/js/teacher_fallback.js?v=1" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>


