<?php
// forgot.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

start_secure_session();
$info = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) {
        $errors[] = 'Nieprawidłowy token formularza.';
    }
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podaj poprawny email.';
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $u = $stmt->fetch();
        if ($u) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $exp = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

            $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid')->execute([':uid' => $u['id']]);
            $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :th, :exp)');
            $ins->execute([':uid' => $u['id'], ':th' => $hash, ':exp' => $exp]);

            $base = rtrim(str_replace('\\','/', dirname($_SERVER['PHP_SELF'])), '/');
            if ($base === '') { $base = '.'; }
            $link = $base . '/reset.php?token=' . urlencode($token) . '&uid=' . urlencode($u['id']);
            $info = 'Link do resetu hasła (demo): <a class="link" href="' . sanitize($link) . '">resetuj hasło</a>. Link ważny 1 godzinę.';
        } else {
            $info = 'Jeśli email istnieje w systemie, wyślemy instrukcje resetu.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<main class="container auth-center">
  <section class="card login-card">
    <h1>Przypomnienie hasła</h1>
    <?php if (!empty($errors)): ?>
      <div class="alert">
        <?php foreach ($errors as $e): ?>
          <p><?php echo sanitize($e); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($info): ?>
      <div class="success"><?php echo $info; ?></div>
    <?php endif; ?>

    <form method="POST" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <label for="email">Podaj email</label>
      <input id="email" type="email" name="email" required autocomplete="off" />
      <button class="btn primary full" type="submit">Wyślij link resetu</button>
      <p class="muted mt"><a class="link" href="<?php echo $BASE_URL; ?>/index.php">Powrót do logowania</a></p>
    </form>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
