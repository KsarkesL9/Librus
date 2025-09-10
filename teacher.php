<?php
// teacher.php – Wersja z nowym wyświetlaniem ocen
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

$APP_BODY_CLASS = 'app';
$teacherId = (int)$me['id'];

$classes = $pdo->prepare("SELECT DISTINCT c.id, c.name FROM teacher_subjects ts JOIN school_classes c ON c.id=ts.class_id WHERE ts.teacher_id=:t ORDER BY c.name");
$classes->execute([':t' => $teacherId]);
$classes = $classes->fetchAll();
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($classes[0]['id'] ?? 0);
$subjects = [];
if ($selectedClassId) {
    $s = $pdo->prepare("SELECT s.id, s.name FROM teacher_subjects ts JOIN subjects s ON s.id=ts.subject_id WHERE ts.teacher_id=:t AND ts.class_id=:c ORDER BY s.name");
    $s->execute([':t' => $teacherId, ':c' => $selectedClassId]);
    $subjects = $s->fetchAll();
}
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : ($subjects[0]['id'] ?? 0);
$terms = $pdo->query("SELECT id, name, ordinal FROM terms ORDER BY ordinal")->fetchAll();
$selectedTermId = isset($_GET['term_id']) && $_GET['term_id'] !== '' ? (int)$_GET['term_id'] : null;

$students = [];
$assessments = [];
$grades_by_student_assessment = [];

if ($selectedClassId && $selectedSubjectId) {
    $st_stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.class_id=:c ORDER BY u.last_name, u.first_name");
    $st_stmt->execute([':c' => $selectedClassId]);
    $students = $st_stmt->fetchAll();

    $ass_sql = "SELECT a.*, gc.name AS cat_name FROM assessments a LEFT JOIN grade_categories gc ON gc.id=a.category_id WHERE a.teacher_id=:t AND a.class_id=:c AND a.subject_id=:s";
    $ass_params = [':t' => $teacherId, ':c' => $selectedClassId, ':s' => $selectedSubjectId];
    if ($selectedTermId) {
        $ass_sql .= " AND a.term_id=:term";
        $ass_params[':term'] = $selectedTermId;
    }
    $ass_sql .= " ORDER BY a.issue_date, a.id";
    $ass_stmt = $pdo->prepare($ass_sql);
    $ass_stmt->execute($ass_params);
    $assessments = $ass_stmt->fetchAll();

    if (!empty($students) && !empty($assessments)) {
        $ass_ids = array_column($assessments, 'id');
        $student_ids = array_column($students, 'id');
        
        $grades_sql = "SELECT * FROM grades WHERE student_id IN (" . implode(',', $student_ids) . ") AND assessment_id IN (" . implode(',', $ass_ids) . ") ORDER BY created_at ASC";
        $grades_stmt = $pdo->query($grades_sql);
        
        foreach ($grades_stmt as $grade) {
            $grades_by_student_assessment[$grade['student_id']][$grade['assessment_id']][] = $grade;
        }
    }
}

