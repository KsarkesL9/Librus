<?php
// grades.php – podgląd ocen ucznia (jasny motyw, szeroki panel ~88% szerokości)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

require_auth();
$user = current_user();
if (!$user || !in_array('uczeń', $user['roles'] ?? [])) {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
    if ($base === '') { $base = '.'; }
    header("Location: $base/dashboard.php");
    exit;
}

$APP_BODY_CLASS = 'app'; // włącza jasny motyw app.css

// Aktywny rok i okresy
$year = $pdo->query("SELECT * FROM school_years WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch();
if ($year) {
    $termsStmt = $pdo->prepare("SELECT * FROM terms WHERE school_year_id = :y ORDER BY ordinal");
    $termsStmt->execute([':y' => $year['id']]);
    $terms = $termsStmt->fetchAll();
} else {
    $terms = $pdo->query("SELECT * FROM terms ORDER BY ordinal")->fetchAll();
}
$hasTwoTerms = count($terms) >= 2;

// Klasa ucznia (ostatni zapis)
$clsStmt = $pdo->prepare("SELECT c.* FROM enrollments e JOIN school_classes c ON c.id=e.class_id WHERE e.student_id=:sid ORDER BY e.id DESC LIMIT 1");
$clsStmt->execute([':sid' => $user['id']]);
$class = $clsStmt->fetch();

// Lista przedmiotów
$subjects = [];
if ($class) {
    $subStmt = $pdo->prepare("SELECT DISTINCT s.id, s.name
                              FROM teacher_subjects ts
                              JOIN subjects s ON s.id = ts.subject_id
                              WHERE ts.class_id = :cid
                              ORDER BY s.name");
    $subStmt->execute([':cid' => $class['id']]);
    $subjects = $subStmt->fetchAll();
}
if (!$subjects) {
    $subStmt = $pdo->prepare("SELECT DISTINCT s.id, s.name
                              FROM grades g
                              JOIN subjects s ON s.id = g.subject_id
                              WHERE g.student_id = :sid
                              ORDER BY s.name");
    $subStmt->execute([':sid' => $user['id']]);
    $subjects = $subStmt->fetchAll();
}

// Pomocnicze
function pillColor(string $val, ?string $catColor): string {
    if ($catColor) return $catColor;
    $v = strtoupper(trim($val));
    if (in_array($v, ['1','1-','1+','2','2-'])) return '#ef4444';
    if (in_array($v, ['3','3-','3+'])) return '#f97316';
    if (in_array($v, ['4','4-','4+'])) return '#fde047';
    if (in_array($v, ['5','5-','5+','6','6-','6+'])) return '#86efac';
    if (in_array($v, ['NP','BZ','NB'])) return '#cbd5e1';
    return '#e5e7eb';
}

function getGrades(PDO $pdo, int $studentId, int $subjectId, ?int $termId): array {
    $sql = "SELECT g.*, gc.name AS cat_name, gc.code AS cat_code,
                   t.first_name AS tfn, t.last_name AS tln
            FROM grades g
            LEFT JOIN grade_categories gc ON gc.id = g.category_id
            LEFT JOIN users t ON t.id = g.teacher_id
            WHERE g.student_id = :sid AND g.subject_id = :sub
              AND g.kind = 'regular' ".
              ($termId ? " AND g.term_id = :term " : "") . "
              AND (g.published_at IS NULL OR g.published_at <= NOW())
            ORDER BY g.created_at";
    $stmt = $pdo->prepare($sql);
    $params = [':sid' => $studentId, ':sub' => $subjectId];
    if ($termId) $params[':term'] = $termId;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSummaryGrade(PDO $pdo, int $studentId, int $subjectId, ?int $termId, string $kind): ?string {
    $sql = "SELECT value_text FROM grades
            WHERE student_id=:sid AND subject_id=:sub AND kind=:k ".
           ($termId ? " AND term_id=:term " : "") . "
            ORDER BY created_at DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $p = [':sid'=>$studentId, ':sub'=>$subjectId, ':k'=>$kind];
    if ($termId) $p[':term'] = $termId;
    $stmt->execute($p);
    $row = $stmt->fetch();
    return $row ? $row['value_text'] : null;
}

function computeGradesAvg(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string {
  $sql = "SELECT g.value_numeric, g.weight FROM grades g
          WHERE g.student_id=:st AND g.subject_id=:sub AND g.kind='regular' AND g.counts_to_avg=1"
          . ($termId ? " AND g.term_id=:term" : "");
  $stmt = $pdo->prepare($sql);
  $par = [':st'=>$studentId, ':sub'=>$subjectId];
  if ($termId) $par[':term']=$termId;
  $stmt->execute($par);
  $sum=0; $w=0;
  foreach ($stmt as $r){ $sum += (float)$r['value_numeric']*(float)$r['weight']; $w += (float)$r['weight']; }
  if ($w<=0) return '—';
  return number_format($sum/$w, 2, '.', '');
}


// Po wejściu odznaczamy „nowe”
$pdo->prepare("UPDATE users SET last_grades_seen_at = NOW() WHERE id=:id")->execute([':id'=>$user['id']]);

include __DIR__ . '/includes/header.php';
?>
<main class="container">
  <section class="grades-card">
    <div class="student-bar">
      <span class="avatar" aria-hidden="true"></span>
      <div>
        <div><strong>Uczeń:</strong> <?php echo sanitize(trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''))); ?></div>
        <div><strong>Klasa:</strong> <?php echo sanitize($class['name'] ?? '—'); ?></div>
      </div>
    </div>

    <h1>Oceny bieżące</h1>

    <?php if (!$subjects): ?>
      <div class="alert">Brak skonfigurowanych przedmiotów lub brak ocen do wyświetlenia.</div>
    <?php else: ?>
      <div class="grades-table-wrap">
        <table class="grades-table">
          <thead>
            <tr>
              <th class="sticky">Przedmiot</th>
              <?php if ($hasTwoTerms): ?>
                <th colspan="2">Okres 1</th>
                <th colspan="2">Okres 2</th>
              <?php else: ?>
                <th>Oceny bieżące</th>
                <th>Podsum.</th>
              <?php endif; ?>
              <th>Średnia</th>
            </tr>
            <tr>
              <th class="sticky">&nbsp;</th>
              <?php if ($hasTwoTerms): ?>
                <th>Oceny bieżące</th><th class="summary-head">I</th>
                <th>Oceny bieżące</th><th class="summary-head">R</th>
              <?php else: ?>
                <th>Oceny bieżące</th><th class="summary-head">I/R</th>
              <?php endif; ?>
              <th>&nbsp;</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subjects as $s):
              $sid = (int)$s['id'];
              $t1 = $terms[0]['id'] ?? null;
              $t2 = $terms[1]['id'] ?? null;

              $g1 = getGrades($pdo, $user['id'], $sid, $t1);
              $g2 = getGrades($pdo, $user['id'], $sid, $t2);

              $sum1 = $t1 ? getSummaryGrade($pdo, $user['id'], $sid, $t1, 'midterm')
                          : getSummaryGrade($pdo, $user['id'], $sid, null, 'midterm');
              $sum2 = $t2 ? getSummaryGrade($pdo, $user['id'], $sid, $t2, 'final')
                          : getSummaryGrade($pdo, $user['id'], $sid, null, 'final');
            ?>
              <tr>
                <th class="sticky subj-name"><?php echo sanitize($s['name']); ?></th>

                <?php if ($hasTwoTerms): ?>
                  <td>
                    <?php if (!$g1): ?><span class="muted">Brak ocen</span><?php endif; ?>
                    <?php foreach ($g1 as $gr):
                      $bg = pillColor($gr['value_text'], $gr['cat_color'] ?? null);
                      $title = [
                        'Kategoria'   => $gr['cat_name'] ?: '—',
                        'Data'        => (new DateTime($gr['created_at']))->format('Y-m-d'),
                        'Nauczyciel'  => trim(($gr['tfn']??'').' '.($gr['tln']??'')) ?: '—',
                        'Waga'        => rtrim(rtrim((string)$gr['weight'], '0'),'.'),
                        'Do średniej' => $gr['counts_to_avg'] ? 'tak' : 'nie',
                        'Komentarz'   => $gr['comment'] ?: '—'
                      ];
                    ?>
                      <span class="grade-pill"
                        style="--pill-bg:<?php echo $bg; ?>"
                        data-cat="<?php echo sanitize($title['Kategoria']); ?>"
                        data-date="<?php echo sanitize($title['Data']); ?>"
                        data-teacher="<?php echo sanitize($title['Nauczyciel']); ?>"
                        data-weight="<?php echo sanitize($title['Waga']); ?>"
                        data-avg="<?php echo sanitize($title['Do średniej']); ?>"
                        data-comment="<?php echo sanitize($title['Komentarz']); ?>">
                        <?php echo sanitize($gr['value_text']); ?>
                      </span>
                    <?php endforeach; ?>
                  </td>
                  <td class="summary">
                    <?php echo $sum1 ? '<span class="sum-pill">'.sanitize($sum1).'</span>' : '—'; ?>
                  </td>

                  <td>
                    <?php if (!$g2): ?><span class="muted">Brak ocen</span><?php endif; ?>
                    <?php foreach ($g2 as $gr):
                      $bg = pillColor($gr['value_text'], $gr['cat_color'] ?? null);
                      $title = [
                        'Kategoria'   => $gr['cat_name'] ?: '—',
                        'Data'        => (new DateTime($gr['created_at']))->format('Y-m-d'),
                        'Nauczyciel'  => trim(($gr['tfn']??'').' '.($gr['tln']??'')) ?: '—',
                        'Waga'        => rtrim(rtrim((string)$gr['weight'], '0'),'.'),
                        'Do średniej' => $gr['counts_to_avg'] ? 'tak' : 'nie',
                        'Komentarz'   => $gr['comment'] ?: '—'
                      ];
                    ?>
                      <span class="grade-pill"
                        style="--pill-bg:<?php echo $bg; ?>"
                        data-cat="<?php echo sanitize($title['Kategoria']); ?>"
                        data-date="<?php echo sanitize($title['Data']); ?>"
                        data-teacher="<?php echo sanitize($title['Nauczyciel']); ?>"
                        data-weight="<?php echo sanitize($title['Waga']); ?>"
                        data-avg="<?php echo sanitize($title['Do średniej']); ?>"
                        data-comment="<?php echo sanitize($title['Komentarz']); ?>">
                        <?php echo sanitize($gr['value_text']); ?>
                      </span>
                    <?php endforeach; ?>
                  </td>
                  <td class="summary">
                    <?php echo $sum2 ? '<span class="sum-pill">'.sanitize($sum2).'</span>' : '—'; ?>
                  </td>
                <?php else: ?>
                  <td>
                    <?php $gAll = getGrades($pdo, $user['id'], $sid, null);
                    if (!$gAll): ?><span class="muted">Brak ocen</span><?php endif; ?>
                    <?php foreach ($gAll as $gr):
                      $bg = pillColor($gr['value_text'], $gr['cat_color'] ?? null);
                      $title = [
                        'Kategoria'   => $gr['cat_name'] ?: '—',
                        'Data'        => (new DateTime($gr['created_at']))->format('Y-m-d'),
                        'Nauczyciel'  => trim(($gr['tfn']??'').' '.($gr['tln']??'')) ?: '—',
                        'Waga'        => rtrim(rtrim((string)$gr['weight'], '0'),'.'),
                        'Do średniej' => $gr['counts_to_avg'] ? 'tak' : 'nie',
                        'Komentarz'   => $gr['comment'] ?: '—'
                      ];
                    ?>
                      <span class="grade-pill"
                        style="--pill-bg:<?php echo $bg; ?>"
                        data-cat="<?php echo sanitize($title['Kategoria']); ?>"
                        data-date="<?php echo sanitize($title['Data']); ?>"
                        data-teacher="<?php echo sanitize($title['Nauczyciel']); ?>"
                        data-weight="<?php echo sanitize($title['Waga']); ?>"
                        data-avg="<?php echo sanitize($title['Do średniej']); ?>"
                        data-comment="<?php echo sanitize($title['Komentarz']); ?>">
                        <?php echo sanitize($gr['value_text']); ?>
                      </span>
                    <?php endforeach; ?>
                  </td>
                  <td class="summary">
                    <?php
                      $sum = getSummaryGrade($pdo, $user['id'], $sid, null, 'final')
                           ?? getSummaryGrade($pdo, $user['id'], $sid, null, 'midterm')
                           ?? null;
                      echo $sum ? '<span class="sum-pill">'.sanitize($sum).'</span>' : '—';
                    ?>
                  </td>
                <?php endif; ?>
                <td class="summary">
                  <span class="sum-pill">
                    <?php echo computeGradesAvg($pdo, $user['id'], $sid, null); ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>