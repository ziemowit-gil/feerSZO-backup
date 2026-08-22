<?php
/**
 * komunikaty/compose.php — Publikacja nowego ogłoszenia (admin).
 *
 * Formularz w języku wizualnym modułu „Tożsamość" (_shell.php): sekcje jako
 * karty .tz-card, akcje .tz-btn, ostrzeżenia .tz-note/.pv-alert.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

if (!is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

$_cu = current_user();

$org_units = [];
try { $org_units = db_all("SELECT id, name FROM org_units ORDER BY name", []); } catch (\Throwable $e) {}
$all_users = [];
try { $all_users = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name", []); } catch (\Throwable $e) {}

$errors = [];
$values = [
    'title'        => '',
    'body'         => '',
    'audience'     => 'all',
    'kategoria'    => 'ogolne',
    'display_mode' => 'feed',
    'is_pinned'    => 0,
    'expires_at'   => '',
    'send_email'   => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values['title']     = trim($_POST['title'] ?? '');
    $values['body']      = trim($_POST['body'] ?? '');
    $values['audience']  = trim($_POST['audience'] ?? 'all');
    $values['kategoria'] = trim($_POST['kategoria'] ?? 'ogolne');
    if (!array_key_exists($values['kategoria'], ann_kategoria_options())) {
        $values['kategoria'] = 'ogolne';
    }
    $values['display_mode'] = trim($_POST['display_mode'] ?? 'feed');
    if (!in_array($values['display_mode'], ['feed', 'banner', 'popup'], true)) {
        $values['display_mode'] = 'feed';
    }
    $values['is_pinned']  = isset($_POST['is_pinned']) ? 1 : 0;
    $values['expires_at'] = trim($_POST['expires_at'] ?? '');
    $values['send_email'] = isset($_POST['send_email']) ? 1 : 0;

    if ($values['title'] === '') $errors[] = 'Tytuł jest wymagany.';
    if (mb_strlen($values['title']) > 200) $errors[] = 'Tytuł może mieć maksymalnie 200 znaków.';
    if (mb_strlen($values['body']) > 5000) $errors[] = 'Treść może mieć maksymalnie 5000 znaków.';
    if (mb_strlen($values['body']) < 1)    $errors[] = 'Treść jest wymagana.';

    $valid_audiences = ['all', 'public', 'role:admin', 'role:editor', 'role:viewer'];
    foreach ($org_units as $ou) $valid_audiences[] = 'unit:' . $ou['id'];
    foreach ($all_users as $au) $valid_audiences[] = 'user:' . $au['id'];
    if (!in_array($values['audience'], $valid_audiences, true)) {
        $errors[] = 'Nieprawidłowy odbiorca.';
    }

    if (empty($errors)) {
        $author_name = trim(($_cu['name'] ?? '')
            ?: (($_cu['first_name'] ?? '') . ' ' . ($_cu['last_name'] ?? '')));
        ann_create($values, (int)$_cu['id'], $author_name);
        flash_set('success', 'Ogłoszenie zostało opublikowane.');
        header('Location: ' . APP_URL . '/komunikaty/index.php');
        exit;
    }
}

$PAGE_TITLE = 'Nowe ogłoszenie';
require_once __DIR__ . '/_shell.php';

pv_page_header('Nowe ogłoszenie', [
    'icon' => 'bi-megaphone',
    'sub'  => 'Trafi do wybranych odbiorców w module Komunikaty',
    'back' => ['url' => APP_URL . '/komunikaty/index.php', 'label' => 'Komunikaty'],
]);
?>

<?php if (!empty($errors)): ?>
<div class="pv-alert pv-alert-danger" role="alert">
  <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
  <div>
    <strong>Nie udało się opublikować ogłoszenia:</strong>
    <ul class="mb-0 ps-3 mt-1">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<form method="post" class="kom-form">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="row g-3">
    <div class="col-lg-8">

      <section class="tz-card">
        <div class="tz-card__hd"><i class="bi bi-pencil-square" aria-hidden="true"></i>Treść</div>
        <div class="tz-card__bd">
          <div class="mb-3">
            <label for="ann-title" class="form-label">Tytuł <span class="text-danger">*</span></label>
            <input type="text" id="ann-title" name="title" class="form-control" maxlength="200" required
                   value="<?= h($values['title']) ?>" placeholder="Krótki, opisowy tytuł ogłoszenia">
            <div class="form-text text-end"><span class="kom-cnt" id="title-cnt">0</span>/200</div>
          </div>

          <div class="mb-0">
            <label for="ann-body" class="form-label">Treść <span class="text-danger">*</span></label>
            <textarea id="ann-body" name="body" class="form-control" rows="10" maxlength="5000" required
                      placeholder="Pełna treść ogłoszenia…"><?= h($values['body']) ?></textarea>
            <div class="form-text d-flex justify-content-between gap-2">
              <span>Zwykły tekst — nowe linie są zachowane.</span>
              <span><span class="kom-cnt" id="body-cnt">0</span>/5000</span>
            </div>
          </div>
        </div>
      </section>

      <section class="tz-card">
        <div class="tz-card__hd"><i class="bi bi-people" aria-hidden="true"></i>Odbiorcy i kategoria</div>
        <div class="tz-card__bd">
          <div class="row g-3">
            <div class="col-sm-6">
              <label for="ann-kategoria" class="form-label">Kategoria</label>
              <select name="kategoria" id="ann-kategoria" class="form-select">
                <?php foreach (ann_kategoria_options() as $_kk => $_kl): ?>
                <option value="<?= h($_kk) ?>" <?= $values['kategoria'] === $_kk ? 'selected' : '' ?>><?= h($_kl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label for="ann-expires" class="form-label">Data wygaśnięcia <span style="font-weight:400;color:var(--tz-muted)">(opcjonalna)</span></label>
              <input type="date" id="ann-expires" name="expires_at" class="form-control"
                     value="<?= h($values['expires_at']) ?>" min="<?= date('Y-m-d') ?>">
              <div class="form-text">Po tej dacie ogłoszenie przestaje być widoczne.</div>
            </div>
            <div class="col-12">
              <label for="ann-audience" class="form-label">Odbiorca</label>
              <select name="audience" class="form-select" id="ann-audience">
                <option value="all"    <?= $values['audience'] === 'all'    ? 'selected' : '' ?>>Wszyscy aktywni użytkownicy</option>
                <option value="public" <?= $values['audience'] === 'public' ? 'selected' : '' ?>>Strona logowania (przed zalogowaniem)</option>
                <optgroup label="Rola">
                  <option value="role:admin"  <?= $values['audience'] === 'role:admin'  ? 'selected' : '' ?>>Administratorzy</option>
                  <option value="role:editor" <?= $values['audience'] === 'role:editor' ? 'selected' : '' ?>>Edytorzy</option>
                  <option value="role:viewer" <?= $values['audience'] === 'role:viewer' ? 'selected' : '' ?>>Przeglądający</option>
                </optgroup>
                <?php if (!empty($org_units)): ?>
                <optgroup label="Jednostka organizacyjna">
                  <?php foreach ($org_units as $ou): ?>
                  <option value="unit:<?= (int)$ou['id'] ?>" <?= $values['audience'] === 'unit:' . $ou['id'] ? 'selected' : '' ?>>
                    <?= h($ou['name']) ?>
                  </option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if (!empty($all_users)): ?>
                <optgroup label="Konkretna osoba">
                  <?php foreach ($all_users as $au): ?>
                  <option value="user:<?= (int)$au['id'] ?>" <?= $values['audience'] === 'user:' . $au['id'] ? 'selected' : '' ?>>
                    <?= h($au['name']) ?><?= $au['email'] ? ' &lt;' . h($au['email']) . '&gt;' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
            </div>
          </div>
        </div>
      </section>

      <section class="tz-card">
        <div class="tz-card__hd"><i class="bi bi-display" aria-hidden="true"></i>Sposób wyświetlania</div>
        <div class="tz-card__bd">
          <label for="ann-display" class="form-label">W panelu wolontariusza</label>
          <select name="display_mode" class="form-select" id="ann-display">
            <option value="feed"   <?= $values['display_mode'] === 'feed'   ? 'selected' : '' ?>>Tylko lista komunikatów (domyślnie)</option>
            <option value="banner" <?= $values['display_mode'] === 'banner' ? 'selected' : '' ?>>Baner — pasek na górze panelu</option>
            <option value="popup"  <?= $values['display_mode'] === 'popup'  ? 'selected' : '' ?>>Popup — okno przy wejściu do panelu</option>
          </select>
          <div class="form-text mt-2">
            <strong>Lista</strong> — widoczne na stronie Komunikaty.
            <strong>Baner</strong> — dodatkowo jako pasek nad treścią panelu.
            <strong>Popup</strong> — dodatkowo jako okno modalne przy wejściu.
            Baner i popup znikają po potwierdzeniu „Przeczytane".
          </div>

          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="ann-pinned" name="is_pinned" value="1"
                   <?= $values['is_pinned'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="ann-pinned">
              <i class="bi bi-pin-angle-fill" style="color:#F59E0B" aria-hidden="true"></i>
              Przypnij ogłoszenie na górze listy
            </label>
          </div>

          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="ann-email" name="send_email" value="1"
                   <?= $values['send_email'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="ann-email">
              <i class="bi bi-envelope" aria-hidden="true"></i> Wyślij też e-mailem do odbiorców
            </label>
          </div>
          <div class="tz-note tz-note--warning mt-2 mb-0" id="email-warn" <?= $values['send_email'] ? '' : 'hidden' ?>>
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>Uwaga:</strong> może to wysłać wiele wiadomości naraz — do wszystkich wybranych odbiorców.</div>
          </div>
        </div>
      </section>

      <div class="d-flex gap-2 flex-wrap mb-3">
        <button type="submit" class="tz-btn"><i class="bi bi-send" aria-hidden="true"></i>Opublikuj ogłoszenie</button>
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="tz-btn tz-btn--ghost">Anuluj</a>
      </div>
    </div>

    <!-- Podgląd -->
    <div class="col-lg-4">
      <section class="tz-card" style="position:sticky;top:1rem">
        <div class="tz-card__hd"><i class="bi bi-eye" aria-hidden="true"></i>Podgląd</div>
        <div class="tz-card__bd">
          <div class="kom-ann__meta mb-2" id="preview-badges"></div>
          <div class="kom-ann__ttl" id="preview-title" style="font-size:.95rem"></div>
          <div class="kom-body" id="preview-body" style="min-height:80px;color:var(--tz-muted)"></div>
        </div>
        <div class="tz-card__ft">
          <span class="kom-ann__author"><i class="bi bi-person" aria-hidden="true"></i><?= h($_cu['name'] ?? '') ?></span>
        </div>
      </section>
    </div>
  </div>
</form>

<script>
(function () {
  var titleEl = document.getElementById('ann-title');
  var bodyEl  = document.getElementById('ann-body');
  var katEl   = document.getElementById('ann-kategoria');
  var pinEl   = document.getElementById('ann-pinned');
  var emailCb = document.getElementById('ann-email');
  var warn    = document.getElementById('email-warn');
  var badges  = document.getElementById('preview-badges');
  var katColor = <?= json_encode(array_map('ann_kategoria_color', array_combine(array_keys(ann_kategoria_options()), array_keys(ann_kategoria_options()))), JSON_UNESCAPED_UNICODE) ?>;

  function badge(text, color) {
    var s = document.createElement('span');
    s.className = 'tz-badge';
    s.style.background = color + '1A';
    s.style.color = color;
    s.style.borderColor = color + '55';
    s.textContent = text;
    return s;
  }

  function update() {
    document.getElementById('title-cnt').textContent = titleEl.value.length;
    document.getElementById('body-cnt').textContent  = bodyEl.value.length;
    document.getElementById('preview-title').textContent = titleEl.value || '(brak tytułu)';
    document.getElementById('preview-body').textContent  = bodyEl.value  || '(brak treści)';

    badges.textContent = '';
    if (pinEl.checked) badges.appendChild(badge('Przypięte', '#F59E0B'));
    badges.appendChild(badge(katEl.options[katEl.selectedIndex].text, katColor[katEl.value] || '#F59E0B'));
  }

  ['input', 'change'].forEach(function (ev) {
    titleEl.addEventListener(ev, update);
    bodyEl.addEventListener(ev, update);
  });
  katEl.addEventListener('change', update);
  pinEl.addEventListener('change', update);
  emailCb.addEventListener('change', function () { warn.hidden = !emailCb.checked; });
  update();
})();
</script>

<?php require __DIR__ . '/_foot.php'; ?>
