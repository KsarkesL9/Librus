<?php
// admin_api.php — endpoint AJAX dla panelu admina (DnD zapisów)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
$me = current_user();
if (!$me || !in_array('admin', $me['roles'] ?? [])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Brak uprawnień.']); exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Błędny token CSRF.']); exit;
}

$action = $_POST['action'] ?? '';
try {
  if ($action === 'enroll') {
    $class_id   = (int)($_POST['class_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);
    if ($class_id<=0 || $student_id<=0) throw new Exception('Brak danych.');

    // czy to uczeń?
    $chk = $pdo->prepare("SELECT u.first_name, u.last_name, u.login
                          FROM users u
                          JOIN user_roles ur ON ur.user_id=u.id
                          JOIN roles r ON r.id=ur.role_id
                          WHERE u.id=:id AND r.name='uczeń' LIMIT 1");
    $chk->execute([':id'=>$student_id]);
    $st = $chk->fetch();
    if (!$st) throw new Exception('Użytkownik nie jest uczniem.');

    // czy już gdzieś zapisany?
    $exists = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=:s LIMIT 1");
    $exists->execute([':s'=>$student_id]);
    if ($exists->fetch()) throw new Exception('Uczeń jest już w klasie.');

    // dodaj
    $pdo->prepare("INSERT INTO enrollments (student_id, class_id, enrolled_at)
                   VALUES (:s,:c,CURDATE())")->execute([':s'=>$student_id, ':c'=>$class_id]);
    $enr_id = (int)$pdo->lastInsertId();

    echo json_encode([
      'ok'=>true,
      'enrollment'=>[
        'enr_id'=>$enr_id,
        'student_id'=>$student_id,
        'first_name'=>$st['first_name'],
        'last_name'=>$st['last_name'],
        'login'=>$st['login']
      ]
    ]); exit;
  }

  if ($action === 'unenroll') {
    $enr_id = (int)($_POST['enr_id'] ?? 0);
    if ($enr_id<=0) throw new Exception('Brak ID zapisu.');

    // Pobierz studenta, by zwrócić jego dane do odtworzenia na liście „bez klasy”
    $row = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.login
                          FROM enrollments e JOIN users u ON u.id=e.student_id
                          WHERE e.id=:id");
    $row->execute([':id'=>$enr_id]);
    $st = $row->fetch();
    if (!$st) throw new Exception('Nie znaleziono zapisu.');

    $pdo->prepare("DELETE FROM enrollments WHERE id=:id")->execute([':id'=>$enr_id]);

    echo json_encode([
      'ok'=>true,
      'student'=>[
        'id'=>(int)$st['id'],
        'first_name'=>$st['first_name'],
        'last_name'=>$st['last_name'],
        'login'=>$st['login']
      ]
    ]); exit;
  }

  throw new Exception('Nieznana akcja.');
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
