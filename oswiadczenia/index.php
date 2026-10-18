<?php
/**
 * oswiadczenia/index.php — Moduł „Oświadczenia" (wolontariat).
 *
 * Jeden plik = widok + API AJAX (wzorzec ?_ajax=1 + XHR POST jak w strategy/
 * i CRM). Frontend: Tailwind CSS (CDN) + czysty JavaScript (Fetch API) —
 * lista oświadczeń wg statusu, modal z treścią + checkbox zapoznania się,
 * a następnie krok wpisania kodu autoryzacyjnego 2FA.
 *
 * Logika domenowa (szablony, generowanie/weryfikacja kodu, zapis podpisu
 * w formie dokumentowej): includes/oswiadczenia.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/oswiadczenia.php';

require_login();
require_module_enabled('oswiadczenia_enabled', 'Moduł Oświadczeń');

$user = current_user();
$uid  = (int)$user['id'];

// ── API AJAX ──────────────────────────────────────────────────────────────────
if (($_GET['_ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (array $d): never {
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Tylko POST.');
        csrf_check();
        $action  = (string)($_POST['action'] ?? '');
        $payload = json_decode((string)($_POST['payload'] ?? '{}'), true) ?: [];

        if ($action === 'list') {
            $out(['ok' => true, 'items' => osw_lista_dla_uzytkownika($uid)]);
        }

        if ($action === 'get') {
            $id  = (int)($payload['id'] ?? 0);
            $osw = osw_pobierz($id, $uid);
            if (!$osw) throw new RuntimeException('Nie znaleziono oświadczenia.');
            $out(['ok' => true, 'item' => $osw]);
        }

        if ($action === 'start_sign') {
            $id = (int)($payload['id'] ?? 0);
            $out(osw_rozpocznij_podpis($id, $uid));
        }

        if ($action === 'verify_sign') {
            $id  = (int)($payload['id'] ?? 0);
            $kod = trim((string)($payload['kod'] ?? ''));
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $out(osw_zweryfikuj_i_podpisz($id, $uid, $kod, $ip, $ua));
        }

        throw new RuntimeException('Nieznana akcja.');
    } catch (\Throwable $e) {
        http_response_code(422);
        $out(['ok' => false, 'error' => $e->getMessage()]);
    }
}

$org_name = function_exists('org_setting') ? (org_setting('org_name') ?: '') : '';
?><!DOCTYPE html>
<html lang="pl" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Oświadczenia<?= $org_name ? ' · ' . h($org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: { extend: {
    colors: { brand: {50:'#eff6ff',100:'#dbeafe',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af'} },
    fontFamily: { sans: ['system-ui','-apple-system','Segoe UI','Roboto','sans-serif'] },
  }}
};
</script>
<style>
  [hidden]{display:none!important}
  /* METRO jak System Tożsamości i Panel współpracownika: ciemny app-bar, Segoe UI, 0 zaokrągleń i cieni */
  body{font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif}
  .m-bar{background:#1d1d1d;color:#fff}
  .m-bar a{color:rgba(255,255,255,.85)} .m-bar a:hover{color:#fff;background:rgba(255,255,255,.1)}
  .m-kicker{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:#5b6472}
  .m-title{font-size:clamp(1.9rem,4vw,2.5rem);font-weight:300;letter-spacing:-.02em;line-height:1.05;margin:.3rem 0 0;color:#111827}
  .m-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));grid-auto-rows:120px;gap:8px}
  .m-tile{display:flex;flex-direction:column;justify-content:space-between;padding:14px 16px;color:#fff;text-decoration:none;background:var(--c)}
  .m-tile:hover{filter:brightness(1.1)} .m-tile:focus-visible{outline:3px solid #fbbf24;outline-offset:-3px}
  .m-tile .val{font-size:1.85rem;font-weight:300;line-height:1}
  .m-tile .lbl{font-size:.84rem;font-weight:600}
  .m-h2{font-size:1.35rem;font-weight:300;color:#111827;margin:0 0 .75rem}
  .m-h2 b{font-weight:600}
  .m-row{display:flex;align-items:center;gap:.9rem;background:#f2f2f2;border-left:8px solid var(--c);padding:.9rem 1.1rem}
  .m-btn{background:#0f6fa8;color:#fff;border:2px solid #0f6fa8;padding:.45rem 1rem;font-weight:600;font-size:.88rem;min-height:40px}
  .m-btn:hover{filter:brightness(1.1)} .m-btn:disabled{opacity:.5;cursor:not-allowed}
  .m-btn--ghost{background:transparent;color:#111827;border-color:#111827}
  .m-btn--ghost:hover{background:#111827;color:#fff;filter:none}
  .m-badge{display:inline-flex;padding:.15rem .5rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;background:#007a00}
  .m-empty{background:#f2f2f2;padding:1.5rem 1rem;text-align:center;color:#5b6472;font-size:.9rem}
  *:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
  @media (prefers-reduced-motion: reduce){ *{transition:none!important;animation:none!important} }
</style>
</head>
<body class="h-full bg-white text-slate-800 antialiased">

<header class="m-bar sticky top-0 z-30">
  <div class="mx-auto flex max-w-screen-lg flex-wrap items-center gap-3 px-4 py-3">
    <a href="<?= h(APP_URL) ?>/panel/index.php" class="flex items-center gap-2 px-2 py-1 text-sm no-underline">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>Panel współpracownika
    </a>
    <span class="ml-auto text-sm" style="color:rgba(255,255,255,.75)"><?= h($user['name'] ?? $user['email'] ?? '') ?></span>
    <?php if (function_exists('is_admin') && is_admin()): ?>
    <a href="<?= h(APP_URL) ?>/admin/oswiadczenia_settings.php" class="px-2 py-1" aria-label="Ustawienia modułu">
      <i class="bi bi-gear text-lg" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </div>
</header>

<main class="mx-auto max-w-screen-lg px-4 py-6" x-data="false">

  <div class="mb-6">
    <div class="m-kicker">Panel współpracownika<?= $org_name ? ' · ' . h($org_name) : '' ?></div>
    <h1 class="m-title">Oświadczenia</h1>
    <p class="mt-2 mb-0 text-slate-600" style="font-weight:300">Przeczytaj i podpisz oświadczenia wymagane przez organizację — kodem SMS albo e-mail.</p>
  </div>

  <div class="m-grid mb-8" aria-label="Podsumowanie">
    <a href="#sek-oczekujace" class="m-tile" id="kafelOczekujace" style="--c:#c25100">
      <i class="bi bi-pen" style="font-size:1.8rem" aria-hidden="true"></i>
      <span><span class="val" id="liczOczekujace">–</span><span class="lbl block">do podpisania</span></span>
    </a>
    <a href="#sek-podpisane" class="m-tile" style="--c:#007a00">
      <i class="bi bi-patch-check" style="font-size:1.8rem" aria-hidden="true"></i>
      <span><span class="val" id="liczPodpisane">–</span><span class="lbl block">podpisane</span></span>
    </a>
  </div>

  <div id="flash" class="mb-4" hidden role="status"></div>

  <section class="mb-8" id="sek-oczekujace" aria-labelledby="h-ocz">
    <h2 class="m-h2" id="h-ocz">Do <b>podpisania</b></h2>
    <div id="listaOczekujace" class="grid gap-2"></div>
    <p id="pustoOczekujace" class="m-empty" hidden>
      Brak oświadczeń oczekujących na podpis.
    </p>
  </section>

  <section id="sek-podpisane" aria-labelledby="h-podp">
    <h2 class="m-h2" id="h-podp"><b>Podpisane</b></h2>
    <div id="listaPodpisane" class="grid gap-2"></div>
    <p id="pustoPodpisane" class="m-empty" hidden>
      Brak podpisanych oświadczeń.
    </p>
  </section>

</main>

<!-- ═══ Modal treści + podpisu ═══ -->
<div id="modalPodpis" class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" hidden>
  <div class="flex max-h-[90vh] w-full max-w-xl flex-col bg-white" role="dialog" aria-modal="true" aria-labelledby="modalTytul" style="border-top:6px solid #0f6fa8">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <h3 id="modalTytul" class="m-h2" style="margin:0"></h3>
      <button type="button" id="btnZamknij" class="text-slate-400 hover:text-slate-600" aria-label="Zamknij">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <div class="overflow-y-auto px-5 py-4">
      <!-- Krok 1: treść -->
      <div id="krok1">
        <div id="modalTresc" class="prose prose-sm max-w-none text-slate-700"></div>
        <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
          <input type="checkbox" id="chkZapoznanie" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
          Zapoznałem/-am się z treścią powyższego oświadczenia i regulaminu.
        </label>
        <div id="blad1" class="mt-2 text-sm text-red-600" hidden></div>
      </div>

      <!-- Krok 2: kod autoryzacyjny -->
      <div id="krok2" hidden>
        <p class="text-sm text-slate-600">
          Wysłaliśmy kod autoryzacyjny (<span id="kanalKodu"></span>). Wpisz go poniżej, aby potwierdzić podpis.
        </p>
        <div id="kodDevBox" class="mt-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-700" hidden></div>
        <input type="text" id="poleKod" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
               class="mt-3 w-40 border-2 border-slate-500 px-3 py-2 text-center text-2xl tracking-[0.4em] focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
               placeholder="——————">
        <div id="blad2" class="mt-2 text-sm text-red-600" hidden></div>
        <button type="button" id="btnWyslijPonownie" class="mt-3 text-sm text-brand-600 hover:underline">
          Wyślij kod ponownie
        </button>
      </div>

      <!-- Potwierdzenie -->
      <div id="krok3" hidden class="py-6 text-center">
        <i class="bi bi-patch-check-fill text-5xl text-emerald-500" aria-hidden="true"></i>
        <p class="mt-3 text-base font-medium text-slate-800">Oświadczenie zostało podpisane.</p>
        <p id="podpisanoInfo" class="mt-1 text-xs text-slate-500"></p>
      </div>
    </div>

    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
      <button type="button" id="btnAnuluj" class="m-btn m-btn--ghost">Anuluj</button>
      <button type="button" id="btnDalej" class="m-btn" disabled>
        Rozpocznij podpisywanie
      </button>
      <button type="button" id="btnPotwierdz" hidden class="m-btn" disabled>
        Potwierdź podpis
      </button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const AJAX_URL = <?= json_encode(APP_URL . '/oswiadczenia/index.php?_ajax=1') ?>;

async function apiCall(action, payload = {}) {
  const body = new URLSearchParams({ _csrf: CSRF, action, payload: JSON.stringify(payload) });
  const resp = await fetch(AJAX_URL, { method: 'POST', body });
  return resp.json();
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function pokazFlash(typ, tekst) {
  const el = document.getElementById('flash');
  const kolory = typ === 'error'
    ? 'border-red-200 bg-red-50 text-red-700'
    : 'border-emerald-200 bg-emerald-50 text-emerald-700';
  el.className = `mb-4 border-l-8 px-4 py-3 text-sm ${kolory}`;
  el.textContent = tekst;
  el.hidden = false;
  setTimeout(() => { el.hidden = true; }, 6000);
}

// ── Stan bieżącego oświadczenia w modalu ─────────────────────────────────────
let biezace = null;

function karta(o) {
  const podpisane = o.status === 'podpisane';
  const data = o.podpisano_at ? new Date(o.podpisano_at.replace(' ', 'T')).toLocaleDateString('pl-PL') : '';
  return `
    <article class="m-row" style="--c:${podpisane ? '#007a00' : '#c25100'}">
      <i class="bi ${podpisane ? 'bi-file-earmark-check' : 'bi-file-earmark-text'} text-2xl text-slate-800" aria-hidden="true"></i>
      <div class="flex-1" style="min-width:0">
        <div class="font-semibold text-slate-900">${escapeHtml(o.tytul)}</div>
        ${podpisane ? `<div class="text-xs text-slate-500">Podpisano: ${data}</div>` : `<div class="text-xs text-slate-500">Wersja ${escapeHtml(o.wersja)}</div>`}
      </div>
      ${podpisane
        ? `<span class="m-badge">Podpisane</span>`
        : `<button data-id="${o.id}" class="btnPodpisz m-btn" aria-label="Podpisz: ${escapeHtml(o.tytul)}">Podpisz</button>`}
    </article>`;
}

async function odswiezListe() {
  const r = await apiCall('list');
  if (!r.ok) { pokazFlash('error', r.error || 'Błąd wczytywania listy.'); return; }

  const oczekujace = r.items.filter(o => o.status !== 'podpisane');
  const podpisane  = r.items.filter(o => o.status === 'podpisane');

  document.getElementById('listaOczekujace').innerHTML = oczekujace.map(karta).join('');
  document.getElementById('pustoOczekujace').hidden = oczekujace.length > 0;
  document.getElementById('listaPodpisane').innerHTML = podpisane.map(karta).join('');
  document.getElementById('pustoPodpisane').hidden = podpisane.length > 0;
  document.getElementById('liczOczekujace').textContent = oczekujace.length;
  document.getElementById('liczPodpisane').textContent = podpisane.length;
  document.getElementById('kafelOczekujace').style.setProperty('--c', oczekujace.length ? '#c25100' : '#5f5f5f');

  document.querySelectorAll('.btnPodpisz').forEach(btn => {
    btn.addEventListener('click', () => otworzModal(parseInt(btn.dataset.id, 10)));
  });
}

function resetModal() {
  document.getElementById('krok1').hidden = false;
  document.getElementById('krok2').hidden = true;
  document.getElementById('krok3').hidden = true;
  document.getElementById('btnDalej').hidden = false;
  document.getElementById('btnPotwierdz').hidden = true;
  document.getElementById('btnAnuluj').hidden = false;
  document.getElementById('chkZapoznanie').checked = false;
  document.getElementById('btnDalej').disabled = true;
  document.getElementById('poleKod').value = '';
  document.getElementById('blad1').hidden = true;
  document.getElementById('blad2').hidden = true;
  document.getElementById('kodDevBox').hidden = true;
}

async function otworzModal(id) {
  const r = await apiCall('get', { id });
  if (!r.ok) { pokazFlash('error', r.error || 'Nie udało się wczytać oświadczenia.'); return; }

  biezace = r.item;
  resetModal();
  document.getElementById('modalTytul').textContent = r.item.tytul;
  document.getElementById('modalTresc').innerHTML = r.item.tresc; // treść zaufana (szablon organizacji, nie wejście użytkownika)
  document.getElementById('modalPodpis').hidden = false;
}

function zamknijModal() {
  document.getElementById('modalPodpis').hidden = true;
  biezace = null;
}

document.getElementById('chkZapoznanie').addEventListener('change', e => {
  document.getElementById('btnDalej').disabled = !e.target.checked;
});

async function wyslijKod() {
  document.getElementById('btnDalej').disabled = true;
  const r = await apiCall('start_sign', { id: biezace.id });
  document.getElementById('btnDalej').disabled = false;

  if (!r.ok) {
    const el = document.getElementById('blad1');
    el.textContent = r.error || 'Nie udało się wysłać kodu.';
    el.hidden = false;
    return;
  }

  document.getElementById('krok1').hidden = true;
  document.getElementById('krok2').hidden = false;
  document.getElementById('btnDalej').hidden = true;
  document.getElementById('btnPotwierdz').hidden = false;
  document.getElementById('btnPotwierdz').disabled = false;
  document.getElementById('kanalKodu').textContent = r.kanal === 'sms' ? 'SMS-em' : 'e-mailem';
  if (r.kod_dev) {
    const box = document.getElementById('kodDevBox');
    box.textContent = `Tryb deweloperski — kod: ${r.kod_dev}`;
    box.hidden = false;
  }
  document.getElementById('poleKod').focus();
}

document.getElementById('btnDalej').addEventListener('click', wyslijKod);
document.getElementById('btnWyslijPonownie').addEventListener('click', wyslijKod);

document.getElementById('btnPotwierdz').addEventListener('click', async () => {
  const kod = document.getElementById('poleKod').value.trim();
  if (!/^\d{6}$/.test(kod)) {
    const el = document.getElementById('blad2');
    el.textContent = 'Wpisz 6-cyfrowy kod.';
    el.hidden = false;
    return;
  }

  document.getElementById('btnPotwierdz').disabled = true;
  const r = await apiCall('verify_sign', { id: biezace.id, kod });
  document.getElementById('btnPotwierdz').disabled = false;

  if (!r.ok) {
    const el = document.getElementById('blad2');
    el.textContent = r.error || 'Nie udało się zweryfikować kodu.';
    el.hidden = false;
    return;
  }

  document.getElementById('krok2').hidden = true;
  document.getElementById('krok3').hidden = false;
  document.getElementById('btnPotwierdz').hidden = true;
  document.getElementById('btnAnuluj').hidden = true;
  document.getElementById('podpisanoInfo').textContent =
    `Data: ${new Date(r.podpisano_at.replace(' ', 'T')).toLocaleString('pl-PL')}`;

  await odswiezListe();
});

document.getElementById('btnZamknij').addEventListener('click', zamknijModal);
document.getElementById('btnAnuluj').addEventListener('click', zamknijModal);
document.getElementById('modalPodpis').addEventListener('click', e => {
  if (e.target === e.currentTarget) zamknijModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !document.getElementById('modalPodpis').hidden) zamknijModal();
});

odswiezListe();
</script>
</body>
</html>
