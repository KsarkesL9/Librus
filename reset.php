<?php
// reset.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

start_secure_session();
$errors = [];
$done = false;
$uid = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) {
        $errors[] = 'Nieprawidłowy token formularza.';
    }
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if ($pass1 !== $pass2 || strlen($pass1) < 8) {
        $errors[] = 'Hasła muszą być identyczne i mieć min. 8 znaków.';
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT token_hash, expires_at FROM password_resets WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch();
        if (!$row) {
            $errors[] = 'Nieprawidłowy lub wygasły token.';
        } else {
            if (new DateTime($row['expires_at']) < new DateTime()) {
                $errors[] = 'Token wygasł.';
            } elseif (!hash_equals($row['token_hash'], hash('sha256', $token))) {
                $errors[] = 'Nieprawidłowy token.';
            } else {
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = :ph WHERE id = :uid')->execute([':ph' => $hash, ':uid' => $uid]);
                $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid')->execute([':uid' => $uid]);
                $done = true;
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<main class="container auth-center">
  <section class="card login-card">
    <h1>Ustaw nowe hasło</h1>
    <?php if (!empty($errors)): ?>
      <div class="alert">
        <?php foreach ($errors as $e): ?>
          <p><?php echo sanitize($e); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="success">Hasło zmienione. <a class="link" href="<?php echo $BASE_URL; ?>/index.php">Zaloguj się</a>.</div>
    <?php else: ?>
      <form method="POST" class="form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="uid" value="<?php echo (int)$uid; ?>">
        <input type="hidden" name="token" value="<?php echo sanitize($token); ?>">
        <label for="password">Nowe hasło</label>
        <input id="password" type="password" name="password" required autocomplete="off" />
        <label for="password2">Powtórz hasło</label>
        <input id="password2" type="password" name="password2" required autocomplete="off" />
        <button class="btn primary full" type="submit">Zmień hasło</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
