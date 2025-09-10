<?php
// teacher_api.php — endpoint AJAX modułu nauczyciela
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$user = current_user();
if (!$user || !in_array('nauczyciel', $user['roles'] ?? [])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Brak uprawnień (nauczyciel).']); exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Błędny token CSRF.']); exit;
}

/* ===== helpers ===== */

function grade_text_to_numeric(string $txt): ?float {
  $t = trim(str_replace(',', '.', $txt));
  if ($t === '') return null;
  if ($t === '+') return 0.5;
  if ($t === '-') return -0.25;

  if (!preg_match('/^(0|[1-6])([+-])?$/u', $t, $m)) return null;
  $base = (int)$m[1];
  $sign = $m[2] ?? '';

  if ($base === 0 && $sign) return null;       // brak 0+ / 0-
  if ($base === 1 && $sign === '-') return null; // brak 1-
  $delta = 0.0;
  if     ($sign === '+') $delta = 0.5;
  elseif ($sign === '-') $delta = -0.25;

  $val = $base + $delta;
  if ($val < 0) $val = 0;
  if ($val > 6) $val = 6;
  return $val;
}
function numeric_to_display(string $txt, ?string $old=null): string {
  // jeśli poprawa – pokaż "nowa (stara→nowa)"
  if ($old !== null && $old !== '') {
    return htmlspecialchars($txt,ENT_QUOTES,'UTF-8') . ' <span class="small-muted">('.
           htmlspecialchars($old,ENT_QUOTES,'UTF-8').'→'.
           htmlspecialchars($txt,ENT_QUOTES,'UTF-8').')</span>';
  }
  return htmlspecialchars($txt,ENT_QUOTES,'UTF-8');
}

function is_teacher_of(PDO $pdo, int $teacherId, int $classId, int $subjectId): bool {
  $s = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id=:t AND class_id=:c AND subject_id=:s LIMIT 1");
  $s->execute([':t'=>$teacherId, ':c'=>$classId, ':s'=>$subjectId]);
  return (bool)$s->fetchColumn();
}
function compute_avg(PDO $pdo, int $studentId, int $subjectId, ?int $termId): string {
  $sql = "SELECT value_numeric, weight
          FROM grades
          WHERE student_id=:st AND subject_id=:sub AND kind='regular'
            AND counts_to_avg=1 ".($termId ? " AND term_id=:term " : '');
  $st = $pdo->prepare($sql);
  $p = [':st'=>$studentId, ':sub'=>$subjectId];
  if ($termId) $p[':term']=$termId;
  $st->execute($p);
  $sum=0; $w=0;
  foreach ($st as $r){
    $vn = (float)$r['value_numeric']; $ww = (float)$r['weight'];
    $sum += $vn*$ww; $w += $ww;
  }
  if ($w <= 0) return '—';
  return number_format($sum/$w, 2, ',', '');
}

/* ===== actions ===== */

$action = $_POST['action'] ?? '';

