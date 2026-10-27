<?php
/**
 * @var bool $enabled @var array $s ustawienia bramki @var list<array> $templates @var ?array $edit szablon w edycji
 * @var list<array> $messages ostatnie wiadomości @var string $smsSettingsUrl
 */
$statusLabel = ['sent' => 'Wysłano (WhatsApp)', 'fallback_sms' => 'Zapasowy SMS', 'failed' => 'Błąd', 'refused_consent' => 'Odrzucono — brak zgody'];
$statusClass = ['sent' => 'text-success', 'fallback_sms' => 'text-warning-emphasis', 'failed' => 'text-danger', 'refused_consent' => 'text-danger'];
$e = $edit ?? ['id' => 0, 'tpl_key' => '', 'label' => '', 'provider_name' => '', 'language' => 'pl', 'body' => '', 'category' => 'transactional', 'sms_fallback' => 1, 'active' => 1];
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h($smsSettingsUrl) ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Ustawienia SMS</a></nav>
<div class="d-flex align-items-center gap-3 mb-3">
  <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0"><i class="bi bi-whatsapp text-success fs-4" aria-hidden="true"></i></div>
  <div>
    <h1 class="h4 mb-0">WhatsApp (SMSApi)</h1>
    <div class="text-muted small">Osobny kanał od SMS — SMS-y zostają przy dostawcach z Ustawień SMS. Wysyłka tylko szablonami zatwierdzonymi przez Meta.</div>
  </div>
</div>
<?= flash_html() ?>

<section class="card shadow-sm mb-4" aria-labelledby="wa-h-gw">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="wa-h-gw">Bramka WhatsApp</h2>
    <p class="small text-muted">Dokumentację techniczną WhatsApp Business API dostajesz od SMSApi po weryfikacji firmy przez Meta (nie jest publiczna).
      Z dokumentacji przepisz tutaj adres, sposób uwierzytelnienia i kształt JSON-a żądania — dopóki pola adresu i szablonu treści są puste, wysyłka WhatsApp jest niedostępna.</p>
    <form method="post" action="<?= h(route('whatsapp.settings')) ?>" class="row g-3">
      <?= Router::csrfField() ?>
      <div class="col-12">
        <label for="wa-url" class="form-label fw-semibold">Adres API (POST)</label>
        <input type="url" id="wa-url" name="whatsapp_api_url" class="form-control" value="<?= h($s['url']) ?>" placeholder="https://…" aria-describedby="wa-url-help">
        <div id="wa-url-help" class="form-text">Wymagane https. Http działa tylko dla localhost (testy).</div>
      </div>
      <div class="col-12 col-md-4">
        <label for="wa-hdr" class="form-label fw-semibold">Nagłówek uwierzytelnienia</label>
        <input type="text" id="wa-hdr" name="whatsapp_auth_header" class="form-control" value="<?= h($s['auth_header']) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label for="wa-pfx" class="form-label fw-semibold">Przedrostek wartości</label>
        <input type="text" id="wa-pfx" name="whatsapp_auth_prefix" class="form-control" value="<?= h($s['auth_prefix']) ?>" aria-describedby="wa-pfx-help">
        <div id="wa-pfx-help" class="form-text">Np. „Bearer ” (ze spacją) albo puste.</div>
      </div>
      <div class="col-12 col-md-5">
        <label for="wa-token" class="form-label fw-semibold">Token / klucz API</label>
        <input type="password" id="wa-token" name="whatsapp_api_token" class="form-control" autocomplete="off" aria-describedby="wa-token-help"
               placeholder="<?= $s['has_token'] ? '•••••••• (zapisany — wpisz, żeby zmienić)' : 'Wklej token' ?>">
        <div id="wa-token-help" class="form-text">
          <?= $s['has_token'] ? 'Token jest zapisany i nie jest wyświetlany.' : 'Token nie jest jeszcze zapisany.' ?>
          <?php if ($s['has_token']): ?><span class="ms-2"><input type="checkbox" class="form-check-input" id="wa-clr" name="clear_token" value="1"> <label for="wa-clr">usuń zapisany token</label></span><?php endif; ?>
        </div>
      </div>
      <div class="col-12">
        <label for="wa-body" class="form-label fw-semibold">Szablon treści żądania (JSON)</label>
        <textarea id="wa-body" name="whatsapp_body_template" rows="9" class="form-control font-monospace" spellcheck="false" aria-describedby="wa-body-help"><?= h($s['body_template']) ?></textarea>
        <div id="wa-body-help" class="form-text">
          Wpisz JSON z dokumentacji, a w miejsce zmiennych wartości użyj:
          <code>{to}</code> (48600700800), <code>{to_plus}</code> (+48600700800), <code>{template}</code> (nazwa szablonu u dostawcy), <code>{language}</code>, <code>{text}</code> (gotowa treść)
          — w cudzysłowach, np. <code>"to": "{to}"</code>; oraz <code>{params_json}</code> (["Anna","12:00"]) i <code>{params_object_json}</code> ({"1":"Anna","2":"12:00"}) — bez cudzysłowów.
        </div>
      </div>
      <div class="col-12 col-md-6">
        <label for="wa-ok" class="form-label fw-semibold">Ścieżka potwierdzenia w odpowiedzi <span class="fw-normal text-muted">(opcjonalnie)</span></label>
        <input type="text" id="wa-ok" name="whatsapp_ok_path" class="form-control" value="<?= h($s['ok_path']) ?>" placeholder="np. messages.0.id" aria-describedby="wa-ok-help">
        <div id="wa-ok-help" class="form-text">Jeśli podana, wysyłka liczy się tylko, gdy odpowiedź JSON ma tam niepustą wartość. Puste = wystarczy HTTP 2xx.</div>
      </div>
      <div class="col-12 col-md-6">
        <label for="wa-id" class="form-label fw-semibold">Ścieżka identyfikatora wiadomości <span class="fw-normal text-muted">(opcjonalnie)</span></label>
        <input type="text" id="wa-id" name="whatsapp_id_path" class="form-control" value="<?= h($s['id_path']) ?>" placeholder="np. messages.0.id">
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="wa-on" name="whatsapp_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="wa-on">Włącz wysyłkę WhatsApp</label>
          <div class="form-text">Po wyłączeniu szablony z zapasowym SMS-em idą od razu SMS-em; pozostałe zwracają błąd.</div>
        </div>
      </div>
      <div class="col-12"><button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz</button></div>
    </form>
  </div>
