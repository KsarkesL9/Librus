<?php
// index.php (logowanie)
require_once __DIR__ . '/includes/functions.php';
start_secure_session();
$errors = $_SESSION['flash_errors'] ?? [];
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_old']);
include __DIR__ . '/includes/header.php';
?>
<main class="container auth-center">
  <section class="card login-card">
    <div class="logo-wrap">
      <div class="logo-circle">S</div>
      <div class="logo-text"><span class="logo-small">LIBRUS</span><span class="logo-big">Synergia</span></div>
    </div>
    <h1>Zaloguj się do systemu Synergia</h1>

    <?php if (!empty($errors)): ?>
      <div class="alert">
        <?php foreach ($errors as $e): ?>
          <p><?php echo sanitize($e); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo $BASE_URL; ?>/login.php" class="form" novalidate autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">

      <label for="login">Login</label>
      <div class="input-wrap">
        <span class="icon" aria-hidden="true">
          <!-- user icon -->
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
            <path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/>
          </svg>
        </span>
        <input id="login" name="login" required value="<?php echo sanitize($old['login'] ?? '') ?>" autocomplete="off" autocapitalize="none" spellcheck="false" />
      </div>

      <label for="password">Hasło</label>
      <div class="input-wrap">
        <span class="icon" aria-hidden="true">
          <!-- dark lock -->
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
            <path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3H9Zm3 4a2 2 0 0 1 1 3.732V18a1 1 0 1 1-2 0v-1.268A2 2 0 0 1 12 13Z"/>
          </svg>
        </span>
        <input id="password" type="password" name="password" required autocomplete="off" />
        <button type="button" class="ghost-btn showpass" aria-label="Pokaż/ukryj hasło">
          <!-- eye icon -->
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/>
          </svg>
        </button>
      </div>

      <div class="form-row between">
        <a class="link" href="<?php echo $BASE_URL; ?>/forgot.php">przypomnij hasło</a>
      </div>

      <button class="btn primary full" type="submit">ZALOGUJ</button>
    </form>

    <p class="muted mt">Nie masz konta? <a class="link" href="<?php echo $BASE_URL; ?>/register.php">Zarejestruj się</a></p>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
