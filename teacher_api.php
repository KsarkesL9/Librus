<?php
// teacher_api.php - Wersja z nową logiką biznesową (poprawa tylko raz, usuwanie parami)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$me = current_user();
if (!$me || !in_array('nauczyciel', $me['roles'] ?? [])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień. Zaloguj się ponownie.']);
    exit;
}
if (!verify_csrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Błędny token CSRF. Odśwież stronę i spróbuj ponownie.']);
    exit;
}

$teacherId = (int)$me['id'];
$action = $_POST['action'] ?? '';
$input = $_POST;

function grade_to_numeric(string $grade): ?float {
    $g = trim(strtoupper($grade));
    $map = ['6'=>6.0,'6+'=>6.0,'6-'=>5.75,'5'=>5.0,'5+'=>5.5,'5-'=>4.75,'4'=>4.0,'4+'=>4.5,'4-'=>3.75,'3'=>3.0,'3+'=>3.5,'3-'=>2.75,'2'=>2.0,'2+'=>2.5,'2-'=>1.75,'1'=>1.0,'1+'=>1.5,'+'=>null,'-'=>null,'NP'=>null,'BZ'=>null,'NB'=>null, '0' => 0.0];
    return $map[$g] ?? (is_numeric($g) ? (float)$g : null);
}

try {
    $pdo->beginTransaction();

    switch ($action) {
        
        case 'ass_add_column':
            $title = trim($input['title'] ?? '');
            if (empty($title)) throw new Exception('Tytuł kolumny jest wymagany.');
            
            $stmt = $pdo->prepare("INSERT INTO assessments (teacher_id, class_id, subject_id, term_id, title, category_id, weight, issue_date, counts_to_avg) VALUES (:tid, :cid, :sid, :termid, :title, :catid, :w, :idt, 1)");
            $stmt->execute([
                ':tid' => $teacherId,
                ':cid' => (int)$input['class_id'],
                ':sid' => (int)$input['subject_id'],
                ':termid' => empty($input['term_id']) ? null : (int)$input['term_id'],
                ':title' => $title,
                ':catid' => empty($input['category_id']) ? null : (int)$input['category_id'],
                ':w' => (float)($input['weight'] ?? 1.0),
                ':idt' => empty($input['issue_date']) ? date('Y-m-d') : $input['issue_date']
            ]);
            echo json_encode(['ok' => true, 'message' => 'Kolumna została dodana.']);
            break;

        case 'grade_add_single':
            $title = trim($input['title'] ?? '');
            $studentId = (int)($input['student_id'] ?? 0);
            $valueText = trim($input['value_text'] ?? '');

            if (empty($title) || empty($studentId) || empty($valueText)) {
                throw new Exception('Uczeń, ocena i tytuł są wymagane do dodania pojedynczej oceny.');
            }

            $stmt = $pdo->prepare("INSERT INTO assessments (teacher_id, class_id, subject_id, term_id, title, category_id, weight, issue_date, counts_to_avg) VALUES (:tid, :cid, :sid, :termid, :title, :catid, :w, CURDATE(), 1)");
            $stmt->execute([
                ':tid' => $teacherId, ':cid' => (int)$input['class_id'], ':sid' => (int)$input['subject_id'],
                ':termid' => empty($input['term_id']) ? null : (int)$input['term_id'], ':title' => $title,
                ':catid' => empty($input['category_id']) ? null : (int)$input['category_id'], ':w' => (float)($input['weight'] ?? 1.0),
            ]);
            $assId = $pdo->lastInsertId();

            $gradeStmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, term_id, category_id, assessment_id, value_text, value_numeric, weight, comment, published_at) VALUES (:studid, :subid, :tid, :termid, :catid, :assid, :vtxt, :vnum, :w, :com, NOW())");
            $gradeStmt->execute([
                ':studid' => $studentId, ':subid' => (int)$input['subject_id'], ':tid' => $teacherId,
                ':termid' => empty($input['term_id']) ? null : (int)$input['term_id'], ':catid' => empty($input['category_id']) ? null : (int)$input['category_id'],
                ':assid' => $assId, ':vtxt' => $valueText, ':vnum' => grade_to_numeric($valueText),
                ':w' => (float)($input['weight'] ?? 1.0), ':com' => trim($input['comment'] ?? '')
            ]);
            echo json_encode(['ok' => true, 'message' => 'Pojedyncza ocena została dodana.']);
            break;

        case 'grade_add_or_improve':
            $assId = (int)($input['assessment_id'] ?? 0);
            $studentId = (int)($input['student_id'] ?? 0);
            $valueText = trim($input['value_text'] ?? '');
            if (!$assId || !$studentId || $valueText === '') throw new Exception('Brak danych do dodania oceny.');

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE student_id = ? AND assessment_id = ?");
            $countStmt->execute([$studentId, $assId]);
            $currentGradeCount = $countStmt->fetchColumn();

            if ($currentGradeCount >= 2) {
                throw new Exception('Ocena została już poprawiona i nie można jej dalej modyfikować.');
            }
            
            $ass = $pdo->prepare("SELECT * FROM assessments WHERE id = ? AND teacher_id = ?");
            $ass->execute([$assId, $teacherId]);
            $assessment = $ass->fetch();
            if (!$assessment) throw new Exception('Nie znaleziono kolumny ocen lub brak uprawnień.');

            $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, term_id, category_id, assessment_id, value_text, value_numeric, weight, comment, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $studentId, $assessment['subject_id'], $teacherId, $assessment['term_id'],
                $assessment['category_id'], $assId, $valueText, grade_to_numeric($valueText),
                $assessment['weight'], trim($input['comment'] ?? '')
            ]);
            echo json_encode(['ok' => true, 'message' => 'Ocena dodana pomyślnie.']);
            break;

        case 'grade_update':
            $gradeId = (int)($input['grade_id'] ?? 0);
            $valueText = trim($input['value_text'] ?? '');
            if (!$gradeId || $valueText === '') throw new Exception('Brak danych do aktualizacji oceny.');

            $stmt = $pdo->prepare("UPDATE grades SET value_text = ?, value_numeric = ?, comment = ? WHERE id = ? AND teacher_id = ?");
            $stmt->execute([
                $valueText, grade_to_numeric($valueText), trim($input['comment'] ?? ''),
                $gradeId, $teacherId
            ]);
            echo json_encode(['ok' => true, 'message' => 'Ocena zaktualizowana.']);
            break;

        case 'grade_delete':
            $gradeId = (int)($input['grade_id'] ?? 0);
            if (!$gradeId) throw new Exception('Brak ID oceny do usunięcia.');
            
            $gradeInfoStmt = $pdo->prepare("SELECT student_id, assessment_id FROM grades WHERE id = ? AND teacher_id = ?");
            $gradeInfoStmt->execute([$gradeId, $teacherId]);
            $gradeInfo = $gradeInfoStmt->fetch();

            if ($gradeInfo) {
                $deleteStmt = $pdo->prepare("DELETE FROM grades WHERE student_id = ? AND assessment_id = ?");
                $deleteStmt->execute([$gradeInfo['student_id'], $gradeInfo['assessment_id']]);
                echo json_encode(['ok' => true, 'message' => 'Ocena (wraz z poprawą) została usunięta.']);
            } else {
                throw new Exception('Nie znaleziono oceny do usunięcia lub brak uprawnień.');
            }
            break;

        default:
            throw new Exception('Nieznana akcja: ' . sanitize($action));
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}