try {

  if ($action === 'ass_add') {
    $class_id   = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $term_id    = isset($_POST['term_id']) && $_POST['term_id'] !== '' ? (int)$_POST['term_id'] : null;
    $title      = trim($_POST['title'] ?? '');
    $category_id= $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $weight     = max(0.01, (float)($_POST['weight'] ?? 1));
    $counts     = ($_POST['counts_to_avg'] ?? '1') === '1' ? 1 : 0;
    $issue_date = $_POST['issue_date'] ?: date('Y-m-d');
    $color      = $_POST['color'] ?: null;

    if (!$class_id || !$subject_id || $title==='') throw new Exception('Uzupełnij dane kolumny.');
    if (!is_teacher_of($pdo, (int)$user['id'], $class_id, $subject_id)) throw new Exception('Nie uczysz tego przedmiotu w tej klasie.');

    $pdo->prepare("INSERT INTO assessments
        (teacher_id,class_id,subject_id,term_id,title,category_id,weight,counts_to_avg,color,issue_date)
        VALUES (:t,:c,:s,:term,:title,:cat,:w,:cnt,:col,:dt)")
        ->execute([
          ':t'=>$user['id'], ':c'=>$class_id, ':s'=>$subject_id, ':term'=>$term_id,
          ':title'=>$title, ':cat'=>$category_id ?: null, ':w'=>$weight, ':cnt'=>$counts,
          ':col'=>$color, ':dt'=>$issue_date
        ]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'ass_del') {
    $id = (int)($_POST['assessment_id'] ?? 0);
    if (!$id) throw new Exception('Brak ID kolumny.');
    // weryfikacja właściciela
    $own = $pdo->prepare("SELECT teacher_id FROM assessments WHERE id=:id");
    $own->execute([':id'=>$id]);
    $t = $own->fetchColumn();
    if (!$t || (int)$t !== (int)$user['id']) throw new Exception('Nie możesz usunąć tej kolumny.');
    $pdo->prepare("DELETE FROM assessments WHERE id=:id")->execute([':id'=>$id]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'grade_set') {
    $ass_id = (int)($_POST['assessment_id'] ?? 0);
    $st_id  = (int)($_POST['student_id'] ?? 0);
    $gid    = $_POST['grade_id'] ? (int)$_POST['grade_id'] : null;
    $valtxt = trim($_POST['value_text'] ?? '');
    $comm   = trim($_POST['comment'] ?? '');

    if (!$ass_id || !$st_id) throw new Exception('Brak danych oceny.');
    $ass = $pdo->prepare("SELECT * FROM assessments WHERE id=:id");
    $ass->execute([':id'=>$ass_id]);
    $A = $ass->fetch();
    if (!$A) throw new Exception('Kolumna nie istnieje.');
    if ((int)$A['teacher_id'] !== (int)$user['id']) throw new Exception('Brak uprawnień.');

    $num = grade_text_to_numeric($valtxt);
    if ($valtxt !== '' && $num === null) throw new Exception('Nieprawidłowa wartość (dopuszczalne: 0..6, +, -, 3+, 4-, bez 0+/0-, bez 1-).');

    if ($gid) {
      $pdo->prepare("UPDATE grades SET value_text=:t, value_numeric=:n, comment=:c, updated_at=NOW()
                     WHERE id=:id")->execute([':t'=>$valtxt, ':n'=>$num, ':c'=>$comm, ':id'=>$gid]);
      $id = $gid;
    } else {
      $pdo->prepare("INSERT INTO grades
        (student_id,subject_id,class_id,term_id,teacher_id,category_id,weight,counts_to_avg,color,created_at,comment,kind,assessment_id,value_text,value_numeric,published_at)
        VALUES (:st,:sub,:cls,:term,:t,:cat,:w,:cnt,:col,NOW(),:comm,'regular',:ass,:txt,:num,NOW())")
        ->execute([
          ':st'=>$st_id, ':sub'=>$A['subject_id'], ':cls'=>$A['class_id'], ':term'=>$A['term_id'],
          ':t'=>$A['teacher_id'], ':cat'=>$A['category_id'], ':w'=>$A['weight'], ':cnt'=>$A['counts_to_avg'],
          ':col'=>$A['color'], ':comm'=>$comm, ':ass'=>$ass_id, ':txt'=>$valtxt, ':num'=>$num
        ]);
      $id = (int)$pdo->lastInsertId();
    }

    $avg = compute_avg($pdo, $st_id, (int)$A['subject_id'], $A['term_id'] ? (int)$A['term_id'] : null);
    echo json_encode([
      'ok'=>true,
      'grade'=>[
        'id'=>$id,
        'display_html'=>$valtxt!=='' ? '<span class="pill">'.htmlspecialchars($valtxt,ENT_QUOTES,'UTF-8').'</span>' : '<span class="small-muted">—</span>'
      ],
      'avg'=>$avg
    ]); exit;
  }

  if ($action === 'grade_improve') {
    $gid    = (int)($_POST['grade_id'] ?? 0);
    $valtxt = trim($_POST['value_text'] ?? '');
    if (!$gid || $valtxt==='') throw new Exception('Brak danych.');

    $g = $pdo->prepare("SELECT * FROM grades WHERE id=:id");
    $g->execute([':id'=>$gid]); $G = $g->fetch();
    if (!$G) throw new Exception('Ocena nie istnieje.');
    // sprawdź właściciela kolumny / nauczyciela
    if ((int)$G['teacher_id'] !== (int)$user['id']) throw new Exception('Brak uprawnień.');

    $num = grade_text_to_numeric($valtxt);
    if ($num === null) throw new Exception('Nieprawidłowa wartość.');

    // wyłącz starą z średniej
    $pdo->prepare("UPDATE grades SET counts_to_avg=0, updated_at=NOW() WHERE id=:id")->execute([':id'=>$gid]);

    // dodaj nową, wiążąc z poprzednią
    $pdo->prepare("INSERT INTO grades
      (student_id,subject_id,class_id,term_id,teacher_id,category_id,weight,counts_to_avg,color,created_at,comment,kind,assessment_id,value_text,value_numeric,improved_of_id,published_at)
      VALUES (:st,:sub,:cls,:term,:t,:cat,:w,1,:col,NOW(),:comm,'regular',:ass,:txt,:num,:old,NOW())")
      ->execute([
        ':st'=>$G['student_id'], ':sub'=>$G['subject_id'], ':cls'=>$G['class_id'], ':term'=>$G['term_id'],
        ':t'=>$G['teacher_id'], ':cat'=>$G['category_id'], ':w'=>$G['weight'], ':col'=>$G['color'],
        ':comm'=>'Poprawa z '.$G['value_text'], ':ass'=>$G['assessment_id'],
        ':txt'=>$valtxt, ':num'=>$num, ':old'=>$gid
      ]);
    $newId = (int)$pdo->lastInsertId();

    $avg = compute_avg($pdo, (int)$G['student_id'], (int)$G['subject_id'], $G['term_id'] ? (int)$G['term_id'] : null);
    echo json_encode([
      'ok'=>true,
      'grade'=>[
        'id'=>$newId,
        'display_html'=>'<span class="pill impr">'.htmlspecialchars($valtxt,ENT_QUOTES,'UTF-8').' <span class="small-muted">('.
                        htmlspecialchars($G['value_text'],ENT_QUOTES,'UTF-8').'→'.
                        htmlspecialchars($valtxt,ENT_QUOTES,'UTF-8').')</span></span>'
      ],
      'avg'=>$avg
    ]); exit;
  }

  if ($action === 'grade_delete') {
    $gid = (int)($_POST['grade_id'] ?? 0);
    if (!$gid) throw new Exception('Brak ID.');
    $g = $pdo->prepare("SELECT * FROM grades WHERE id=:id");
    $g->execute([':id'=>$gid]); $G=$g->fetch();
    if (!$G) throw new Exception('Nie istnieje.');
    if ((int)$G['teacher_id'] !== (int)$user['id']) throw new Exception('Brak uprawnień.');
    $pdo->prepare("DELETE FROM grades WHERE id=:id")->execute([':id'=>$gid]);
    $avg = compute_avg($pdo, (int)$G['student_id'], (int)$G['subject_id'], $G['term_id'] ? (int)$G['term_id'] : null);
    echo json_encode(['ok'=>true,'avg'=>$avg]); exit;
  }

  if ($action === 'summary_set') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $class_id   = (int)($_POST['class_id'] ?? 0);
    $term_id    = isset($_POST['term_id']) && $_POST['term_id']!=='' ? (int)$_POST['term_id'] : null;
    $kind       = $_POST['kind'] ?? '';
    $valtxt     = trim($_POST['value_text'] ?? '');

    if (!$student_id || !$subject_id || !$class_id) throw new Exception('Brak danych.');
    // weryfikuj, że to jego przedmiot/klasa
    if (!is_teacher_of($pdo, (int)$user['id'], $class_id, $subject_id)) throw new Exception('Brak uprawnień.');

    $allowed = ['midterm_proposed','midterm','final_proposed','final'];
    if (!in_array($kind,$allowed,true)) throw new Exception('Zły typ oceny.');
    if ($valtxt!=='') {
      $num = grade_text_to_numeric($valtxt);
      if ($num === null) throw new Exception('Nieprawidłowa wartość.');
    } else { $num = null; }

    // usuń poprzednią, zostaw najnowszą
    $pdo->prepare("DELETE FROM grades
                   WHERE student_id=:st AND subject_id=:sub AND class_id=:cls
                     AND kind=:k ".($term_id?" AND term_id=:term ":"")."
                   ")->execute([':st'=>$student_id, ':sub'=>$subject_id, ':cls'=>$class_id, ':k'=>$kind, ':term'=>$term_id]);

    if ($valtxt!=='') {
      $pdo->prepare("INSERT INTO grades
        (student_id,subject_id,class_id,term_id,teacher_id,category_id,weight,counts_to_avg,color,created_at,comment,kind,assessment_id,value_text,value_numeric,published_at)
        VALUES (:st,:sub,:cls,:term,:t,NULL,1,0,NULL,NOW(),NULL,:k,NULL,:txt,:num,NOW())")
        ->execute([':st'=>$student_id, ':sub'=>$subject_id, ':cls'=>$class_id, ':term'=>$term_id,
                   ':t'=>$user['id'], ':k'=>$kind, ':txt'=>$valtxt, ':num'=>$num]);
    }

    echo json_encode(['ok'=>true]); exit;
  }

  throw new Exception('Nieznana akcja');

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
