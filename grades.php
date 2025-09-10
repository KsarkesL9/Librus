<?php
// grades.php – podgląd ocen ucznia (z ostateczną poprawką błędu w średniej)
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

function pillColor(array $grade): string {
    $categoryName = strtolower($grade['cat_name'] ?? '');
    $categoryColors = [
        'sprawdzian' => '#ef4444', 'kartkówka' => '#f97316', 'odpowiedź' => '#eab308',
        'zadanie domowe' => '#22c55e', 'aktywność' => '#3b82f6',
    ];
    foreach ($categoryColors as $key => $color) {
        if (strpos($categoryName, $key) !== false) return $color;
    }
    $val = strtoupper(trim($grade['value_text']));
    if (in_array($val, ['1', '1+', '1-', 'NP', 'BZ', 'NB', '0'])) return '#ef4444';
    if (in_array($val, ['2', '2+', '2-', '3', '3-', '3+'])) return '#eab308';
    if (in_array($val, ['4', '4+', '4-', '5', '5+', '5-', '6', '6+', '6-'])) return '#22c55e';
    return '#9ca3af';
}

function getGradesGrouped(PDO $pdo, int $studentId, int $subjectId, ?int $termId): array {
    $sql = "SELECT g.*, gc.name AS cat_name, gc.code AS cat_code, a.title AS ass_title,
                   t.first_name AS tfn, t.last_name AS tln
            FROM grades g
            LEFT JOIN assessments a ON a.id = g.assessment_id
            LEFT JOIN grade_categories gc ON gc.id = g.category_id
            LEFT JOIN users t ON t.id = g.teacher_id
            WHERE g.student_id = :sid AND g.subject_id = :sub
              AND g.kind = 'regular' ".
              ($termId ? " AND g.term_id = :term " : "") . "
              AND (g.published_at IS NULL OR g.published_at <= NOW())
            ORDER BY g.assessment_id, g.created_at";
    $stmt = $pdo->prepare($sql);
    $params = [':sid' => $studentId, ':sub' => $subjectId];
    if ($termId) $params[':term'] = $termId;
    $stmt->execute($params);
    
    $grouped = [];
    foreach($stmt->fetchAll() as $grade) {
        $grouped[$grade['assessment_id']][] = $grade;
    }
    return $grouped;
}

