<?php
/**
 * komunikaty/compose.php — Publikacja nowego ogłoszenia (admin).
 *
 * Wygląd METRO jak komunikaty/index.php (wspólny zestaw modules/panel_metro):
 * płaskie grupy sekcji, kategoria i tryb wyświetlania jako kafelki-radia,
 * podgląd = ta sama karta z paskiem koloru kategorii co w feedzie.
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
    if (!array_key_exists($values['display_mode'], ann_display_modes())) {
        $values['display_mode'] = 'feed';
    }
    $values['is_pinned']  = isset($_POST['is_pinned']) ? 1 : 0;
    $values['expires_at'] = trim($_POST['expires_at'] ?? '');
    $values['send_email'] = isset($_POST['send_email']) ? 1 : 0;

    if ($values['title'] === '') $errors[] = 'Tytuł jest wymagany.';
    if (mb_strlen($values['title']) > 200) $errors[] = 'Tytuł może mieć maksymalnie 200 znaków.';
    if (mb_strlen($values['body']) > 5000) $errors[] = 'Treść może mieć maksymalnie 5000 znaków.';
    if (mb_strlen($values['body']) < 1)    $errors[] = 'Treść jest wymagana.';

    $valid_audiences = ['all', 'contracts:active', 'public', 'ti:prowadzacy', 'role:admin', 'role:editor', 'role:viewer'];
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
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');

$_kat_opts = ann_kategoria_options();
$_kat_col  = [];
foreach ($_kat_opts as $_kk => $_kl) $_kat_col[$_kk] = ann_kategoria_color($_kk);

// Kafle trybu wyświetlania: ikona + krótki opis; etykieta z ann_display_modes() jako zapas.
$_dm_meta = [
    'feed'      => ['bi-list-ul',            'Lista',            'Tylko na stronie Komunikaty.'],
    'important' => ['bi-exclamation-square', 'Ważna informacja', 'Stały kafel na pulpicie panelu — do daty wygaśnięcia albo usunięcia.'],
    'banner'    => ['bi-layout-text-window', 'Baner',            'Pasek nad treścią panelu, znika po „Przeczytane”.'],
    'popup'     => ['bi-window-stack',       'Popup',            'Okno przy wejściu do panelu, znika po „Przeczytane”.'],
];
?>
<style>
/* ── Nowe ogłoszenie w METRO: formularz w grupach + podgląd jak karta w feedzie ── */
.pvm .kom-cmp{display:grid;grid-template-columns:1fr;gap:1rem;align-items:start}
@media(min-width:992px){.pvm .kom-cmp{grid-template-columns:minmax(0,1fr) 340px}}
.pvm .kom-cmp__side{position:sticky;top:1rem}
.pvm .kom-sec{background:var(--pvm-light);padding:1.25rem;margin-bottom:8px}
.pvm .kom-sec__hd{display:flex;align-items:center;gap:.6rem;margin:0 0 1rem;font-size:1.25rem;font-weight:300;color:var(--pvm-ink);letter-spacing:-.01em}
.pvm .kom-sec__hd .sq{width:32px;height:32px;background:var(--c,var(--pvm-acc));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.pvm .kom-lbl{display:block;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--pvm-muted);margin:0 0 .35rem}
.pvm .kom-lbl .req{color:var(--m-red)}
.pvm .kom-lbl .opt{text-transform:none;letter-spacing:0;font-weight:400}
.pvm .kom-field{margin-bottom:1.1rem}
.pvm .kom-field:last-child{margin-bottom:0}
.pvm .kom-in{width:100%;background:var(--pvm-bg);color:var(--pvm-ink);border:2px solid #8a8a8a;border-radius:0;padding:.6rem .75rem;font:inherit;font-size:.95rem;min-height:44px}
.pvm .kom-in:focus{outline:none;border-color:var(--pvm-acc);box-shadow:none}
.pvm .kom-in--title{font-size:1.15rem;font-weight:600}
.pvm textarea.kom-in{min-height:12rem;resize:vertical;line-height:1.6}
.pvm .kom-hint{display:flex;justify-content:space-between;gap:.75rem;font-size:.78rem;color:var(--pvm-muted);margin-top:.3rem}
.pvm .kom-cnt{font-variant-numeric:tabular-nums;white-space:nowrap}
.pvm .kom-cnt.is-over{color:var(--m-red);font-weight:700}
.pvm .kom-row{display:grid;grid-template-columns:1fr;gap:1.1rem}
@media(min-width:576px){.pvm .kom-row{grid-template-columns:1fr 1fr}}

/* wybór kategorii i trybu: płaskie kafelki-radia */
.pvm .kom-pick{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;border:0}
.pvm .kom-pick input{position:absolute;opacity:0;pointer-events:none}
.pvm .kom-cat{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .85rem;background:var(--pvm-bg);color:var(--pvm-ink);font-size:.86rem;font-weight:500;min-height:40px;border-left:6px solid var(--c);cursor:pointer;margin:0}
.pvm .kom-cat:hover{background:var(--pvm-light-h)}
.pvm input:checked+.kom-cat{background:var(--c);color:var(--on,#fff);font-weight:600}
.pvm input:focus-visible+.kom-cat,.pvm input:focus-visible+.kom-mode{outline:3px solid #fbbf24;outline-offset:2px}
.pvm .kom-modes{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:6px}
.pvm .kom-mode{position:relative;display:flex;flex-direction:column;gap:.35rem;height:100%;padding:.85rem .9rem;background:var(--pvm-bg);color:var(--pvm-ink);border:2px solid transparent;cursor:pointer;margin:0}
.pvm .kom-mode:hover{background:var(--pvm-light-h)}
.pvm .kom-mode .ic{font-size:1.5rem;line-height:1}
.pvm .kom-mode .t{font-size:.92rem;font-weight:600}
.pvm .kom-mode .d{font-size:.78rem;color:var(--pvm-muted);line-height:1.4}
.pvm .kom-mode .ok{position:absolute;top:.5rem;right:.55rem;font-size:1rem;display:none}
.pvm input:checked+.kom-mode{background:var(--pvm-acc);color:var(--pvm-acc-on);border-color:var(--pvm-acc)}
.pvm input:checked+.kom-mode .d{color:inherit;opacity:.9}
.pvm input:checked+.kom-mode .ok{display:block}

/* przełączniki */
.pvm .kom-opt{display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .9rem;background:var(--pvm-bg);margin:0 0 6px;cursor:pointer}
.pvm .kom-opt:hover{background:var(--pvm-light-h)}
.pvm .kom-opt input{width:20px;height:20px;margin:.1rem 0 0;flex-shrink:0;accent-color:var(--pvm-acc);cursor:pointer}
.pvm .kom-opt .t{display:block;font-size:.92rem;font-weight:600;color:var(--pvm-ink)}
.pvm .kom-opt .d{display:block;font-size:.78rem;color:var(--pvm-muted)}
.pvm .mt-note--warn{border-left-color:var(--m-orange)}
.pvm .mt-note--warn>i{color:var(--m-orange)}
.pvm .mt-note--err{border-left-color:var(--m-red)}
.pvm .mt-note--err>i{color:var(--m-red)}
.pvm .kom-acts{display:flex;flex-wrap:wrap;gap:8px;margin:1rem 0 1.5rem}

/* podgląd — ta sama karta co w feedzie (index.php) */
.pvm .kom-prev-hd{display:flex;align-items:center;gap:.5rem;margin:0 0 .6rem;font-size:1.25rem;font-weight:300;color:var(--pvm-ink)}
.pvm .kom-ann{background:var(--pvm-light);border-left:8px solid var(--c);padding:1rem 1.15rem;min-width:0}
.pvm .kom-ann__meta{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .7rem;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--pvm-muted);margin-bottom:.35rem}
.pvm .kom-ann__ttl{margin:0;font-size:1.15rem;font-weight:600;line-height:1.3;color:var(--pvm-ink);word-break:break-word}
.pvm .kom-body{margin-top:.45rem;font-size:.92rem;line-height:1.6;color:var(--pvm-ink);white-space:pre-wrap;word-break:break-word;max-height:22rem;overflow:auto}
.pvm .kom-ann__ft{margin-top:.8rem;font-size:.8rem;color:var(--pvm-muted)}
.pvm .is-empty{color:var(--pvm-muted);font-style:italic;font-weight:400}
.pvm .kom-prev-to{margin-top:8px;background:var(--pvm-light);padding:.75rem 1rem;font-size:.82rem;color:var(--pvm-muted)}
.pvm .kom-prev-to strong{display:block;color:var(--pvm-ink);font-weight:600;word-break:break-word}

:root[data-theme="hc"] .pvm .kom-sec,:root[data-theme="hc"] .pvm .kom-ann,:root[data-theme="hc"] .pvm .kom-prev-to{border:2px solid #000}
:root[data-theme="hc"] .pvm .kom-ann{border-left-width:8px}
:root[data-theme="hc"] .pvm .kom-cat,:root[data-theme="hc"] .pvm .kom-mode,:root[data-theme="hc"] .pvm .kom-opt{border:2px solid #000}
:root[data-theme="hc"] .pvm input:checked+.kom-cat,:root[data-theme="hc"] .pvm input:checked+.kom-mode{background:#000;color:#fff}
</style>

<div class="pvm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/komunikaty/index.php" style="color:inherit"><i class="bi bi-arrow-left" aria-hidden="true"></i> Komunikaty</a></div>
      <h1 class="mt-title">Nowe ogłoszenie</h1>
      <p class="mt-lead">Trafi do wybranych odbiorców w module Komunikaty — i opcjonalnie na pulpit panelu albo e-mailem.</p>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="mt-note mt-note--err" role="alert">
    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
    <div>
      <strong>Nie udało się opublikować ogłoszenia:</strong>
      <ul class="mb-0 ps-3 mt-1">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" class="kom-cmp" id="kom-compose">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <div>
      <!-- ═══ Treść ═══ -->
      <section class="kom-sec" aria-labelledby="sec-tresc">
        <h2 class="kom-sec__hd" id="sec-tresc"><span class="sq" style="--c:var(--m-blue)" aria-hidden="true"><i class="bi bi-pencil"></i></span>Treść</h2>
        <div class="kom-field">
          <label for="ann-title" class="kom-lbl">Tytuł <span class="req" aria-hidden="true">*</span></label>
          <input type="text" id="ann-title" name="title" class="kom-in kom-in--title" maxlength="200" required
                 value="<?= h($values['title']) ?>" placeholder="Krótki, opisowy tytuł ogłoszenia" aria-describedby="title-hint">
          <div class="kom-hint" id="title-hint"><span></span><span class="kom-cnt" id="title-cnt">0/200</span></div>
        </div>
        <div class="kom-field">
          <label for="ann-body" class="kom-lbl">Treść <span class="req" aria-hidden="true">*</span></label>
          <textarea id="ann-body" name="body" class="kom-in" rows="10" required
                    placeholder="Pełna treść ogłoszenia…" aria-describedby="body-hint"><?= h($values['body']) ?></textarea>
          <div class="kom-hint" id="body-hint"><span>Zwykły tekst — nowe linie są zachowane.</span><span class="kom-cnt" id="body-cnt">0/5000</span></div>
        </div>
      </section>

      <!-- ═══ Kategoria i odbiorcy ═══ -->
      <section class="kom-sec" aria-labelledby="sec-odb">
        <h2 class="kom-sec__hd" id="sec-odb"><span class="sq" style="--c:var(--m-teal)" aria-hidden="true"><i class="bi bi-people"></i></span>Kategoria i odbiorcy</h2>

        <fieldset class="kom-field" style="border:0;padding:0;min-width:0">
          <legend class="kom-lbl">Kategoria</legend>
          <div class="kom-pick">
            <?php foreach ($_kat_opts as $_kk => $_kl): $_kc = $_kat_col[$_kk]; ?>
            <input type="radio" name="kategoria" id="kat-<?= h($_kk) ?>" value="<?= h($_kk) ?>"<?= $values['kategoria'] === $_kk ? ' checked' : '' ?>>
            <label for="kat-<?= h($_kk) ?>" class="kom-cat" style="--c:<?= h($_kc) ?>;--on:<?= h(panelMetroOnColor($_kc)) ?>"><?= h($_kl) ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <div class="kom-row">
          <div class="kom-field">
            <label for="ann-audience" class="kom-lbl">Odbiorca</label>
            <select name="audience" class="kom-in" id="ann-audience">
              <option value="all"    <?= $values['audience'] === 'all'    ? 'selected' : '' ?>>Wszyscy aktywni użytkownicy</option>
              <option value="contracts:active" <?= $values['audience'] === 'contracts:active' ? 'selected' : '' ?>>Osoby z aktywnymi umowami</option>
              <option value="public" <?= $values['audience'] === 'public' ? 'selected' : '' ?>>Strona logowania (przed zalogowaniem)</option>
              <option value="ti:prowadzacy" <?= $values['audience'] === 'ti:prowadzacy' ? 'selected' : '' ?>>Prowadzący TI (baner tylko w panelu dydaktyka)</option>
              <optgroup label="Rola">
                <option value="role:admin"  <?= $values['audience'] === 'role:admin'  ? 'selected' : '' ?>>Administratorzy</option>
                <option value="role:editor" <?= $values['audience'] === 'role:editor' ? 'selected' : '' ?>>Edytorzy</option>
                <option value="role:viewer" <?= $values['audience'] === 'role:viewer' ? 'selected' : '' ?>>Przeglądający</option>
              </optgroup>
              <?php if (!empty($org_units)): ?>
              <optgroup label="Jednostka organizacyjna">
                <?php foreach ($org_units as $ou): ?>
                <option value="unit:<?= (int)$ou['id'] ?>" <?= $values['audience'] === 'unit:' . $ou['id'] ? 'selected' : '' ?>><?= h($ou['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($all_users)): ?>
              <optgroup label="Konkretna osoba">
                <?php foreach ($all_users as $au): ?>
                <option value="user:<?= (int)$au['id'] ?>" <?= $values['audience'] === 'user:' . $au['id'] ? 'selected' : '' ?>><?= h($au['name']) ?><?= $au['email'] ? ' &lt;' . h($au['email']) . '&gt;' : '' ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
          </div>
          <div class="kom-field">
            <label for="ann-expires" class="kom-lbl">Data wygaśnięcia <span class="opt">(opcjonalna)</span></label>
            <input type="date" id="ann-expires" name="expires_at" class="kom-in"
                   value="<?= h($values['expires_at']) ?>" min="<?= date('Y-m-d') ?>" aria-describedby="exp-hint">
            <div class="kom-hint" id="exp-hint"><span>Po tej dacie ogłoszenie przestaje być widoczne.</span></div>
          </div>
        </div>
      </section>

      <!-- ═══ Sposób wyświetlania ═══ -->
      <section class="kom-sec" aria-labelledby="sec-disp">
        <h2 class="kom-sec__hd" id="sec-disp"><span class="sq" style="--c:var(--m-violet)" aria-hidden="true"><i class="bi bi-display"></i></span>Sposób wyświetlania</h2>

        <fieldset class="kom-field" style="border:0;padding:0;min-width:0">
          <legend class="kom-lbl">W Panelu współpracownika</legend>
          <div class="kom-pick kom-modes">
            <?php foreach (ann_display_modes() as $_dm => $_dl):
                [$_di, $_dt, $_dd] = $_dm_meta[$_dm] ?? ['bi-square', $_dl, '']; ?>
            <div>
              <input type="radio" name="display_mode" id="dm-<?= h($_dm) ?>" value="<?= h($_dm) ?>"<?= $values['display_mode'] === $_dm ? ' checked' : '' ?>>
              <label for="dm-<?= h($_dm) ?>" class="kom-mode">
                <i class="bi bi-check-square-fill ok" aria-hidden="true"></i>
                <i class="bi <?= h($_di) ?> ic" aria-hidden="true"></i>
                <span class="t"><?= h($_dt) ?></span>
                <?php if ($_dd !== ''): ?><span class="d"><?= h($_dd) ?></span><?php endif; ?>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <label class="kom-opt" for="ann-pinned">
          <input type="checkbox" id="ann-pinned" name="is_pinned" value="1"<?= $values['is_pinned'] ? ' checked' : '' ?>>
          <span><span class="t"><i class="bi bi-pin-angle-fill" style="color:var(--m-orange)" aria-hidden="true"></i> Przypnij na górze listy</span>
                <span class="d">Ogłoszenie będzie wyświetlane przed pozostałymi.</span></span>
        </label>
        <label class="kom-opt" for="ann-email">
          <input type="checkbox" id="ann-email" name="send_email" value="1"<?= $values['send_email'] ? ' checked' : '' ?> aria-describedby="email-warn">
          <span><span class="t"><i class="bi bi-envelope" aria-hidden="true"></i> Wyślij też e-mailem</span>
                <span class="d">Wiadomość w kolorze kategorii trafi do skrzynek wszystkich odbiorców.</span></span>
        </label>
        <div class="mt-note mt-note--warn mb-0" id="email-warn"<?= $values['send_email'] ? '' : ' hidden' ?>>
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <div><strong>Uwaga:</strong> może to wysłać wiele wiadomości naraz — do wszystkich wybranych odbiorców.</div>
        </div>
      </section>

      <div class="kom-acts">
        <button type="submit" class="mt-btn"><i class="bi bi-send" aria-hidden="true"></i>Opublikuj ogłoszenie</button>
        <a href="<?= APP_URL ?>/komunikaty/index.php" class="mt-btn mt-btn--ghost">Anuluj</a>
      </div>
    </div>

    <!-- ═══ Podgląd ═══ -->
    <aside class="kom-cmp__side" aria-labelledby="prev-hd">
      <h2 class="kom-prev-hd" id="prev-hd"><i class="bi bi-eye" aria-hidden="true"></i>Podgląd</h2>
      <article class="kom-ann" id="prev-card" style="--c:<?= h($_kat_col[$values['kategoria']] ?? '#5f5f5f') ?>" aria-live="polite">
        <div class="kom-ann__meta" id="prev-meta"></div>
        <h3 class="kom-ann__ttl" id="prev-title"></h3>
        <div class="kom-body" id="prev-body"></div>
        <div class="kom-ann__ft"><i class="bi bi-person" aria-hidden="true"></i> <?= h($_cu['name'] ?? '') ?> · <?= date('d.m.Y') ?></div>
      </article>
      <div class="kom-prev-to">Odbiorcy<strong id="prev-to"></strong></div>
    </aside>
  </form>
</div>

<script>
(function () {
  var f       = document.getElementById('kom-compose');
  var titleEl = document.getElementById('ann-title');
  var bodyEl  = document.getElementById('ann-body');
  var audEl   = document.getElementById('ann-audience');
  var pinEl   = document.getElementById('ann-pinned');
  var emailCb = document.getElementById('ann-email');
  var warn    = document.getElementById('email-warn');
  var meta    = document.getElementById('prev-meta');
  var card    = document.getElementById('prev-card');
  var katColor = <?= json_encode($_kat_col, JSON_UNESCAPED_UNICODE) ?>;
  var BODY_MAX = 5000;

  // maxlength na treści dopiero po załadowaniu — globalny licznik z utils.js
  // (textarea[maxlength]) dublowałby licznik formularza.
  window.addEventListener('load', function () { bodyEl.maxLength = BODY_MAX; });

  function badge(html, bg) {
    var s = document.createElement('span');
    s.className = 'mt-badge';
    if (bg) s.style.background = bg;
    s.innerHTML = html;
    return s;
  }
  function checked(name) { var r = f.querySelector('input[name="' + name + '"]:checked'); return r; }
  function cnt(el, id, max) {
    var o = document.getElementById(id), n = el.value.length;
    o.textContent = n + '/' + max;
    o.classList.toggle('is-over', n > max);
  }
  function fill(el, text, empty) {
    el.textContent = text || empty;
    el.classList.toggle('is-empty', !text);
  }

  function update() {
    cnt(titleEl, 'title-cnt', 200);
    cnt(bodyEl, 'body-cnt', BODY_MAX);
    fill(document.getElementById('prev-title'), titleEl.value.trim(), '(brak tytułu)');
    fill(document.getElementById('prev-body'), bodyEl.value, '(brak treści)');

    var kat = checked('kategoria'), dm = checked('display_mode');
    card.style.setProperty('--c', (kat && katColor[kat.value]) || '#5f5f5f');
    meta.textContent = '';
    if (dm && dm.value === 'important') meta.appendChild(badge('<i class="bi bi-exclamation-lg" aria-hidden="true"></i>Ważna informacja', 'var(--m-red)'));
    if (pinEl.checked) meta.appendChild(badge('<i class="bi bi-pin-angle-fill" aria-hidden="true"></i>Przypięte', 'var(--m-orange)'));
    if (kat) {
      var k = document.createElement('span');
      k.textContent = f.querySelector('label[for="' + kat.id + '"]').textContent;
      meta.appendChild(k);
    }
    var opt = audEl.options[audEl.selectedIndex];
    document.getElementById('prev-to').textContent = opt ? opt.textContent.trim() : '';
    warn.hidden = !emailCb.checked;
  }

  f.addEventListener('input', update);
  f.addEventListener('change', update);
  update();
})();
</script>

<?php require __DIR__ . '/_foot.php'; ?>
