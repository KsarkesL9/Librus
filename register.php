<?php
// register.php – rejestracja z rozbitym adresem; "Kraj" zawsze Polska (readonly)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

start_secure_session();
$errors = [];
$success = false;

$VOIVODESHIPS = [
  'dolnośląskie','kujawsko-pomorskie','lubelskie','lubuskie','łódzkie',
  'małopolskie','mazowieckie','opolskie','podkarpackie','podlaskie',
  'pomorskie','śląskie','świętokrzyskie','warmińsko-mazurskie','wielkopolskie','zachodniopomorskie'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) { $errors[] = 'Nieprawidłowy token formularza.'; }

    $login = trim($_POST['login'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');

    // W tym formularzu kraj jest zawsze Polska (readonly w UI)
    $country = 'Polska';

    $voiv = mb_strtolower(trim($_POST['voivodeship'] ?? ''), 'UTF-8');
    $city = trim($_POST['city'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $building_no = strtoupper(trim($_POST['building_no'] ?? ''));
    $apartment_no = strtoupper(trim($_POST['apartment_no'] ?? ''));

    $pesel = preg_replace('/\D+/', '', $_POST['pesel'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $role = trim($_POST['role'] ?? 'uczeń');

    if ($login === '' || $email === '' || $first_name === '' || $last_name === '' ||
        $country === '' || $voiv === '' || $city === '' || $street === '' || $building_no === '' ||
        $pesel === '' || $password === '') {
        $errors[] = 'Wszystkie wymagane pola muszą być wypełnione.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Podaj poprawny adres email.'; }
    if (strlen($login) < 3) { $errors[] = 'Login musi mieć przynajmniej 3 znaki.'; }
    if (!preg_match('/^\d{11}$/', $pesel)) { $errors[] = 'PESEL musi mieć 11 cyfr.'; }
    if (strlen($password) < 8) { $errors[] = 'Hasło musi mieć co najmniej 8 znaków.'; }
    if ($password !== $password2) { $errors[] = 'Hasła nie są identyczne.'; }

    if ($city !== '' && !preg_match('/^[\p{L}\s\.\-\'`]{2,}$/u', $city)) {
        $errors[] = 'Miasto: użyj liter/spacji/myślników (min. 2 znaki).';
    }
    if ($street !== '' && !preg_match('/^[0-9\p{L}\s\.\-\/\'`]{2,}$/u', $street)) {
        $errors[] = 'Ulica: użyj liter/cyfr/spacji (min. 2 znaki).';
    }
    if ($building_no !== '' && !preg_match('/^\d{1,4}[A-Z]?$/', $building_no)) {
        $errors[] = 'Numer budynku: wpisz np. 12 lub 12A.';
    }
    if ($apartment_no !== '' && !preg_match('/^\d{1,4}[A-Z]?$/', $apartment_no)) {
        $errors[] = 'Numer lokalu: wpisz np. 7 lub 7A (albo zostaw puste).';
    }
    if ($voiv !== '' && !in_array($voiv, $VOIVODESHIPS, true)) {
        $errors[] = 'Wybierz województwo z listy.';
    }

    $role_stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
    $role_stmt->execute([':name' => $role]);
    $role_row = $role_stmt->fetch();
    if (!$role_row) { $errors[] = 'Wybrana rola nie istnieje.'; }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE login = :login OR email = :email OR pesel = :pesel LIMIT 1');
            $stmt->execute([':login' => $login, ':email' => $email, ':pesel' => $pesel]);
            if ($stmt->fetch()) {
                $errors[] = 'Podany login/email/PESEL już istnieje.';
            } else {
                $pdo->beginTransaction();
                $ph = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('
                  INSERT INTO users
                  (login, email, password_hash, first_name, last_name,
                   country, voivodeship, city, street, building_no, apartment_no,
                   pesel)
                  VALUES
                  (:login, :email, :ph, :fn, :ln,
                   :country, :voiv, :city, :street, :bno, :ano,
                   :pesel)
                ');
                $ins->execute([
                    ':login' => $login,
                    ':email' => $email,
                    ':ph' => $ph,
                    ':fn' => $first_name,
                    ':ln' => $last_name,
                    ':country' => $country,          // zawsze "Polska" z formularza
                    ':voiv' => $voiv,
                    ':city' => $city,
                    ':street' => $street,
                    ':bno' => $building_no,
                    ':ano' => ($apartment_no !== '' ? $apartment_no : null),
                    ':pesel' => $pesel
                ]);
                $uid = (int)$pdo->lastInsertId();

                $ur = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
                $ur->execute([':uid' => $uid, ':rid' => (int)$role_row['id']]);

                $pdo->commit();
                $success = true;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Wystąpił błąd podczas rejestracji.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<main class="container auth-center">
  <section class="card login-card">
    <div class="logo-wrap">
      <img src="<?php echo $BASE_URL; ?>/assets/img/logo_full.png" alt="Zenith Nexus Logo" style="height: 80px;">
    </div>
    <h1>Rejestracja użytkownika</h1>

    <?php if (!empty($errors)): ?>
      <div class="alert">
        <?php foreach ($errors as $e): ?><p><?php echo sanitize($e); ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="success">Konto zostało utworzone. Możesz się teraz <a class="link" href="<?php echo $BASE_URL; ?>/index.php">zalogować</a>.</div>
    <?php else: ?>
    <form method="POST" class="form" novalidate autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">

      <div class="grid two">
        <div>
          <label for="login">Login</label>
          <div class="input-wrap">
            <span class="icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 12c2.76 0 5-2.24 5-5S14.76 2 12 2 7 4.24 7 7s2.24 5 5 5Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/></svg>
            </span>
            <input id="login" name="login" required value="<?php echo sanitize($_POST['login'] ?? '') ?>" autocomplete="off" autocapitalize="none" spellcheck="false"/>
          </div>
        </div>

        <div>
          <label for="email">Email</label>
          <div class="input-wrap">
            <span class="icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm-1.4 3L12 11.25 5.4 7h13.2ZM20 18H4V8.5l8 5 8-5V18Z"/></svg>
            </span>
            <input id="email" type="email" name="email" required value="<?php echo sanitize($_POST['email'] ?? '') ?>" autocomplete="off"/>
          </div>
        </div>
      </div>

      <div class="grid two">
        <div>
          <label for="first_name">Imię</label>
          <div class="input-wrap">
            <input id="first_name" name="first_name" required value="<?php echo sanitize($_POST['first_name'] ?? '') ?>" autocomplete="off"/>
          </div>
        </div>
        <div>
          <label for="last_name">Nazwisko</label>
          <div class="input-wrap">
            <input id="last_name" name="last_name" required value="<?php echo sanitize($_POST['last_name'] ?? '') ?>" autocomplete="off"/>
          </div>
        </div>
      </div>

      <!-- Adres -->
      <div class="grid two">
        <div>
          <label for="country">Kraj</label>
          <!-- całe pole wyszarzone, bez efektu focus; wartość wysyłana, bo readonly (nie disabled) -->
          <div class="input-wrap readonly-wrap">
            <input id="country" name="country" value="Polska" readonly
                   class="readonly-fixed" aria-readonly="true" />
          </div>
          <small class="muted">Pole stałe w formularzu – zawsze „Polska”.</small>
        </div>
        <div>
          <label for="voivodeship">Województwo</label>
          <div class="input-wrap">
            <select id="voivodeship" name="voivodeship" required>
              <?php
                $chosenVoiv = mb_strtolower($_POST['voivodeship'] ?? 'mazowieckie', 'UTF-8');
                foreach ($VOIVODESHIPS as $v) {
                  $sel = ($chosenVoiv === $v) ? 'selected' : '';
                  echo "<option value=\"{$v}\" {$sel}>$v</option>";
                }
              ?>
            </select>
          </div>
        </div>
      </div>

      <label for="city">Miasto</label>
      <div class="input-wrap">
        <input id="city" name="city" required value="<?php echo sanitize($_POST['city'] ?? '') ?>" placeholder="np. Warszawa"/>
      </div>

      <label for="street">Ulica</label>
      <div class="input-wrap">
        <input id="street" name="street" required value="<?php echo sanitize($_POST['street'] ?? '') ?>" placeholder="np. Jana Pawła II"/>
      </div>

      <div class="grid two">
        <div>
          <label for="building_no">Numer budynku</label>
          <div class="input-wrap">
            <input id="building_no" name="building_no" required value="<?php echo sanitize($_POST['building_no'] ?? '') ?>"
                   pattern="\d{1,4}[A-Za-z]?" title="1–4 cyfry + opcjonalna litera, np. 12 lub 12A" placeholder="np. 12A"/>
          </div>
        </div>
        <div>
          <label for="apartment_no">Numer lokalu (opcjonalnie)</label>
          <div class="input-wrap">
            <input id="apartment_no" name="apartment_no" value="<?php echo sanitize($_POST['apartment_no'] ?? '') ?>"
                   pattern="\d{1,4}[A-Za-z]?" title="1–4 cyfry + opcjonalna litera, np. 7 lub 7A" placeholder="np. 7"/>
          </div>
        </div>
      </div>

      <div class="grid two">
        <div>
          <label for="pesel">PESEL</label>
          <div class="input-wrap">
            <input id="pesel" name="pesel" pattern="\d{11}" title="11 cyfr" required value="<?php echo sanitize($_POST['pesel'] ?? '') ?>" autocomplete="off"/>
          </div>
        </div>
        <div>
          <label for="role">Rola w systemie</label>
          <div class="input-wrap">
            <select id="role" name="role" required autocomplete="off">
              <?php
                $roles = $pdo->query('SELECT name FROM roles ORDER BY id')->fetchAll();
                $chosen = $_POST['role'] ?? 'uczeń';
                foreach ($roles as $r) {
                  $n = $r['name']; $sel = ($n === $chosen) ? 'selected' : '';
                  echo "<option value=\"{$n}\" {$sel}>{$n}</option>";
                }
              ?>
            </select>
          </div>
        </div>
      </div>

      <div class="grid two">
        <div>
          <label for="password">Hasło</label>
          <div class="input-wrap">
            <span class="icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3H9Zm3 4a2 2 0 0 1 1 3.732V18a1 1 0 1 1-2 0v-1.268A2 2 0 0 1 12 13Z"/></svg>
            </span>
            <input id="password" type="password" name="password" required autocomplete="off"/>
            <button type="button" class="ghost-btn showpass" aria-label="Pokaż/ukryj hasło">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/></svg>
            </button>
          </div>
          <small class="muted">Min. 8 znaków</small>
        </div>

        <div>
          <label for="password2">Powtórz hasło</label>
          <div class="input-wrap">
            <span class="icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Z"/></svg>
            </span>
            <input id="password2" type="password" name="password2" required autocomplete="off"/>
          </div>
        </div>
      </div>

      <button class="btn primary full" type="submit">Utwórz konto</button>
      <p class="muted mt">Masz już konto? <a class="link" href="<?php echo $BASE_URL; ?>/index.php">Zaloguj się</a></p>
    </form>
    <?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