function computeGradesAvg(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string {
  // POPRAWKA: Dodano g.assessment_id do zapytania SELECT
  $sql = "SELECT g.value_numeric, g.weight, g.assessment_id FROM grades g
          JOIN assessments a ON g.assessment_id = a.id
          WHERE g.student_id=:st AND g.subject_id=:sub AND g.kind='regular' AND a.counts_to_avg=1"
          . ($termId ? " AND g.term_id=:term" : "");
  $stmt = $pdo->prepare($sql);
  $par = [':st'=>$studentId, ':sub'=>$subjectId];
  if ($termId) $par[':term']=$termId;
  $stmt->execute($par);

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
  return number_format($total_sum / $total_weight, 2, '.', '');
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
                    $grades_t1 = getGradesGrouped($pdo, $user['id'], $sid, $t1_id);
                    if (empty($grades_t1)) echo '<span class="muted">Brak ocen</span>';
                    
                    foreach ($grades_t1 as $assessment_group) {
                        echo '<div class="grade-group">';
                        if (count($assessment_group) > 1) { echo '<span class="group-bracket">[</span>'; }

                        foreach ($assessment_group as $idx => $gr) {
                            $color = pillColor($gr);
                            $args = [
                                'color' => $color,
                                'title' => $gr['ass_title'] ?: '—',
                                'cat' => $gr['cat_name'] ?: '—',
                                'date' => (new DateTime($gr['created_at']))->format('Y-m-d'),
                                'teacher' => trim(($gr['tfn'] ?? '') . ' ' . ($gr['tln'] ?? '')) ?: '—',
                                'weight' => rtrim(rtrim((string)$gr['weight'], '0'), '.'),
                                'comment' => $gr['comment'] ?: '—',
                                'value' => $gr['value_text']
                            ];
                            $sanitized_args = array_map('sanitize', $args);
                            echo vsprintf(
                                '<span class="grade-pill" style="--pill-bg:%s;" data-title="%s" data-cat="%s" data-date="%s" data-teacher="%s" data-weight="%s" data-comment="%s">%s</span>',
                                $sanitized_args
                            );

                            if (count($assessment_group) > 1 && $idx < count($assessment_group) - 1) {
                                echo '<span class="group-arrow">→</span>';
                            }
                        }
                        if (count($assessment_group) > 1) { echo '<span class="group-bracket">]</span>'; }
                        echo '</div>';
                    }
                  ?>
                </td>
                <td class="summary"><?php echo computeGradesAvg($pdo, $user['id'], $sid, $t1_id); ?></td>
                
                <?php if ($hasTwoTerms): ?>
                  <td>
                    <?php 
                      $grades_t2 = getGradesGrouped($pdo, $user['id'], $sid, $t2_id);
                      if (empty($grades_t2)) echo '<span class="muted">Brak ocen</span>';
                      
                      foreach ($grades_t2 as $assessment_group) {
                          echo '<div class="grade-group">';
                          if (count($assessment_group) > 1) { echo '<span class="group-bracket">[</span>'; }

                          foreach ($assessment_group as $idx => $gr) {
                              $color = pillColor($gr);
                              $args = [
                                  'color' => $color,
                                  'title' => $gr['ass_title'] ?: '—',
                                  'cat' => $gr['cat_name'] ?: '—',
                                  'date' => (new DateTime($gr['created_at']))->format('Y-m-d'),
                                  'teacher' => trim(($gr['tfn'] ?? '') . ' ' . ($gr['tln'] ?? '')) ?: '—',
                                  'weight' => rtrim(rtrim((string)$gr['weight'], '0'), '.'),
                                  'comment' => $gr['comment'] ?: '—',
                                  'value' => $gr['value_text']
                              ];
                              $sanitized_args = array_map('sanitize', $args);
                              echo vsprintf(
                                  '<span class="grade-pill" style="--pill-bg:%s;" data-title="%s" data-cat="%s" data-date="%s" data-teacher="%s" data-weight="%s" data-comment="%s">%s</span>',
                                  $sanitized_args
                              );
                              
                              if (count($assessment_group) > 1 && $idx < count($assessment_group) - 1) {
                                  echo '<span class="group-arrow">→</span>';
                              }
                          }
                          if (count($assessment_group) > 1) { echo '<span class="group-bracket">]</span>'; }
                          echo '</div>';
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
<script>
document.addEventListener('DOMContentLoaded', () => {
  let tooltipElement = null;

  function createTooltip() {
    if (tooltipElement) return;
    tooltipElement = document.createElement('div');
    tooltipElement.className = 'grade-tooltip';
    document.body.appendChild(tooltipElement);
  }

  function showTooltip(pill) {
    createTooltip();
    
    const data = {
      'Tytuł': pill.dataset.title || '—',
      'Kategoria': pill.dataset.cat || '—',
      'Data': pill.dataset.date || '—',
      'Nauczyciel': pill.dataset.teacher || '—',
      'Waga': pill.dataset.weight || '—',
      'Komentarz': pill.dataset.comment || '—'
    };

    tooltipElement.innerHTML = Object.entries(data)
      .map(([key, value]) => `<div class="row"><span>${key}:</span><strong>${value}</strong></div>`)
      .join('');

    const pillRect = pill.getBoundingClientRect();
    tooltipElement.classList.add('visible');
    const tooltipRect = tooltipElement.getBoundingClientRect();

    let top = pillRect.top - tooltipRect.height - 10;
    let left = pillRect.left + (pillRect.width / 2) - (tooltipRect.width / 2);

    if (top < 10) top = pillRect.bottom + 10;
    if (left < 10) left = 10;
    if (left + tooltipRect.width > window.innerWidth - 10) {
      left = window.innerWidth - tooltipRect.width - 10;
    }

    tooltipElement.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
  }

  function hideTooltip() {
    if (tooltipElement) tooltipElement.classList.remove('visible');
  }

  document.querySelectorAll('.grade-pill').forEach(pill => {
      pill.addEventListener('mouseenter', () => showTooltip(pill));
      pill.addEventListener('mouseleave', hideTooltip);
  });

  window.addEventListener('scroll', hideTooltip, true);
  window.addEventListener('resize', hideTooltip, true);
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>