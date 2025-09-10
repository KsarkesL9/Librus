<?php
// grades.php – podgląd ocen ucznia (nowy wygląd + jaskrawe kolory)
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

$APP_BODY_CLASS = 'app'; // Włącza jasny motyw app.css

$subjects = [];
$class = null;
$terms = [];
try {
    // Aktywny rok i okresy
    $year = $pdo->query("SELECT * FROM school_years WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch();
    if ($year) {
        $termsStmt = $pdo->prepare("SELECT * FROM terms WHERE school_year_id = :y ORDER BY ordinal");
        $termsStmt->execute([':y' => $year['id']]);
        $terms = $termsStmt->fetchAll();
    }
    
    if (empty($terms)) {
        $terms = $pdo->query("SELECT * FROM terms ORDER BY ordinal LIMIT 2")->fetchAll();
    }
    $hasTwoTerms = count($terms) >= 2;

    // Klasa ucznia
    $clsStmt = $pdo->prepare("SELECT c.* FROM enrollments e JOIN school_classes c ON c.id=e.class_id WHERE e.student_id=:sid ORDER BY e.id DESC LIMIT 1");
    $clsStmt->execute([':sid' => $user['id']]);
    $class = $clsStmt->fetch();

    // Lista przedmiotów
    if ($class) {
        $subStmt = $pdo->prepare("SELECT DISTINCT s.id, s.name
                                  FROM teacher_subjects ts
                                  JOIN subjects s ON s.id = ts.subject_id
                                  WHERE ts.class_id = :cid
                                  ORDER BY s.name");
        $subStmt->execute([':cid' => $class['id']]);
        $subjects = $subStmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Błąd bazy danych w grades.php: ' . $e->getMessage());
    $subjects = [];
}

// NOWA FUNKCJA Z JASKRAWYMI KOLORAMI
function pillColor(array $grade): string {
    // 1. Sprawdź kolor na podstawie nazwy kategorii
    $categoryName = strtolower($grade['cat_name'] ?? '');
    $categoryColors = [
        'sprawdzian'      => '#ef4444', // Jaskrawa czerwień
        'kartkówka'       => '#f97316', // Jaskrawy pomarańcz
        'odpowiedź'       => '#eab308', // Jaskrawy żółty
        'zadanie domowe'  => '#22c55e', // Jaskrawa zieleń
        'aktywność'       => '#3b82f6', // Jaskrawy niebieski
    ];
    foreach ($categoryColors as $key => $color) {
        if (strpos($categoryName, $key) !== false) {
            return $color;
        }
    }

    // 2. Jeśli kategoria nie pasuje, użyj wartości oceny jako fallback
    $val = strtoupper(trim($grade['value_text']));
    if (in_array($val, ['1', '1+', '1-', 'NP', 'BZ', 'NB', '0'])) return '#ef4444'; // Czerwień
    if (in_array($val, ['2', '2+', '2-', '3', '3-', '3+'])) return '#eab308';       // Żółty
    if (in_array($val, ['4', '4+', '4-', '5', '5+', '5-', '6', '6+', '6-'])) return '#22c55e'; // Zielony
    
    // 3. Domyślny kolor
    return '#9ca3af'; // Szary
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
              <th class="sticky" rowspan="2">Przedmiot</th>
              <?php if ($hasTwoTerms): ?>
                <th colspan="2">Okres 1</th>
                <th colspan="2">Okres 2</th>
                <th class="summary-head" rowspan="2">Śr. roczna</th>
              <?php else: ?>
                <th>Oceny bieżące</th>
                <th class="summary-head">Średnia</th>
              <?php endif; ?>
            </tr>
            <tr>
              <?php if ($hasTwoTerms): ?>
                <th>Oceny bieżące</th>
                <th class="summary-head">Śr. I</th>
                <th>Oceny bieżące</th>
                <th class="summary-head">Śr. II</th>
              <?php else: ?>
                 <th>&nbsp;</th>
                 <th>&nbsp;</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subjects as $s):
              $sid = (int)$s['id'];
              $t1_id = $terms[0]['id'] ?? null;
              $t2_id = $terms[1]['id'] ?? null;
            ?>
              <tr>
                <th class="sticky subj-name"><?php echo sanitize($s['name']); ?></th>
                
                <td>
                  <?php 
                    $grades_t1 = getGrades($pdo, $user['id'], $sid, $t1_id);
                    if (!$grades_t1) echo '<span class="muted">Brak ocen</span>';
                    foreach ($grades_t1 as $gr) {
                      $color = pillColor($gr);
                      $titleData = [
                        'Kategoria'   => $gr['cat_name'] ?: '—',
                        'Data'        => (new DateTime($gr['created_at']))->format('Y-m-d'),
                        'Nauczyciel'  => trim(($gr['tfn']??'').' '.($gr['tln']??'')) ?: '—',
                        'Waga'        => rtrim(rtrim((string)$gr['weight'], '0'),'.'),
                        'Do średniej' => $gr['counts_to_avg'] ? 'tak' : 'nie',
                        'Komentarz'   => $gr['comment'] ?: '—',
                        'Ocena'       => $gr['value_text']
                      ];
                      echo sprintf(
                        '<span class="grade-pill" style="--pill-bg:%s; --pill-color:%s;" data-cat="%s" data-date="%s" data-teacher="%s" data-weight="%s" data-avg="%s" data-comment="%s">%s</span>',
                        $color, '#fff',
                        ...array_map('sanitize', array_values($titleData))
                      );
                    }
                  ?>
                </td>
                <td class="summary"><?php echo computeGradesAvg($pdo, $user['id'], $sid, $t1_id); ?></td>
                
                <?php if ($hasTwoTerms): ?>
                  <td>
                    <?php 
                      $grades_t2 = getGrades($pdo, $user['id'], $sid, $t2_id);
                      if (!$grades_t2) echo '<span class="muted">Brak ocen</span>';
                      foreach ($grades_t2 as $gr) {
                        $color = pillColor($gr);
                        $titleData = [
                          'Kategoria'   => $gr['cat_name'] ?: '—',
                          'Data'        => (new DateTime($gr['created_at']))->format('Y-m-d'),
                          'Nauczyciel'  => trim(($gr['tfn']??'').' '.($gr['tln']??'')) ?: '—',
                          'Waga'        => rtrim(rtrim((string)$gr['weight'], '0'),'.'),
                          'Do średniej' => $gr['counts_to_avg'] ? 'tak' : 'nie',
                          'Komentarz'   => $gr['comment'] ?: '—',
                          'Ocena'       => $gr['value_text']
                        ];
                         echo sprintf(
                          '<span class="grade-pill" style="--pill-bg:%s; --pill-color:%s;" data-cat="%s" data-date="%s" data-teacher="%s" data-weight="%s" data-avg="%s" data-comment="%s">%s</span>',
                          $color, '#fff',
                          ...array_map('sanitize', array_values($titleData))
                        );
                      }
                    ?>
                  </td>
                  <td class="summary"><?php echo computeGradesAvg($pdo, $user['id'], $sid, $t2_id); ?></td>
                  <td class="summary final-avg"><?php echo computeGradesAvg($pdo, $user['id'], $sid, null); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>