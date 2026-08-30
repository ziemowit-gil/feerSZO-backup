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
  @media (prefers-reduced-motion: reduce){ *{transition:none!important;animation:none!important} }
</style>
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased">

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
  <div class="mx-auto flex max-w-screen-lg flex-wrap items-center gap-3 px-4 py-3">
    <a href="<?= h(APP_URL) ?>/portal.php" class="flex items-center gap-2 text-slate-500 hover:text-brand-600"
       aria-label="Wróć do portalu SZO">
      <i class="bi bi-arrow-left-short text-xl" aria-hidden="true"></i>
    </a>
    <h1 class="text-lg font-semibold text-slate-800"><i class="bi bi-file-earmark-check mr-2 text-brand-600" aria-hidden="true"></i>Oświadczenia</h1>
    <span class="ml-auto text-sm text-slate-500"><?= h($user['name'] ?? $user['email'] ?? '') ?></span>
    <?php if (function_exists('is_admin') && is_admin()): ?>
    <a href="<?= h(APP_URL) ?>/admin/oswiadczenia_settings.php" class="text-slate-400 hover:text-brand-600" aria-label="Ustawienia modułu">
      <i class="bi bi-gear text-lg" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </div>
</header>

<main class="mx-auto max-w-screen-lg px-4 py-6" x-data="false">

  <div id="flash" class="mb-4" hidden></div>

  <section class="mb-8">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
      <i class="bi bi-exclamation-circle text-amber-500" aria-hidden="true"></i>Do podpisania
    </h2>
    <div id="listaOczekujace" class="grid gap-3"></div>
    <p id="pustoOczekujace" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500" hidden>
      Brak oświadczeń oczekujących na podpis.
    </p>
  </section>

  <section>
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
      <i class="bi bi-check-circle text-emerald-500" aria-hidden="true"></i>Podpisane
    </h2>
    <div id="listaPodpisane" class="grid gap-3"></div>
    <p id="pustoPodpisane" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500" hidden>
      Brak podpisanych oświadczeń.
    </p>
  </section>

</main>

<!-- ═══ Modal treści + podpisu ═══ -->
<div id="modalPodpis" class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" hidden>
  <div class="flex max-h-[90vh] w-full max-w-xl flex-col rounded-xl bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <h3 id="modalTytul" class="text-base font-semibold text-slate-800"></h3>
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
               class="mt-3 w-40 rounded-lg border border-slate-300 px-3 py-2 text-center text-2xl tracking-[0.4em] focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
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
      <button type="button" id="btnAnuluj" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Anuluj</button>
      <button type="button" id="btnDalej"
              class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50" disabled>
        Rozpocznij podpisywanie
      </button>
      <button type="button" id="btnPotwierdz" hidden
              class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50" disabled>
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
  el.className = `mb-4 rounded-lg border px-4 py-3 text-sm ${kolory}`;
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
    <article class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <i class="bi ${podpisane ? 'bi-file-earmark-check text-emerald-500' : 'bi-file-earmark-text text-amber-500'} text-xl" aria-hidden="true"></i>
      <div class="flex-1">
        <div class="text-sm font-medium text-slate-800">${escapeHtml(o.tytul)}</div>
        ${podpisane ? `<div class="text-xs text-slate-500">Podpisano: ${data}</div>` : `<div class="text-xs text-slate-500">Wersja ${escapeHtml(o.wersja)}</div>`}
      </div>
      ${podpisane
        ? `<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">Podpisane</span>`
        : `<button data-id="${o.id}" class="btnPodpisz rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Podpisz</button>`}
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
