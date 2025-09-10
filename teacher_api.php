<?php
// teacher_api.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['teacher_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu. Zaloguj się ponownie.']);
    exit;
}
$teacherId = (int)$_SESSION['teacher_id'];

// Pomocnicza funkcja do przeliczania średniej ważonej
function compute_avg_api(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string
{
    $sql = "SELECT g.value_numeric, g.weight FROM grades g
            WHERE g.student_id=:st AND g.subject_id=:sub AND g.kind='regular' AND g.counts_to_avg=1"
        . ($termId ? " AND g.term_id=:term" : "");
    $stmt = $pdo->prepare($sql);
    $par = [':st' => $studentId, ':sub' => $subjectId];
    if ($termId) $par[':term'] = $termId;
    $stmt->execute($par);
    $sum = 0;
    $w = 0;
    foreach ($stmt as $r) {
        $sum += (float)$r['value_numeric'] * (float)$r['weight'];
        $w += (float)$r['weight'];
    }
    if ($w <= 0) return '—';
    return number_format($sum / $w, 2, '.', '');
}

// Mapowanie ocen tekstowych na wartości numeryczne
function grade_to_numeric(string $grade): ?float
{
    $g = trim(strtoupper($grade));
    $map = [
        '6' => 6.0, '6+' => 6.0, '6-' => 5.75,
        '5' => 5.0, '5+' => 5.5, '5-' => 4.75,
        '4' => 4.0, '4+' => 4.5, '4-' => 3.75,
        '3' => 3.0, '3+' => 3.5, '3-' => 2.75,
        '2' => 2.0, '2+' => 2.5, '2-' => 1.75,
        '1' => 1.0, '1+' => 1.5,
        '+' => 0.5, '-' => -0.25,
        'NP' => null, 'BZ' => null, 'NB' => null
    ];
    return $map[$g] ?? null;
}

$action = $_REQUEST['action'] ?? '';
$input = $_POST;

try {
    switch ($action) {
        // Dodawanie kolumny ocen (używane przez AJAX na stronie teacher.php)
        case 'add':
            $title = trim($input['title'] ?? '');
            $category_id = strlen($input['category_id'] ?? '') ? (int)$input['category_id'] : null;
            $weight = isset($input['weight']) ? (float)$input['weight'] : 1.0;
            $counts = (int)($input['counts_to_avg'] ?? 1);
            $issue_date = $input['issue_date'] ?? date('Y-m-d');

            if ($title === '') {
                throw new Exception('Brak tytułu.');
            }

            $stmt = $pdo->prepare("INSERT INTO assessments
                (teacher_id, class_id, subject_id, term_id, title, category_id, weight, counts_to_avg, issue_date)
                VALUES (:teacher_id, :class_id, :subject_id, :term_id, :title, :category_id, :weight, :counts, :issue_date)
            ");
            $stmt->execute([
                ':teacher_id' => $teacherId,
                ':class_id' => (int)($input['class_id'] ?? 0),
                ':subject_id' => (int)($input['subject_id'] ?? 0),
                ':term_id' => strlen($input['term_id'] ?? '') ? (int)$input['term_id'] : null,
                ':title' => $title,
                ':category_id' => $category_id,
                ':weight' => $weight,
                ':counts' => $counts,
                ':issue_date' => $issue_date
            ]);
            $insertId = (int)$pdo->lastInsertId();

            // Zwróć nowy wiersz
            $rowStmt = $pdo->prepare("SELECT a.*, gc.name AS cat_name FROM assessments a LEFT JOIN grade_categories gc ON a.category_id = gc.id WHERE a.id = :id");
            $rowStmt->execute([':id' => $insertId]);
            $newRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'row' => $newRow]);
            break;

        // Edycja kolumny (np. zmiana tytułu)
        case 'edit':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Brak id.');
            $check = $pdo->prepare("SELECT teacher_id FROM assessments WHERE id = :id");
            $check->execute([':id' => $id]);
            $owner = $check->fetchColumn();
            if (!$owner || (int)$owner !== $teacherId) {
                http_response_code(403);
                throw new Exception('Brak uprawnień do edycji.');
            }

            $upd = $pdo->prepare("UPDATE assessments SET title = :title, category_id = :category_id, weight = :weight, counts_to_avg = :counts, issue_date = :issue_date WHERE id = :id");
            $upd->execute([
                ':title' => trim($input['title'] ?? ''),
                ':category_id' => strlen($input['category_id'] ?? '') ? (int)$input['category_id'] : null,
                ':weight' => (float)($input['weight'] ?? 1.0),
                ':counts' => (int)($input['counts_to_avg'] ?? 1),
                ':issue_date' => $input['issue_date'] ?? date('Y-m-d'),
                ':id' => $id
            ]);
            echo json_encode(['ok' => true]);
            break;

        // Usuwanie kolumny
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Brak id.');
            $check = $pdo->prepare("SELECT teacher_id FROM assessments WHERE id = :id");
            $check->execute([':id' => $id]);
            $owner = $check->fetchColumn();
            if (!$owner || (int)$owner !== $teacherId) {
                http_response_code(403);
                throw new Exception('Brak uprawnień do usunięcia.');
            }
            $pdo->prepare("DELETE FROM assessments WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            break;
            
        case 'grade_set':
            $assId = (int)($input['assessment_id'] ?? 0);
            $studentId = (int)($input['student_id'] ?? 0);
            $gradeId = (int)($input['grade_id'] ?? 0);
            $valueText = trim($input['value_text'] ?? '');
            $comment = trim($input['comment'] ?? '');

            if (!$assId || !$studentId) throw new Exception('Brakuje identyfikatorów.');
            
            // Poprawne sprawdzenie uprawnień
            $assStmt = $pdo->prepare("SELECT * FROM assessments WHERE id=:id AND teacher_id=:tid");
            $assStmt->execute([':id'=>$assId, ':tid'=>$teacherId]);
            $ass = $assStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ass) throw new Exception('Brak uprawnień do tej kolumny.');

            $isNew = $gradeId === 0;

            if ($valueText === '') {
                // jeśli puste — usuwamy (jak w `grade_delete`)
                if (!$isNew) {
                    $pdo->prepare("DELETE FROM grades WHERE id = :id AND teacher_id = :tid")->execute([':id' => $gradeId, ':tid' => $teacherId]);
                }
                $grade = ['id' => 0, 'value_text' => ''];
            } else {
                $gradeData = [
                    ':student_id' => $studentId,
                    ':subject_id' => (int)($input['subject_id'] ?? 0),
                    ':teacher_id' => $teacherId,
                    ':term_id' => strlen($input['term_id'] ?? '') ? (int)$input['term_id'] : null,
                    ':category_id' => $ass['category_id'],
                    ':value_text' => $valueText,
                    ':value_numeric' => grade_to_numeric($valueText),
                    ':weight' => $ass['weight'],
                    ':counts_to_avg' => $ass['counts_to_avg'],
                    ':comment' => $comment,
                    ':assessment_id' => $assId,
                ];

                if ($isNew) {
                    $sql = "INSERT INTO grades (student_id, subject_id, teacher_id, term_id, category_id, kind, value_text, value_numeric, weight, counts_to_avg, comment, assessment_id)
                            VALUES (:student_id, :subject_id, :teacher_id, :term_id, :category_id, 'regular', :value_text, :value_numeric, :weight, :counts_to_avg, :comment, :assessment_id)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($gradeData);
                    $gradeId = (int)$pdo->lastInsertId();
                } else {
                    $sql = "UPDATE grades SET value_text = :value_text, value_numeric = :value_numeric, comment = :comment
                            WHERE id = :id AND teacher_id = :tid";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':value_text' => $valueText, ':value_numeric' => grade_to_numeric($valueText), ':comment' => $comment, ':id' => $gradeId, ':tid' => $teacherId]);
                }
                $grade = ['id' => $gradeId, 'value_text' => $valueText];
            }
            
            $newAvg = compute_avg_api($pdo, $studentId, (int)($input['subject_id'] ?? 0), strlen($input['term_id'] ?? '') ? (int)$input['term_id'] : null);
            echo json_encode(['ok' => true, 'grade' => $grade, 'avg' => $newAvg]);
            break;

        case 'grade_improve':
            $gradeId = (int)($input['grade_id'] ?? 0);
            $valueText = trim($input['value_text'] ?? '');
            if (!$gradeId || $valueText === '') throw new Exception('Brakuje danych.');
            
            $oldGrade = $pdo->prepare("SELECT * FROM grades WHERE id = :id AND teacher_id = :tid");
            $oldGrade->execute([':id' => $gradeId, ':tid' => $teacherId]);
            $oldGrade = $oldGrade->fetch(PDO::FETCH_ASSOC);
            if (!$oldGrade) throw new Exception('Nie znaleziono oceny lub brak uprawnień.');

            $sql = "INSERT INTO grades (student_id, subject_id, teacher_id, term_id, category_id, kind, value_text, value_numeric, weight, counts_to_avg, improved_of_id)
                    VALUES (:student_id, :subject_id, :teacher_id, :term_id, :category_id, 'regular', :value_text, :value_numeric, :weight, :counts_to_avg, :improved_of_id)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id' => $oldGrade['student_id'],
                ':subject_id' => $oldGrade['subject_id'],
                ':teacher_id' => $teacherId,
                ':term_id' => $oldGrade['term_id'],
                ':category_id' => $oldGrade['category_id'],
                ':value_text' => $valueText,
                ':value_numeric' => grade_to_numeric($valueText),
                ':weight' => $oldGrade['weight'],
                ':counts_to_avg' => $oldGrade['counts_to_avg'],
                ':improved_of_id' => $gradeId
            ]);
            $newGradeId = (int)$pdo->lastInsertId();
            
            $newAvg = compute_avg_api($pdo, (int)$oldGrade['student_id'], (int)$oldGrade['subject_id'], (int)$oldGrade['term_id']);
            echo json_encode(['ok' => true, 'grade' => ['id' => $newGradeId, 'value_text' => $valueText, 'improved_of_id' => $gradeId], 'avg' => $newAvg]);
            break;

        case 'grade_delete':
            $gradeId = (int)($input['grade_id'] ?? 0);
            if (!$gradeId) throw new Exception('Brak identyfikatora oceny.');

            $grade = $pdo->prepare("SELECT * FROM grades WHERE id = :id AND teacher_id = :tid");
            $grade->execute([':id' => $gradeId, ':tid' => $teacherId]);
            $grade = $grade->fetch(PDO::FETCH_ASSOC);
            if (!$grade) throw new Exception('Nie znaleziono oceny lub brak uprawnień.');

            $pdo->prepare("DELETE FROM grades WHERE id = :id")->execute([':id' => $gradeId]);
            $newAvg = compute_avg_api($pdo, (int)$grade['student_id'], (int)$grade['subject_id'], (int)$grade['term_id']);
            echo json_encode(['ok' => true, 'avg' => $newAvg]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Nieznane działanie.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}