function compute_avg_php(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string {
    $sql = "SELECT g.value_numeric, g.weight, g.assessment_id 
            FROM grades g 
            JOIN assessments a ON g.assessment_id = a.id
            WHERE g.student_id = :st 
              AND g.subject_id = :sub 
              AND g.kind = 'regular' 
              AND a.counts_to_avg = 1"
           . ($termId ? " AND g.term_id = :term" : "");
           
    $stmt = $pdo->prepare($sql);
    $params = [':st' => $studentId, ':sub' => $subjectId];
    if ($termId) $params[':term'] = $termId;
    $stmt->execute($params);
    
    $grades_by_assessment = [];
    foreach ($stmt as $row) {
        if ($row['value_numeric'] !== null) {
            $grades_by_assessment[$row['assessment_id']]['values'][] = (float)$row['value_numeric'];
            $grades_by_assessment[$row['assessment_id']]['weight'] = (float)$row['weight'];
        }
    }
    
    $total_sum = 0;
    $total_weight = 0;
    
    foreach ($grades_by_assessment as $ass_id => $data) {
        if (!empty($data['values'])) {
            $avg_for_assessment = array_sum($data['values']) / count($data['values']);
            $total_sum += $avg_for_assessment * $data['weight'];
            $total_weight += $data['weight'];
        }
    }

    if ($total_weight <= 0) return '—';
    return number_format($total_sum / $total_weight, 2, ',', '');
}

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/teacher.css">
<main class="container">
    <section id="teacher-root" class="t-card"
             data-base-url="<?php echo $BASE_URL; ?>"
             data-subject-id="<?php echo (int)$selectedSubjectId; ?>"
             data-class-id="<?php echo (int)$selectedClassId; ?>"
             data-term-id="<?php echo $selectedTermId !== null ? (int)$selectedTermId : ''; ?>">

        <div class="head h1row">
            <div>
                <h1>Dziennik ocen</h1>
                <div class="small-muted">Nauczyciel: <?php echo sanitize(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')); ?></div>
            </div>
            <div class="right">
                <button id="add-grade-btn" class="btn primary">Wystaw / Dodaj kolumnę</button>
            </div>
        </div>

        <div class="t-toolbar">
          <form method="get" class="form" style="display:contents">
            <div class="input-wrap"><label>Klasa</label>
              <select name="class_id" onchange="this.form.submit()">
                <?php foreach ($classes as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo $selectedClassId === $c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="input-wrap"><label>Przedmiot</label>
              <select name="subject_id" onchange="this.form.submit()">
                <?php foreach ($subjects as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo $selectedSubjectId === $s['id'] ? 'selected' : ''; ?>><?php echo sanitize($s['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="input-wrap"><label>Okres</label>
              <select name="term_id" onchange="this.form.submit()">
                <option value="">— cały rok —</option>
                <?php foreach ($terms as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>" <?php echo (string)$selectedTermId === (string)$t['id'] ? 'selected' : ''; ?>><?php echo sanitize($t['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </div>

        <div class="grd-wrap">
            <table class="grd">
                <thead>
                <tr>
                    <th class="sticky">Uczeń</th>
                    <?php foreach ($assessments as $a): ?>
                        <th>
                            <div class="ass-head"><strong><?php echo sanitize($a['title']); ?></strong></div>
                            <span class="ass-meta"><?php echo sanitize($a['issue_date']); ?> • waga <span class="badge-soft"><?php echo rtrim(rtrim((string)$a['weight'], '0'), '.'); ?></span></span>
                        </th>
                    <?php endforeach; ?>
                    <th>Średnia</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $st): ?>
                    <tr>
                        <th class="sticky student"><?php echo sanitize($st['last_name'] . ' ' . $st['first_name']); ?></th>
                        <?php foreach ($assessments as $a):
                            $grades_in_cell = $grades_by_student_assessment[$st['id']][$a['id']] ?? [];
                            $grades_count = count($grades_in_cell);
                        ?>
                            <td class="cell">
                                <?php if ($grades_count === 0): ?>
                                    <button class="add-grade-plus" data-student-id="<?php echo (int)$st['id']; ?>" data-assessment-id="<?php echo (int)$a['id']; ?>">+</button>
                                <?php else: ?>
                                    <div class="grade-container">
                                        <?php if ($grades_count === 1): ?>
                                            <span class="pill" data-student-id="<?php echo (int)$st['id']; ?>" data-assessment-id="<?php echo (int)$a['id']; ?>" data-grade-id="<?php echo (int)$grades_in_cell[0]['id']; ?>">
                                                <?php echo sanitize($grades_in_cell[0]['value_text']); ?>
                                            </span>
                                        <?php elseif ($grades_count >= 2): ?>
                                            <span class="pill" data-student-id="<?php echo (int)$st['id']; ?>" data-assessment-id="<?php echo (int)$a['id']; ?>" data-grade-id="<?php echo (int)$grades_in_cell[0]['id']; ?>">
                                                <?php echo sanitize($grades_in_cell[0]['value_text']); ?>
                                            </span>
                                            <span class="grade-arrow">→</span>
                                            <span class="pill improved" data-student-id="<?php echo (int)$st['id']; ?>" data-assessment-id="<?php echo (int)$a['id']; ?>" data-grade-id="<?php echo (int)$grades_in_cell[1]['id']; ?>">
                                                <?php echo sanitize($grades_in_cell[1]['value_text']); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($grades_count < 2): ?>
                                            <button class="add-grade-plus improve" data-student-id="<?php echo (int)$st['id']; ?>" data-assessment-id="<?php echo (int)$a['id']; ?>">+</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="avg"><?php echo compute_avg_php($pdo, (int)$st['id'], $selectedSubjectId, $selectedTermId); ?></td>
                    </tr>
                <?php endforeach; if (!$students): ?>
                    <tr><td colspan="<?php echo count($assessments) + 2; ?>" class="small-muted">Brak uczniów w klasie.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="<?php echo $BASE_URL; ?>/assets/js/teacher.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>