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
    } elseif ($do === 'save_design' && is_admin()) {
        $d = stopkaDesignSanitize(json_decode((string)($_POST['design_json'] ?? ''), true));
        db_exec("UPDATE stopka_settings SET design_json=? WHERE id=1", [json_encode($d, JSON_UNESCAPED_UNICODE)]);
        flash_set('success', 'Wygląd stopki zapisany.');
        if (!empty(stopkaExSettings()['ex_enabled'])) {
            [$m, $e] = stopkaExPush();
            flash_set($e ? 'danger' : 'success', 'Wygląd zapisany. Exchange: ' . $m);
        }
    } elseif ($do === 'check_exchange' && is_admin()) {
        $_SESSION['stopka_check'] = stopkaExCheck();
        $_SESSION['stopka_check_at'] = date('Y-m-d H:i:s');
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
$data = ['org' => $org, 'me' => $me, 'design' => $org['design'], 'defaults' => stopkaDesignDefaults(), 'fonts' => STOPKA_FONTS];
$check = $_SESSION['stopka_check'] ?? null; $checkAt = $_SESSION['stopka_check_at'] ?? ''; unset($_SESSION['stopka_check'], $_SESSION['stopka_check_at']);
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

  <section class="tw-bg-white tw-rounded tw-shadow tw-p-5 tw-mt-6" aria-labelledby="h-cfg">
    <h2 id="h-cfg" class="tw-text-lg tw-font-semibold tw-mb-3">Konfigurator wyglądu stopki</h2>
    <form method="post" class="tw-grid lg:tw-grid-cols-2 tw-gap-6">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save_design">
      <input type="hidden" name="design_json" :value="JSON.stringify(design)">
      <div class="tw-space-y-4">
        <fieldset class="tw-grid tw-grid-cols-2 tw-gap-3 tw-border-0 tw-p-0 tw-m-0">
          <legend class="tw-text-sm tw-font-semibold tw-mb-2">Czcionka i rozmiary</legend>
          <div class="tw-col-span-2"><label class="<?= $lbl ?>" for="d1">Czcionka</label>
            <select id="d1" x-model="design.font" class="<?= $inp ?>"><template x-for="f in fonts" :key="f"><option :value="f" x-text="f"></option></template></select></div>
          <div><label class="<?= $lbl ?>" for="d2">Rozmiar tekstu: <span x-text="design.size"></span> px</label>
            <input id="d2" type="range" min="11" max="16" x-model.number="design.size" class="tw-w-full"></div>
          <div><label class="<?= $lbl ?>" for="d3">Rozmiar imienia i nazwiska: <span x-text="design.nameSize"></span> px</label>
            <input id="d3" type="range" min="13" max="24" x-model.number="design.nameSize" class="tw-w-full"></div>
          <div><label class="<?= $lbl ?>" for="d4">Odstęp między blokami: <span x-text="design.gap"></span> px</label>
            <input id="d4" type="range" min="0" max="24" x-model.number="design.gap" class="tw-w-full"></div>
        </fieldset>
        <fieldset class="tw-grid tw-grid-cols-2 tw-gap-3 tw-border-0 tw-p-0 tw-m-0">
          <legend class="tw-text-sm tw-font-semibold tw-mb-2">Kolory</legend>
          <div><label class="<?= $lbl ?>" for="d5">Tekst</label><input id="d5" type="color" x-model="design.color" class="tw-h-10 tw-w-full"></div>
          <div><label class="<?= $lbl ?>" for="d6">Linki i akcent</label><input id="d6" type="color" x-model="design.accent" class="tw-h-10 tw-w-full"></div>
          <label class="tw-col-span-2 tw-flex tw-items-center tw-gap-2 tw-text-sm"><input type="checkbox" x-model="design.nameAccent"> Imię i nazwisko w kolorze akcentu</label>
        </fieldset>
        <fieldset class="tw-grid tw-grid-cols-2 tw-gap-3 tw-border-0 tw-p-0 tw-m-0">
          <legend class="tw-text-sm tw-font-semibold tw-mb-2">Linia oddzielająca treść promocyjną</legend>
          <div><label class="<?= $lbl ?>" for="d7">Styl</label>
            <select id="d7" x-model="design.sepStyle" class="<?= $inp ?>">
              <option value="solid">Ciągła</option><option value="dashed">Kreskowana</option><option value="dotted">Kropkowana</option><option value="none">Bez linii</option>
            </select></div>
          <div><label class="<?= $lbl ?>" for="d8">Kolor linii</label><input id="d8" type="color" x-model="design.sepColor" class="tw-h-10 tw-w-full"></div>
        </fieldset>
        <fieldset class="tw-grid tw-grid-cols-2 tw-gap-3 tw-border-0 tw-p-0 tw-m-0">
          <legend class="tw-text-sm tw-font-semibold tw-mb-2">Logo (opcjonalnie)</legend>
          <div class="tw-col-span-2"><label class="<?= $lbl ?>" for="d9">Adres obrazka (https://…, PNG/JPG hostowany publicznie)</label>
            <input id="d9" type="url" x-model="design.logoUrl" class="<?= $inp ?>" placeholder="https://"></div>
          <div class="tw-col-span-2"><label class="<?= $lbl ?>" for="d10">Szerokość logo: <span x-text="design.logoWidth"></span> px</label>
            <input id="d10" type="range" min="40" max="300" x-model.number="design.logoWidth" class="tw-w-full"></div>
        </fieldset>
        <fieldset class="tw-border-0 tw-p-0 tw-m-0">
          <legend class="tw-text-sm tw-font-semibold tw-mb-2">Elementy stopki</legend>
          <div class="tw-grid tw-grid-cols-2 tw-gap-2 tw-text-sm">
            <template x-for="[k,l] in shows" :key="k"><label class="tw-flex tw-items-center tw-gap-2"><input type="checkbox" x-model="design.show[k]"> <span x-text="l"></span></label></template>
          </div>
        </fieldset>
        <div class="tw-flex tw-gap-3">
          <button class="<?= $btn ?>">Zapisz wygląd</button>
          <button type="button" @click="design = JSON.parse(JSON.stringify(defaults))" class="tw-rounded tw-bg-white tw-text-slate-800 tw-border tw-border-solid tw-border-slate-400 tw-px-4 tw-py-2">Przywróć domyślny</button>
        </div>
      </div>
      <div class="lg:tw-sticky lg:tw-top-20 tw-self-start">
        <h3 class="tw-text-sm tw-font-semibold tw-mb-2">Podgląd na żywo (z Twoimi danymi)</h3>
        <label class="tw-flex tw-items-center tw-gap-2 tw-text-sm tw-mb-2"><input type="checkbox" x-model="dark"> Ciemne tło poczty</label>
        <div class="tw-border tw-border-solid tw-border-slate-200 tw-rounded tw-p-4 tw-overflow-x-auto" :style="dark ? 'background:#1f2937;color:#e5e7eb' : 'background:#fff'" x-html="html()" aria-live="polite"></div>
        <p class="tw-text-xs tw-text-slate-500 tw-mt-2">Zapis wyglądu aktualizuje też regułę w Exchange, jeśli synchronizacja jest włączona.</p>
      </div>
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
    <form method="post" class="tw-mt-3">
      <?= csrf_field() ?><input type="hidden" name="_action" value="check_exchange">
      <button class="tw-rounded tw-bg-white tw-text-slate-800 tw-border tw-border-solid tw-border-slate-400 tw-px-4 tw-py-2 hover:tw-bg-slate-50">Sprawdź uprawnienia</button>
      <span class="tw-text-xs tw-text-slate-500">Tylko odczyty — niczego nie zmienia w Exchange.</span>
    </form>
    <?php if ($check): ?>
    <div class="tw-mt-3 tw-border tw-border-solid tw-border-slate-200 tw-rounded" role="region" aria-label="Wynik sprawdzenia uprawnień">
      <p class="tw-text-xs tw-text-slate-500 tw-px-3 tw-pt-2">Sprawdzono: <?= h($checkAt) ?></p>
      <ul class="tw-list-none tw-m-0 tw-p-3 tw-space-y-2">
        <?php foreach ($check as [$label, $ok, $detail]): ?>
        <li class="tw-flex tw-gap-2 tw-text-sm">
          <span class="tw-shrink-0 tw-font-bold <?= $ok === true ? 'tw-text-green-700' : ($ok === false ? 'tw-text-red-700' : 'tw-text-slate-600') ?>" aria-hidden="true"><?= $ok === true ? '✔' : ($ok === false ? '✖' : 'ℹ') ?></span>
          <span><strong><?= h($label) ?></strong> <span class="tw-sr-only"><?= $ok === true ? '— w porządku.' : ($ok === false ? '— problem.' : '— informacja.') ?></span><br><span class="tw-text-slate-600"><?= h($detail) ?></span></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php if (!empty($ex['ex_last_sync'])): ?>
      <p class="tw-text-sm tw-mt-3" role="status"><strong>Ostatnia synchronizacja:</strong> <?= h($ex['ex_last_sync']) ?> — <?= h($ex['ex_last_msg']) ?></p>
    <?php endif; ?>
    <h3 class="tw-text-sm tw-font-semibold tw-mt-4 tw-mb-2">Podgląd stopki, jaką zobaczą odbiorcy (z Twoimi danymi)</h3>
    <div class="tw-border tw-border-solid tw-border-slate-200 tw-rounded tw-p-4 tw-overflow-x-auto"><?= stopkaExPreviewHtml($ex, $me) ?></div>
    <p class="tw-text-xs tw-text-slate-500 tw-mt-2">Wymaga w Entra ID: uprawnienia aplikacji Exchange.ManageAsApp (zgoda admina) i roli Exchange Administrator dla tej aplikacji. Reguła odświeża się też automatycznie raz na dobę w nocy (agent CRON <code>stopka_exchange_sync</code>); ręcznie: <code>php cron/stopka_exchange_sync.php</code>.</p>
  </section>
  <?php endif; ?>
</div>

<script>
function stopkaGen(d) {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  return {
    me: d.me, org: d.org, design: d.design, defaults: d.defaults, fonts: d.fonts, dark: false, copied: false, fail: false,
    shows: [['title','Stanowisko'],['phone','Telefon'],['email','E-mail'],['org','Nazwa organizacji'],['address','Adres'],['nip','NIP'],['krs','KRS'],['website','Strona WWW'],['promo','Treść promocyjna']],
    // Odpowiednik PHP: stopkaRenderHtml() w logic/stopka.php — zmieniając jedno, zmień drugie.
    html() {
      const m = this.me, o = this.org, d = this.design, sh = d.show, sm = d.size - 1;
      const row = (t, st) => t ? '<div style="' + st + '">' + t + '</div>' : '';
      const link = (href, label) => '<a href="' + href + '" style="color:' + d.accent + ';text-decoration:none;">' + label + '</a>';
      let h = '<div style="font-family:' + d.font + ',Arial,Helvetica,sans-serif;color:' + d.color + ';font-size:' + d.size + 'px;line-height:1.5;">';
      if (/^https:\/\/[^\s"'<>]+$/i.test(d.logoUrl || '')) {
        h += '<div style="margin-bottom:' + d.gap + 'px;"><img src="' + esc(d.logoUrl) + '" width="' + d.logoWidth + '" alt="' + esc(o.org_name) + '" style="display:block;border:0;"></div>';
      }
      h += row(esc((m.first_name + ' ' + m.last_name).trim()), 'font-size:' + d.nameSize + 'px;font-weight:bold;line-height:1.4;color:' + (d.nameAccent ? d.accent : d.color) + ';');
      if (sh.title) h += row(esc(m.job_title), 'font-size:' + d.size + 'px;');
      const parts = [];
      if (sh.phone && m.phone) parts.push('tel. ' + esc(m.phone));
      if (sh.email && m.email) parts.push(link('mailto:' + esc(m.email), esc(m.email)));
      h += row(parts.join(' &nbsp;|&nbsp; '), 'font-size:' + d.size + 'px;');
      if (sh.org) h += row(esc(o.org_name), 'margin-top:' + d.gap + 'px;font-size:' + d.size + 'px;font-weight:bold;');
      if (sh.address) h += row(esc(o.address), 'font-size:' + sm + 'px;');
      if (sh.nip && (o.nip || '').trim()) h += row('NIP: ' + esc(o.nip), 'font-size:' + sm + 'px;');
      if (sh.krs && (o.krs || '').trim()) h += row('KRS: ' + esc(o.krs), 'font-size:' + sm + 'px;');
      const url = (o.website || '').trim();
      if (sh.website && url) h += row(link(esc(/^https?:\/\//i.test(url) ? url : 'https://' + url), esc(url)), 'font-size:' + sm + 'px;');
      const promo = (o.promo_html || '').trim();
      if (sh.promo && promo) {
        const bd = d.sepStyle === 'none' ? '' : 'border-top:1px ' + d.sepStyle + ' ' + d.sepColor + ';padding-top:8px;';
        h += '<div style="margin-top:' + (d.gap + 2) + 'px;' + bd + 'font-size:' + sm + 'px;line-height:1.5;">' + this.safePromo(promo) + '</div>';
      }
      return h + '</div>';
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
