<?php
// includes/header.php – wspólny nagłówek; ładuje jasny motyw app.css,
// gdy strona ustawi $APP_BODY_CLASS = 'app'.

require_once __DIR__ . '/functions.php';

$BASE_URL = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($BASE_URL === '') { $BASE_URL = '.'; }

// Badge "nowe oceny" dla ucznia
$badgeCount = 0;
$me = current_user();
if ($me && in_array('uczeń', $me['roles'] ?? [])) {
  $dbFile = __DIR__ . '/../config/db.php';
  if (file_exists($dbFile)) {
    require_once $dbFile;
    $last = $pdo->prepare("SELECT last_grades_seen_at FROM users WHERE id=:id");
    $last->execute([':id' => $me['id']]);
    $lastSeenAt = $last->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM grades
                           WHERE student_id=:sid
                             AND (published_at IS NULL OR published_at<=NOW())
                             AND created_at > COALESCE(:seen, '1970-01-01')");
    $stmt->execute([':sid'=>$me['id'], ':seen'=>$lastSeenAt]);
    $badgeCount = (int)$stmt->fetchColumn();
  }
}

$BODY_CLASS = isset($APP_BODY_CLASS) ? $APP_BODY_CLASS : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Zenith Nexus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/styles.css" />
  <?php if (strpos($BODY_CLASS, 'app') !== false): ?>
    <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/app.css" />
  <?php endif; ?>
  <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/assets/css/modal.css" />
  <script defer src="<?php echo $BASE_URL; ?>/assets/js/app.js"></script>
</head>
<body class="<?php echo htmlspecialchars($BODY_CLASS, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($me): ?>
  <div class="topbar">
    <div class="topbar-inner">
      <a class="brand" href="<?php echo $BASE_URL; ?>/dashboard.php" title="Strona główna">
        <img src="<?php echo $BASE_URL; ?>/assets/img/logo_simple.png" alt="Zenith Nexus Logo" style="height: 36px;">
        <span style="font-size: 1.2rem; font-weight: 800; color: #fff;">Zenith Nexus</span>
      </a>

      <nav class="nav-icons">
        <?php if (in_array('uczeń', $me['roles'] ?? [])): ?>
        <a class="nav-icon" href="<?php echo $BASE_URL; ?>/grades.php" title="Oceny (podgląd)">
          <span class="circle"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 2h6a2 2 0 0 1 2 2h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3-3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1a2 2 0 0 1 2-2Zm0 2a1 1 0 0 0-1 1v1h8V5a1 1 0 0 0-1-1H9Zm-3 4v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V8H6Zm3 3h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg></span>
          <span class="label">Oceny</span>
          <?php if ($badgeCount > 0): ?><span class="badge"><?php echo (int)$badgeCount; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (in_array('nauczyciel', $me['roles'] ?? [])): ?>
        <a class="nav-icon" href="<?php echo $BASE_URL; ?>/teacher.php" title="Wystawianie ocen">
          <span class="circle"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 4h8l6 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm8 1v5h5"/><path d="M8 13h8v2H8zM8 17h5v2H8z"/></svg></span>
          <span class="label">Nauczyciel</span>
        </a>
        <?php endif; ?>

        <?php if (in_array('admin', $me['roles'] ?? [])): ?>
        <a class="nav-icon" href="<?php echo $BASE_URL; ?>/admin.php" title="Panel administratora">
          <span class="circle"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94a7.49 7.49 0 0 0 .05-.94 7.49 7.49 0 0 0-.05-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.67 7.67 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.57.23-1.11.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.7 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.03.31-.05.62-.05.94 0 .32.02.63.05.94L2.82 14.5a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.51.4 1.06.71 1.63.94l.36 2.54c.06.24.26.42.5.42h3.84c.24 0 .44-.18.5-.42l.36-2.54c.57-.23 1.11-.54-1.63-.94l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.56ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/></svg></span>
          <span class="label">Admin</span>
        </a>
        <?php endif; ?>
        
        <a class="nav-icon" href="<?php echo $BASE_URL; ?>/logout.php" title="Wyloguj" id="logout-link">
          <span class="circle"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 17v-3H9v-2h7V9l4 4-4 4Zm-8-9a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2h-2V6H8v12h4v2H8a2 2 0 0 1-2-2V8Z"/></svg></span>
          <span class="label">Wyloguj</span>
        </a>
      </nav>
    </div>
  </div>
<?php endif; ?>