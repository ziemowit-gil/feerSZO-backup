<?php
/**
 * Generator stopek e-mail — każdy zalogowany pracownik widzi własną stopkę;
 * administrator edytuje dane organizacji i treść promocyjną dla wszystkich.
 * Logowanie: standardowe logowanie SZO (Microsoft / konto lokalne).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once __DIR__ . '/logic/stopka.php';
require_once __DIR__ . '/logic/stopkaExchange.php';

require_login();
stopkaExMigrate();
$user = current_user();
$self = APP_URL . '/modules/stopka/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['_action'] ?? '');
    if ($do === 'save_profile') {
        stopkaSaveProfile((int)$user['id'], trim((string)($_POST['job_title'] ?? '')), trim((string)($_POST['phone'] ?? '')));
        flash_set('success', 'Dane do stopki zapisane.');
    } elseif ($do === 'graph_sync') {
        [$msg, $err] = stopkaSyncFromGraph($user);
        flash_set($err ? 'danger' : 'success', $msg);
    } elseif ($do === 'save_org' && is_admin()) {
        db_exec("UPDATE stopka_settings SET org_name=?, address=?, nip=?, krs=?, website=?, promo_html=? WHERE id=1", [
            trim((string)($_POST['org_name'] ?? '')), trim((string)($_POST['address'] ?? '')),
            trim((string)($_POST['nip'] ?? '')), trim((string)($_POST['krs'] ?? '')),
            trim((string)($_POST['website'] ?? '')), stopkaSanitizePromo((string)($_POST['promo_html'] ?? '')),
        ]);
        flash_set('success', 'Ustawienia organizacji zapisane.');
        if (!empty(stopkaExSettings()['ex_enabled'])) {
            [$m, $e] = stopkaExPush();
            flash_set($e ? 'danger' : 'success', 'Exchange: ' . $m);
        }
    } elseif ($do === 'save_exchange' && is_admin()) {
        db_exec("UPDATE stopka_settings SET ex_enabled=?, ex_mode=?, ex_phone_attr=? WHERE id=1", [
            !empty($_POST['ex_enabled']) ? 1 : 0,
            ($_POST['ex_mode'] ?? '') === 'Enforce' ? 'Enforce' : 'Audit',
            ($_POST['ex_phone_attr'] ?? '') === 'PhoneNumber' ? 'PhoneNumber' : 'MobilePhone',
        ]);
        if (!empty($_POST['ex_enabled'])) {
            [$m, $e] = stopkaExPush();
            flash_set($e ? 'danger' : 'success', 'Exchange: ' . $m);
        } else {
            flash_set('success', 'Synchronizacja z Exchange wyłączona (istniejąca reguła zostaje w Exchange — wyłącz ją tam ręcznie, jeśli trzeba).');
        }
    }
    header('Location: ' . $self);
    exit;
}

$PAGE_TITLE = 'Stopka e-mail';
$org  = stopkaSettings();
$me   = stopkaProfile($user);
$data = ['org' => $org, 'me' => $me];
$inp  = 'tw-w-full tw-rounded tw-border tw-border-solid tw-border-slate-300 tw-px-3 tw-py-2 tw-bg-white';
$lbl  = 'tw-block tw-text-sm tw-font-medium tw-mb-1';
$btn  = 'tw-rounded tw-bg-blue-700 tw-text-white tw-px-4 tw-py-2 hover:tw-bg-blue-800 tw-border-0';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="tw-max-w-5xl tw-mx-auto" x-data='stopkaGen(<?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
  <h1 class="tw-text-2xl tw-font-semibold tw-mb-4">Stopka e-mail</h1>
  <div class="tw-grid md:tw-grid-cols-2 tw-gap-6">
    <section class="tw-bg-white tw-rounded tw-shadow tw-p-5 tw-space-y-3" aria-labelledby="h-me">
      <h2 id="h-me" class="tw-text-lg tw-font-semibold">Twoje dane</h2>
      <p class="tw-text-sm tw-text-slate-600">Imię, nazwisko i e-mail pochodzą z Twojego konta: <strong><?= h($me['first_name'] . ' ' . $me['last_name']) ?></strong>, <?= h($me['email']) ?>.</p>
      <form method="post" class="tw-space-y-3">
        <?= csrf_field() ?><input type="hidden" name="_action" value="save_profile">
        <div><label class="<?= $lbl ?>" for="p3">Stanowisko</label><input id="p3" name="job_title" x-model="me.job_title" class="<?= $inp ?>"></div>
        <div><label class="<?= $lbl ?>" for="p4">Telefon</label><input id="p4" name="phone" x-model="me.phone" class="<?= $inp ?>"></div>
        <button class="<?= $btn ?>">Zapisz</button>
      </form>
      <form method="post" class="tw-border-t tw-border-solid tw-border-slate-200 tw-pt-3">
        <?= csrf_field() ?><input type="hidden" name="_action" value="graph_sync">
        <p class="tw-text-xs tw-text-slate-500 tw-mb-2">Pobiera stanowisko i telefon z Twojego profilu Microsoft 365.</p>
        <button class="tw-rounded tw-bg-slate-700 tw-text-white tw-px-4 tw-py-2 hover:tw-bg-slate-800 tw-border-0">Synchronizuj z Microsoft 365</button>
      </form>
    </section>

    <section class="tw-bg-white tw-rounded tw-shadow tw-p-5 tw-space-y-3" aria-labelledby="h-prev">
      <h2 id="h-prev" class="tw-text-lg tw-font-semibold">Podgląd stopki</h2>
      <div class="tw-border tw-border-solid tw-border-slate-200 tw-rounded tw-p-4 tw-overflow-x-auto tw-bg-white" x-html="html()" aria-live="polite"></div>
      <button type="button" @click="copy()" class="<?= $btn ?>" x-text="copied ? 'Skopiowano ✔' : 'Kopiuj stopkę do schowka'"></button>
      <p class="tw-text-xs tw-text-slate-500" x-show="fail" x-cloak>Przeglądarka zablokowała schowek — zaznacz podgląd ręcznie i skopiuj (Ctrl+C).</p>
    </section>
  </div>

  <?php if (is_admin()): ?>
  <section class="tw-bg-white tw-rounded tw-shadow tw-p-5 tw-mt-6" aria-labelledby="h-org">
    <h2 id="h-org" class="tw-text-lg tw-font-semibold tw-mb-3">Administrator — dane organizacji</h2>
    <form method="post" class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save_org">
      <div><label class="<?= $lbl ?>" for="o1">Nazwa organizacji</label><input id="o1" name="org_name" x-model="org.org_name" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>" for="o5">Strona WWW</label><input id="o5" name="website" x-model="org.website" class="<?= $inp ?>" placeholder="https://"></div>
      <div class="sm:tw-col-span-2"><label class="<?= $lbl ?>" for="o2">Adres</label><input id="o2" name="address" x-model="org.address" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>" for="o3">NIP</label><input id="o3" name="nip" x-model="org.nip" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>" for="o4">KRS</label><input id="o4" name="krs" x-model="org.krs" class="<?= $inp ?>"></div>
      <div class="sm:tw-col-span-2"><label class="<?= $lbl ?>" for="o6">Treść promocyjna (HTML: a, b, i, u, br, p, span, img) — dołączana do stopek wszystkich</label>
        <textarea id="o6" name="promo_html" rows="4" x-model="org.promo_html" class="<?= $inp ?> tw-font-mono tw-text-sm"></textarea></div>
      <div class="sm:tw-col-span-2"><button class="<?= $btn ?>">Zapisz organizację</button>
        <span class="tw-text-xs tw-text-slate-500">Podgląd odświeża się na żywo; niedozwolone znaczniki zostaną usunięte przy zapisie.</span></div>
    </form>
  </section>

  <section class="tw-bg-white tw-rounded tw-shadow tw-p-5 tw-mt-6" aria-labelledby="h-ex">
    <h2 id="h-ex" class="tw-text-lg tw-font-semibold tw-mb-1">Automatyczna stopka w Outlooku (Exchange Online)</h2>
    <p class="tw-text-sm tw-text-slate-600 tw-mb-3">Jedna reguła transportowa dopisuje stopkę do każdej wysłanej wiadomości — w Outlooku w przeglądarce, klasycznym i na telefonach. Imię, stanowisko, telefon i e-mail Exchange bierze z Entra ID.</p>
    <?php $ex = stopkaExSettings(); ?>
    <form method="post" class="tw-space-y-3">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save_exchange">
      <label class="tw-flex tw-items-center tw-gap-2 tw-text-sm"><input type="checkbox" name="ex_enabled" value="1" <?= !empty($ex['ex_enabled']) ? 'checked' : '' ?>> Włącz synchronizację z Exchange (zapis ustawień od razu wypycha regułę)</label>
      <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
        <div><label class="<?= $lbl ?>" for="x1">Tryb reguły</label>
          <select id="x1" name="ex_mode" class="<?= $inp ?>">
            <option value="Audit" <?= ($ex['ex_mode'] ?? 'Audit') === 'Audit' ? 'selected' : '' ?>>Audit — tylko loguje, nie zmienia wiadomości (test)</option>
            <option value="Enforce" <?= ($ex['ex_mode'] ?? '') === 'Enforce' ? 'selected' : '' ?>>Enforce — dopisuje stopkę do wiadomości</option>
          </select></div>
        <div><label class="<?= $lbl ?>" for="x2">Telefon w stopce z atrybutu Entra ID</label>
          <select id="x2" name="ex_phone_attr" class="<?= $inp ?>">
            <option value="MobilePhone" <?= ($ex['ex_phone_attr'] ?? 'MobilePhone') === 'MobilePhone' ? 'selected' : '' ?>>Telefon komórkowy (mobilePhone)</option>
            <option value="PhoneNumber" <?= ($ex['ex_phone_attr'] ?? '') === 'PhoneNumber' ? 'selected' : '' ?>>Telefon służbowy (businessPhones)</option>
          </select></div>
      </div>
      <button class="<?= $btn ?>">Zapisz i wypchnij do Exchange</button>
    </form>
    <?php if (!empty($ex['ex_last_sync'])): ?>
      <p class="tw-text-sm tw-mt-3" role="status"><strong>Ostatnia synchronizacja:</strong> <?= h($ex['ex_last_sync']) ?> — <?= h($ex['ex_last_msg']) ?></p>
    <?php endif; ?>
    <h3 class="tw-text-sm tw-font-semibold tw-mt-4 tw-mb-2">Podgląd stopki, jaką zobaczą odbiorcy (z Twoimi danymi)</h3>
    <div class="tw-border tw-border-solid tw-border-slate-200 tw-rounded tw-p-4 tw-overflow-x-auto"><?= stopkaExPreviewHtml($org, $me) ?></div>
    <p class="tw-text-xs tw-text-slate-500 tw-mt-2">Wymaga w Entra ID: uprawnienia aplikacji Exchange.ManageAsApp (zgoda admina) i roli Exchange Administrator dla tej aplikacji. Odświeżanie z CLI: <code>php cli/stopkaExchangeSync.php</code>.</p>
  </section>
  <?php endif; ?>
</div>

<script>
function stopkaGen(d) {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  return {
    me: d.me, org: d.org, copied: false, fail: false,
    html() {
      const m = this.me, o = this.org, f = 'font-family:Arial,Helvetica,sans-serif;color:#222;';
      const name = esc((m.first_name + ' ' + m.last_name).trim());
      const contact = [m.phone ? 'tel. ' + esc(m.phone) : '', m.email ? '<a href="mailto:' + esc(m.email) + '" style="color:#1d4ed8;text-decoration:none;">' + esc(m.email) + '</a>' : ''].filter(Boolean).join(' &nbsp;|&nbsp; ');
      const row = (t, st) => t ? '<div style="' + st + '">' + t + '</div>' : '';
      const ids = (lbl, v) => v ? '<div style="font-size:12px;line-height:1.5;">' + lbl + ' ' + esc(v) + '</div>' : '';
      const url = /^https?:\/\//i.test(o.website) ? o.website : (o.website ? 'https://' + o.website : '');
      const site = url ? '<div style="font-size:12px;line-height:1.5;"><a href="' + esc(url) + '" style="color:#1d4ed8;text-decoration:none;">' + esc(o.website) + '</a></div>' : '';
      const promo = o.promo_html && o.promo_html.trim()
        ? '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #999;font-size:12px;line-height:1.5;color:#444;">' + this.safePromo(o.promo_html) + '</div>' : '';
      return '<div style="' + f + 'font-size:13px;line-height:1.5;">'
        + row(name, 'font-size:16px;font-weight:bold;line-height:1.4;')
        + row(esc(m.job_title), 'font-size:13px;')
        + row(contact, 'font-size:13px;')
        + row(esc(o.org_name), 'margin-top:8px;font-size:13px;font-weight:bold;')
        + row(esc(o.address), 'font-size:12px;line-height:1.5;')
        + ids('NIP:', o.nip) + ids('KRS:', o.krs) + site + promo + '</div>';
    },
    safePromo(html) {
      const doc = new DOMParser().parseFromString('<div id="r">' + html + '</div>', 'text/html');
      const ok = {A:['href'],B:[],STRONG:[],I:[],EM:[],U:[],BR:[],P:[],SPAN:[],IMG:['src','alt','width','height']};
      const walk = n => [...n.children].forEach(c => {
        if (!ok[c.tagName]) { c.remove(); return; }
        [...c.attributes].forEach(a => {
          if (!ok[c.tagName].includes(a.name) || (['href','src'].includes(a.name) && !/^(https?:|mailto:)/i.test(a.value.trim()))) c.removeAttribute(a.name);
        });
        walk(c);
      });
      walk(doc.getElementById('r'));
      return doc.getElementById('r').innerHTML;
    },
    async copy() {
      const html = this.html(), text = new DOMParser().parseFromString(html.replace(/<\/div>/g, '\n'), 'text/html').body.textContent.trim();
      this.fail = false;
      try {
        await navigator.clipboard.write([new ClipboardItem({
          'text/html': new Blob([html], {type: 'text/html'}), 'text/plain': new Blob([text], {type: 'text/plain'})})]);
      } catch (e) {
        try {
          const box = document.createElement('div'); box.innerHTML = html; box.style.cssText = 'position:fixed;left:-9999px;';
          document.body.appendChild(box);
          const r = document.createRange(); r.selectNodeContents(box);
          const s = getSelection(); s.removeAllRanges(); s.addRange(r);
          const ok = document.execCommand('copy'); s.removeAllRanges(); box.remove();
          if (!ok) throw e;
        } catch (e2) { this.fail = true; return; }
      }
      this.copied = true; setTimeout(() => this.copied = false, 2500);
    }
  };
}
</script>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
