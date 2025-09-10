<?php
// dashboard.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_auth();
$user = current_user();
include __DIR__ . '/includes/header.php';
?>
<main class="container">
  <section class="card">
    <h1>Witaj, <?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
    <p>Twoje role: <strong><?php echo sanitize(implode(', ', $user['roles'])); ?></strong></p>

    <div class="grid three">
      <?php if (user_has_role('uczeń')): ?>
        <div class="tile">Panel ucznia – oceny, plan lekcji (placeholder).</div>
      <?php endif; ?>
      <?php if (user_has_role('rodzic')): ?>
        <div class="tile">Panel rodzica – frekwencja, wiadomości (placeholder).</div>
      <?php endif; ?>
      <?php if (user_has_role('nauczyciel')): ?>
        <div class="tile">Panel nauczyciela – dziennik lekcyjny (placeholder).</div>
      <?php endif; ?>
      <?php if (user_has_role('dyrektor')): ?>
        <div class="tile">Panel dyrektora – raporty (placeholder).</div>
      <?php endif; ?>
      <?php if (user_has_role('admin')): ?>
        <div class="tile">Panel admina – zarządzanie użytkownikami (placeholder).</div>
      <?php endif; ?>
    </div>

    <p class="mt"><a class="btn" href="<?php echo $BASE_URL; ?>/logout.php">Wyloguj</a></p>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