</section>

<section class="card shadow-sm mb-4" aria-labelledby="wa-h-tpl">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="wa-h-tpl">Szablony wiadomości</h2>
    <p class="small text-muted">Szablon musi być zatwierdzony przez Meta u dostawcy. Treść z parametrami <code>{{1}}</code>, <code>{{2}}</code>… jest też tekstem zapasowego SMS-a.
      <strong>Marketingowy</strong> szablon wyśle się tylko dla odbiorcy ze zgodą — kod wywołujący musi ją potwierdzić.</p>
    <?php if ($templates): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <thead><tr><th scope="col">Klucz</th><th scope="col">Nazwa</th><th scope="col">U dostawcy</th><th scope="col">Rodzaj</th><th scope="col">SMS zapasowy</th><th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <th scope="row" class="font-monospace small"><?= h($t['tpl_key']) ?></th>
            <td><?= h($t['label']) ?></td>
            <td class="small"><?= h($t['provider_name']) ?> (<?= h($t['language']) ?>)</td>
            <td><?= $t['category'] === 'marketing' ? 'marketingowy' : 'transakcyjny' ?></td>
            <td><?= (int)$t['sms_fallback'] ? 'tak' : 'nie' ?></td>
            <td><?= (int)$t['active'] ? 'aktywny' : '<span class="text-muted">nieaktywny</span>' ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="<?= h(route('whatsapp.index', ['edit' => (int)$t['id']])) ?>#wa-h-edit">Edytuj<span class="visually-hidden"> szablon <?= h($t['tpl_key']) ?></span></a>
              <form method="post" action="<?= h(route('whatsapp.template.delete', ['id' => (int)$t['id']])) ?>" class="d-inline" onsubmit="return confirm('Usunąć szablon?');">
                <?= Router::csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger">Usuń<span class="visually-hidden"> szablon <?= h($t['tpl_key']) ?></span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><p class="text-muted">Brak szablonów.</p><?php endif; ?>

    <h3 class="h6 fw-bold" id="wa-h-edit"><?= $e['id'] ? 'Edycja szablonu' : 'Nowy szablon' ?></h3>
    <form method="post" action="<?= h(route('whatsapp.template.save')) ?>" class="row g-3">
      <?= Router::csrfField() ?>
      <div class="col-12 col-md-3">
        <label for="wt-key" class="form-label fw-semibold">Klucz w SZO</label>
        <input type="text" id="wt-key" name="tpl_key" class="form-control font-monospace" value="<?= h($e['tpl_key']) ?>" required pattern="[a-z0-9_]{2,40}" <?= $e['id'] ? 'readonly' : '' ?> aria-describedby="wt-key-help">
        <div id="wt-key-help" class="form-text">a–z, 0–9, _ ; tego klucza używa kod wysyłający.</div>
      </div>
      <div class="col-12 col-md-4">
        <label for="wt-label" class="form-label fw-semibold">Nazwa</label>
        <input type="text" id="wt-label" name="label" class="form-control" value="<?= h($e['label']) ?>" required>
      </div>
      <div class="col-12 col-md-3">
        <label for="wt-prov" class="form-label fw-semibold">Nazwa szablonu u dostawcy</label>
        <input type="text" id="wt-prov" name="provider_name" class="form-control font-monospace" value="<?= h($e['provider_name']) ?>" required>
      </div>
      <div class="col-12 col-md-2">
        <label for="wt-lang" class="form-label fw-semibold">Język</label>
        <input type="text" id="wt-lang" name="language" class="form-control" value="<?= h($e['language']) ?>" required pattern="[a-z]{2}(_[A-Z]{2})?" aria-describedby="wt-lang-help">
        <div id="wt-lang-help" class="form-text">pl albo pl_PL</div>
      </div>
      <div class="col-12">
        <label for="wt-body" class="form-label fw-semibold">Treść (z parametrami {{1}}, {{2}}…)</label>
        <textarea id="wt-body" name="body" rows="4" maxlength="<?= (int)WhatsappService::BODY_MAX ?>" class="form-control" required><?= h($e['body']) ?></textarea>
      </div>
      <div class="col-12 col-md-5">
        <label for="wt-cat" class="form-label fw-semibold">Rodzaj</label>
        <select id="wt-cat" name="category" class="form-select">
          <?php foreach (WhatsappService::CATEGORIES as $k => $lab): ?>
            <option value="<?= h($k) ?>" <?= $e['category'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-7 d-flex flex-column justify-content-end gap-1">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="wt-sms" name="sms_fallback" value="1" <?= (int)$e['sms_fallback'] ? 'checked' : '' ?>><label class="form-check-label" for="wt-sms">Gdy WhatsApp zawiedzie — wyślij tę treść zwykłym SMS-em</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="wt-act" name="active" value="1" <?= (int)$e['active'] ? 'checked' : '' ?>><label class="form-check-label" for="wt-act">Szablon aktywny</label></div>
      </div>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz szablon</button>
        <?php if ($e['id']): ?><a class="btn btn-outline-secondary" href="<?= h(route('whatsapp.index')) ?>">Anuluj edycję</a><?php endif; ?>
      </div>
    </form>
  </div>
</section>

<?php if ($templates): ?>
<section class="card shadow-sm mb-4" aria-labelledby="wa-h-test">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="wa-h-test">Test wysyłki</h2>
    <form method="post" action="<?= h(route('whatsapp.test')) ?>" class="row g-3">
      <?= Router::csrfField() ?>
      <div class="col-12 col-md-4">
        <label for="wx-tpl" class="form-label fw-semibold">Szablon</label>
        <select id="wx-tpl" name="tpl_key" class="form-select" required>
          <?php foreach ($templates as $t): if (!(int)$t['active']) continue; ?>
            <option value="<?= h($t['tpl_key']) ?>"><?= h($t['label']) ?> (<?= (int)WhatsappService::paramCount((string)$t['body']) ?> par.)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label for="wx-phone" class="form-label fw-semibold">Numer odbiorcy</label>
        <input type="tel" id="wx-phone" name="phone" class="form-control" required placeholder="600 700 800 albo +48…" autocomplete="off">
      </div>
      <div class="col-12 col-md-4">
        <label for="wx-par" class="form-label fw-semibold">Parametry <span class="fw-normal text-muted">(jeden w wierszu)</span></label>
        <textarea id="wx-par" name="params_text" rows="3" class="form-control" aria-describedby="wx-par-help"></textarea>
        <div id="wx-par-help" class="form-text">Pierwszy wiersz = {{1}}, drugi = {{2}}…</div>
      </div>
      <div class="col-12 d-flex flex-column gap-1">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="wx-cons" name="consent" value="1"><label class="form-check-label" for="wx-cons">Odbiorca wyraził zgodę (wymagane dla szablonu marketingowego)</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="wx-sms" name="allow_sms" value="1"><label class="form-check-label" for="wx-sms">Pozwól na zapasowy SMS, jeśli WhatsApp zawiedzie (domyślnie test sprawdza sam WhatsApp)</label></div>
      </div>
      <div class="col-12"><button type="submit" class="btn btn-outline-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij próbną wiadomość</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="card shadow-sm mb-4" aria-labelledby="wa-h-log">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="wa-h-log">Ostatnie wiadomości</h2>
    <?php if (!$messages): ?><p class="text-muted mb-0">Jeszcze nic nie wysłano.</p><?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Szablon</th><th scope="col">Numer</th><th scope="col">Wynik</th><th scope="col">Szczegóły</th></tr></thead>
        <tbody>
        <?php foreach ($messages as $m): ?>
          <tr>
            <td class="small text-nowrap"><?= h($m['created_at']) ?></td>
            <td class="font-monospace small"><?= h($m['tpl_key']) ?></td>
            <td class="small"><?= h($m['number_tail']) ?></td>
            <td class="<?= h($statusClass[$m['status']] ?? '') ?>"><?= h($statusLabel[$m['status']] ?? $m['status']) ?></td>
            <td class="small"><?= $m['provider_msg_id'] ? 'id ' . h($m['provider_msg_id']) : '' ?><?= $m['error'] ? ' ' . h($m['error']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>
