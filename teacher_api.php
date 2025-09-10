<?php
// teacher_api.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['teacher_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']);
    exit;
}
$teacherId = (int)$_SESSION['teacher_id'];

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'add':
            // oczekujemy POST
            $title = trim($_POST['title'] ?? '');
            $category_id = strlen($_POST['category_id'] ?? '') ? (int)$_POST['category_id'] : null;
            $weight = isset($_POST['weight']) ? (int)$_POST['weight'] : 1;
            $counts = isset($_POST['counts_to_avg']) ? (int)$_POST['counts_to_avg'] : 1;
            $issue_date = $_POST['issue_date'] ?? date('Y-m-d');

            if ($title === '') {
                throw new Exception('Brak tytułu.');
            }

            $stmt = $pdo->prepare("INSERT INTO assessments
                (teacher_id, class_id, subject_id, term_id, title, category_id, weight, counts_to_avg, issue_date)
                VALUES (:teacher_id, NULL, NULL, NULL, :title, :category_id, :weight, :counts, :issue_date)
            ");
            $stmt->execute([
                ':teacher_id' => $teacherId,
                ':title' => $title,
                ':category_id' => $category_id,
                ':weight' => $weight,
                ':counts' => $counts,
                ':issue_date' => $issue_date
            ]);
            $insertId = (int)$pdo->lastInsertId();

            // zwróć nowy wiersz (prosty)
            $rowStmt = $pdo->prepare("SELECT a.*, gc.name AS cat_name FROM assessments a LEFT JOIN grade_categories gc ON a.category_id = gc.id WHERE a.id = :id");
            $rowStmt->execute([':id' => $insertId]);
            $newRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'row' => $newRow]);
            break;

        case 'edit':
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$id) throw new Exception('Brak id.');
            // pobierz żeby sprawdzić właściciela
            $check = $pdo->prepare("SELECT teacher_id FROM assessments WHERE id = :id");
            $check->execute([':id' => $id]);
            $owner = $check->fetchColumn();
            if (!$owner || (int)$owner !== $teacherId) {
                http_response_code(403);
                throw new Exception('Brak uprawnień do edycji.');
            }

            $title = trim($_POST['title'] ?? '');
            $category_id = strlen($_POST['category_id'] ?? '') ? (int)$_POST['category_id'] : null;
            $weight = isset($_POST['weight']) ? (int)$_POST['weight'] : 1;
            $counts = isset($_POST['counts_to_avg']) ? (int)$_POST['counts_to_avg'] : 1;
            $issue_date = $_POST['issue_date'] ?? date('Y-m-d');

            if ($title === '') {
                throw new Exception('Brak tytułu.');
            }

            $upd = $pdo->prepare("UPDATE assessments SET title = :title, category_id = :category_id, weight = :weight, counts_to_avg = :counts, issue_date = :issue_date WHERE id = :id");
            $upd->execute([
                ':title' => $title,
                ':category_id' => $category_id,
                ':weight' => $weight,
                ':counts' => $counts,
                ':issue_date' => $issue_date,
                ':id' => $id
            ]);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$id) throw new Exception('Brak id.');
            // sprawdź właściciela
            $check = $pdo->prepare("SELECT teacher_id FROM assessments WHERE id = :id");
            $check->execute([':id' => $id]);
            $owner = $check->fetchColumn();
            if (!$owner || (int)$owner !== $teacherId) {
                http_response_code(403);
                throw new Exception('Brak uprawnień do usunięcia.');
            }

            $del = $pdo->prepare("DELETE FROM assessments WHERE id = :id");
            $del->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
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
