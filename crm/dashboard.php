<?php
/**
 * crm/dashboard.php — pulpit CRM.
 *
 * Zasada układu: GÓRA = co wymaga reakcji, DÓŁ = jak wygląda baza.
 *
 * Poprzednia wersja zaczynała się od sześciu liczb inwentarzowych („106
 * kontaktów, 74 osoby, 30 firm”), z których żadna nie mówiła, co zrobić —
 * a trzy z nich mówiły to samo. Zaległe działanie, nieprzeczytana wiadomość
 * i sprawa bez ruchu od dwóch miesięcy nie miały gdzie się pokazać. Teraz
 * pierwszy ekran to lista rzeczy do zrobienia, a stan bazy zszedł na sam dół,
 * do jednego paska — bo sprawdza się go raz na kwartał, a nie codziennie rano.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_offers_migrate();

$PAGE_TITLE  = 'Dashboard CRM';
$user        = current_user();
$uid         = (int)($user['id'] ?? 0);
$can_write   = can_write('crm') || is_admin();
$can_mailing = can_write('crm_mailing') || is_admin();
$stats       = CrmManager::getStats();

$u_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (!$u_name) $u_name = explode(' ', $user['name'] ?? '')[0] ?? '';
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Witaj' : 'Dobry wieczór');

/** Skrót na policzenie czegokolwiek bez wywracania strony, gdy modułu nie ma. */
$count = function (string $sql, array $p = []): int {
    try { return (int)(db_one($sql, $p)['c'] ?? 0); } catch (\Throwable $e) { return 0; }
};

// ── WYMAGA REAKCJI ─────────────────────────────────────────────────────────
// Wszystko liczone z perspektywy ZALOGOWANEGO: „zaległe” to moje zaległe,
// nie sumaryczny dług całej organizacji, którego i tak nikt nie odrobi.
$acts_late = $count(
    "SELECT COUNT(*) AS c FROM crm_activities a
       JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
      WHERE a.status='planned' AND a.assigned_to=? AND a.scheduled_at IS NOT NULL
        AND date(a.scheduled_at) < date('now','localtime')", [$uid]);
$acts_today = $count(
    "SELECT COUNT(*) AS c FROM crm_activities a
       JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
      WHERE a.status='planned' AND a.assigned_to=? AND date(a.scheduled_at)=date('now','localtime')", [$uid]);

$inbox_unread = $count(
    "SELECT COUNT(*) AS c FROM crm_communications
      WHERE direction='in' AND is_read=0 AND inbox_status='active'");
$inbox_mine = $count(
    "SELECT COUNT(*) AS c FROM crm_communications
      WHERE direction='in' AND inbox_status='active' AND assigned_to=?", [$uid]);

$cases_open = $count("SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress')");
$cases_high = $count("SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress') AND priority='high'");
$cases_stale= $count(
    "SELECT COUNT(*) AS c FROM crm_cases
      WHERE status IN ('open','in_progress') AND updated_at < datetime('now', ?)",
    ['-' . CRM_CASE_STALE_DAYS . ' days']);

$offer_stats  = crm_offer_stats();
$offer_noconf = $count(
    "SELECT COUNT(*) AS c FROM crm_offers
      WHERE deleted_at IS NULL AND requires_confirmation=1 AND confirmation_id IS NULL
        AND status IN ('wyslana','zaakceptowana')");

// ── MOJE DZIAŁANIA (lista, nie tylko licznik) ──────────────────────────────
$my_acts = [];
try {
    $my_acts = db_all(
        "SELECT a.*, c.imie_nazwisko
           FROM crm_activities a
           JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
          WHERE a.status='planned' AND a.assigned_to=? AND a.scheduled_at IS NOT NULL
            AND date(a.scheduled_at) <= date('now','localtime')
          ORDER BY a.scheduled_at ASC LIMIT 8", [$uid]);
} catch (\Throwable $e) {}

// ── PORZĄDEK W BAZIE ───────────────────────────────────────────────────────
// Liczby liczone leniwie i w try — narzędzia higieny są nowe, a pulpit nie ma
// się wywalać na instalacji, w której czegoś jeszcze nie zmigrowano.
$no_owner = $count("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND (owner_id IS NULL OR owner_id=0)");
$dup_groups = 0; $dom_suggest = 0;
try {
    require_once dirname(__DIR__) . '/includes/crm_merge.php';
    require_once dirname(__DIR__) . '/includes/crm_domains.php';
    $dup_groups  = count(crm_find_duplicates(2000));
    $dom_suggest = count(crm_domain_org_suggestions());
} catch (\Throwable $e) {}

// ── STAN BAZY (na dół) ─────────────────────────────────────────────────────
$persons     = $count("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND type='osoba'");
$orgs        = $count("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND type<>'osoba'");
$comms_today = $count("SELECT COUNT(*) AS c FROM crm_communications WHERE DATE(sent_at)=DATE('now')");
$comms_week  = $count("SELECT COUNT(*) AS c FROM crm_communications WHERE sent_at >= DATE('now','-7 days')");

$status_counts = [];
try {
    foreach (db_all("SELECT status, COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 GROUP BY status") as $r) {
        $status_counts[$r['status']] = (int)$r['c'];
    }
} catch (\Throwable $e) {}

// ── LISTY ──────────────────────────────────────────────────────────────────
$case_status_cfg = [
    'open'        => ['label'=>'Otwarta', 'color'=>'#2563EB','bg'=>'#EEF4FF','icon'=>'bi-circle'],
    'in_progress' => ['label'=>'W toku',  'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-arrow-clockwise'],
];
$case_priority_cfg = [
    'low'    => ['label'=>'Niski',  'color'=>'#9CA3AF'],
    'medium' => ['label'=>'Średni', 'color'=>'#D97706'],
    'high'   => ['label'=>'Wysoki', 'color'=>'#DC2626'],
];
$open_cases = db_all(
    "SELECT c.*, ct.imie_nazwisko AS contact_name,
            (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=c.id) AS notes_count,
            (SELECT COUNT(*) FROM crm_case_files f WHERE f.case_id=c.id) AS files_count
       FROM crm_cases c
       LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
      WHERE c.status IN ('open','in_progress')
      ORDER BY CASE c.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END, c.updated_at DESC
      LIMIT 6"
);
$recent = db_all(
    "SELECT c.* FROM crm_contacts c WHERE c.crm_active=1 ORDER BY c.updated_at DESC LIMIT 6"
);
$recent_comms = db_all(
    "SELECT cc.*, ct.imie_nazwisko
       FROM crm_communications cc
       LEFT JOIN crm_contacts ct ON ct.id=cc.contact_id
      ORDER BY cc.sent_at DESC LIMIT 6"
);

/** Kafelek „wymaga reakcji". Zero też pokazujemy — wyszarzone. */
function crm_dash_tile(string $href, string $icon, string $color, string $bg,
                       int|string $value, string $label, string $note = '', string $note_color = ''): void
{
    $zero = ($value === 0 || $value === '0');
    ?>
    <div class="col-6 col-lg">
      <a href="<?= h($href) ?>" class="crm-tile<?= $zero ? ' crm-tile--zero' : '' ?>">
        <div class="crm-tile-ico" style="background:<?= h($bg) ?>;color:<?= h($color) ?>"><i class="bi <?= h($icon) ?>"></i></div>
        <div class="crm-tile-val"><?= is_int($value) ? number_format($value, 0, ',', ' ') : h($value) ?></div>
        <div class="crm-tile-lbl"><?= h($label) ?></div>
        <?php if ($note !== ''): ?>
        <div class="crm-tile-note" style="color:<?= h($note_color ?: '#6B7280') ?>"><?= h($note) ?></div>
        <?php endif; ?>
      </a>
    </div>
    <?php
}

include __DIR__ . '/includes/header_crm.php';
?>

<style>
/* ══ Wyszukiwarka pulpitu ═══════════════════════════════════════════════════
   Jedno pole zamiast wędrówki po sekcjach: kontakty, sprawy, oferty i poczta
   odpowiadają naraz. Klawisz „/" ustawia w nim kursor (includes/search_hotkey.php). */
.cs-wrap { position:relative; margin-bottom:1rem }
.cs-box { position:relative }
.cs-box i.cs-ico { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:#9CA3AF }
.cs-input {
  width:100%; height:48px; padding:0 1rem 0 2.6rem; font-size:.95rem; color:#111827;
  border:1px solid #E5E7EB; border-radius:12px; background:#fff;
}
.cs-input:focus { border-color:var(--crm-primary); box-shadow:0 0 0 4px rgba(1,118,211,.12); outline:none }
.cs-kbd { position:absolute; right:.8rem; top:50%; transform:translateY(-50%); font-size:.7rem;
  color:#9CA3AF; background:#F3F4F6; border:1px solid #E5E7EB; border-radius:5px; padding:.05rem .35rem }
.cs-drop { position:absolute; z-index:1050; top:calc(100% + 6px); left:0; right:0; background:#fff;
  border:1px solid #E5E7EB; border-radius:12px; box-shadow:0 14px 40px rgba(16,24,40,.14);
  max-height:60vh; overflow-y:auto; display:none; padding:.35rem }
.cs-drop.is-open { display:block }
.cs-grp { font-size:.66rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
  color:#9CA3AF; padding:.5rem .7rem .25rem; display:flex; align-items:center; gap:.35rem }
.cs-item { display:block; padding:.4rem .7rem; border-radius:8px; text-decoration:none; color:#111827 }
.cs-item:hover, .cs-item.is-on { background:#EFF6FF; color:#1D4ED8 }
.cs-item .cs-t { font-size:.87rem; font-weight:600; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.cs-item .cs-s { font-size:.75rem; color:#9CA3AF; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.cs-item .cs-flag { font-size:.66rem; font-weight:700; color:#B91C1C; background:#FEF2F2;
  border-radius:2rem; padding:0 .4rem; margin-left:.35rem }
.cs-empty { padding:1rem; text-align:center; color:#9CA3AF; font-size:.85rem }
</style>

<!-- ══ NAGŁÓWEK ══════════════════════════════════════════════════════════════ -->
<div class="crm-page-header mb-3">
  <div>
    <div class="crm-page-title"><span><?= h($greeting) ?>, <?= h($u_name ?: 'Użytkowniku') ?></span></div>
    <div class="crm-page-subtitle">
      CRM · <?= h(org_setting('org_name') ?: ORG_NAME) ?> · <?= date('d.m.Y') ?>
    </div>
  </div>
  <?php if ($can_write || $can_mailing): ?>
  <div class="crm-page-actions">
    <?php if ($can_write): ?>
    <div class="btn-group btn-group-sm">
      <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-crm-primary">
        <i class="bi bi-person-plus me-1"></i>Nowy kontakt
      </a>
      <button type="button" class="btn btn-crm-primary dropdown-toggle dropdown-toggle-split"
              data-bs-toggle="dropdown" aria-expanded="false">
        <span class="visually-hidden">Więcej opcji</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/add_person.php"><i class="bi bi-person me-2"></i>Osoba fizyczna</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/add_org.php?type=organizacja"><i class="bi bi-building me-2"></i>Organizacja</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/add_org.php?type=kontrahent"><i class="bi bi-briefcase me-2"></i>Kontrahent</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/contact/add_org.php?type=partner"><i class="bi bi-handshake me-2"></i>Partner</a></li>
      </ul>
    </div>
    <?php endif; ?>
    <?php if ($can_mailing): ?>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1"></i>Wyślij wiadomość
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ WYSZUKIWARKA ═════════════════════════════════════════════════════════ -->
<div class="cs-wrap">
  <div class="cs-box">
    <i class="bi bi-search cs-ico" aria-hidden="true"></i>
    <label class="visually-hidden" for="crmSearch">Szukaj w CRM</label>
    <input type="search" id="crmSearch" class="cs-input" data-search-input autocomplete="off"
           role="combobox" aria-expanded="false" aria-controls="crmSearchDrop"
           placeholder="Szukaj w CRM — kontakt, sprawa, oferta, wiadomość, NIP, numer…">
    <span class="cs-kbd" aria-hidden="true">/</span>
  </div>
  <div class="cs-drop" id="crmSearchDrop" role="listbox" aria-label="Wyniki wyszukiwania"></div>
</div>

<script>
/* Wyszukiwarka pulpitu — jedno zapytanie do crm/api/search.php, wyniki grupowane
   po sekcjach. Strzałki i Enter działają bez myszy. */
(function () {
  var inp  = document.getElementById('crmSearch');
  var drop = document.getElementById('crmSearchDrop');
  if (!inp || !drop) return;

  var timer = null, flat = [], active = -1;

  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function close() { drop.classList.remove('is-open'); inp.setAttribute('aria-expanded','false'); active = -1; }

  function paint(groups, q) {
    flat = [];
    if (!groups.length) {
      drop.innerHTML = '<div class="cs-empty">Nic nie pasuje do „' + esc(q) + '"</div>';
      drop.classList.add('is-open');
      return;
    }
    var html = '';
    groups.forEach(function (g) {
      html += '<div class="cs-grp"><i class="bi ' + esc(g.icon) + '"></i>' + esc(g.label) + '</div>';
      g.items.forEach(function (it) {
        var i = flat.length;
        flat.push(it);
        html += '<a class="cs-item" data-i="' + i + '" href="' + esc(it.url) + '" role="option">'
              + '<span class="cs-t">' + esc(it.title)
              + (it.flag ? '<span class="cs-flag">' + esc(it.flag) + '</span>' : '') + '</span>'
              + (it.sub ? '<span class="cs-s">' + esc(it.sub) + '</span>' : '')
              + '</a>';
      });
    });
    drop.innerHTML = html;
    drop.classList.add('is-open');
    inp.setAttribute('aria-expanded', 'true');
  }

  function mark() {
    drop.querySelectorAll('.cs-item').forEach(function (el, i) {
      el.classList.toggle('is-on', i === active);
      if (i === active) el.scrollIntoView({block: 'nearest'});
    });
  }

  inp.addEventListener('input', function () {
    var q = this.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { close(); return; }
    timer = setTimeout(function () {
      fetch('<?= APP_URL ?>/crm/api/search.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (d) { active = -1; paint((d && d.groups) || [], q); })
        .catch(close);
    }, 220);
  });

  inp.addEventListener('keydown', function (e) {
    if (!drop.classList.contains('is-open')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, flat.length - 1); mark(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); mark(); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); window.location.href = flat[active].url; }
    else if (e.key === 'Escape') { close(); }
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#crmSearchDrop') && e.target !== inp) close();
  });
})();
</script>

<!-- ══ WYMAGA REAKCJI ════════════════════════════════════════════════════════ -->
<h2 class="crm-sect-hd"><i class="bi bi-hand-index-thumb" aria-hidden="true"></i>Wymaga reakcji</h2>
<div class="row g-3 mb-4">
  <?php
  crm_dash_tile(APP_URL . '/crm/activities.php?view=zalegle', 'bi-exclamation-triangle-fill', '#B42318', '#FEF2F2',
      $acts_late, 'Zaległe działania', $acts_late ? 'moje, po terminie' : 'nic nie zalega',
      $acts_late ? '#B42318' : '');

  crm_dash_tile(APP_URL . '/crm/activities.php?view=dzis', 'bi-calendar-check-fill', '#2563EB', '#EEF4FF',
      $acts_today, 'Działania na dziś');

  crm_dash_tile(APP_URL . '/crm/inbox.php', 'bi-inbox-fill', '#0176D3', '#E8F4FD',
      $inbox_unread, 'Nieprzeczytane w skrzynce',
      $inbox_mine ? $inbox_mine . ' przypisane mnie' : '');

  crm_dash_tile(APP_URL . '/crm/cases/index.php?status=open', 'bi-briefcase-fill', '#2563EB', '#EEF4FF',
      $cases_open, 'Otwarte sprawy',
      $cases_high ? $cases_high . ' wysoki priorytet' : '', $cases_high ? '#DC2626' : '');

  crm_dash_tile(APP_URL . '/crm/cases/stale.php', 'bi-hourglass-split', '#92400E', '#FEF3C7',
      $cases_stale, 'Sprawy bez ruchu', 'ponad ' . CRM_CASE_STALE_DAYS . ' dni');

  crm_dash_tile(APP_URL . '/crm/offers/index.php', 'bi-file-earmark-ruled-fill', '#0F766E', '#E6F6F2',
      number_format((float)$offer_stats['pipeline'], 0, ',', ' ') . ' zł', 'Oferty w toku',
      $offer_noconf ? $offer_noconf . ' bez potwierdzenia' : '', $offer_noconf ? '#B45309' : '');
  ?>
</div>

<div class="row g-3">

  <!-- ── Lewa kolumna ──────────────────────────────────────────────────── -->
  <div class="col-lg-8">

    <!-- Moje działania -->
    <div class="crm-panel mb-3">
      <div class="crm-panel-header">
        <div class="crm-panel-title">
          <i class="bi bi-list-check me-1" style="color:#2563EB"></i>Moje działania — zaległe i na dziś
          <span class="crm-count-chip"><?= count($my_acts) ?></span>
        </div>
        <a href="<?= APP_URL ?>/crm/activities.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wszystkie <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
      <?php if ($my_acts): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($my_acts as $a):
          $late = strtotime($a['scheduled_at']) < strtotime('today');
        ?>
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$a['contact_id'] ?>" class="crm-case-row text-decoration-none">
          <span class="crm-case-dot" style="background:<?= $late ? '#DC2626' : '#2563EB' ?>" aria-hidden="true"></span>
          <div class="flex-grow-1 min-width-0">
            <div class="crm-case-title"><?= h($a['title']) ?></div>
            <div class="crm-case-meta"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($a['imie_nazwisko']) ?></div>
          </div>
          <span class="text-nowrap" style="font-size:.76rem;<?= $late ? 'color:#B42318;font-weight:600' : 'color:#6B7280' ?>">
            <?= date('d.m H:i', strtotime($a['scheduled_at'])) ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-panel-body text-muted small">
        Nic na dziś i nic zaległego. Działania planuje się w kartotece kontaktu.
      </div>
      <?php endif; ?>
    </div>

    <!-- Otwarte sprawy -->
    <div class="crm-panel">
      <div class="crm-panel-header">
        <div class="crm-panel-title">
          <i class="bi bi-briefcase-fill me-1" style="color:#2563EB"></i>Otwarte sprawy
          <span class="crm-count-chip"><?= $cases_open ?></span>
        </div>
        <a href="<?= APP_URL ?>/crm/cases/index.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wszystkie <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
      <?php if ($open_cases): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($open_cases as $c):
          $sc = $case_status_cfg[$c['status']] ?? $case_status_cfg['open'];
          $pc = $case_priority_cfg[$c['priority']] ?? $case_priority_cfg['medium'];
        ?>
        <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$c['id'] ?>" class="crm-case-row text-decoration-none"
           aria-label="Sprawa: <?= h($c['title']) ?> — <?= h($sc['label']) ?>, priorytet <?= h($pc['label']) ?>">
          <span class="crm-case-dot" style="background:<?= $pc['color'] ?>" aria-hidden="true"></span>
          <div class="flex-grow-1 min-width-0">
            <?php if (!empty($c['case_number'])): ?>
            <div class="crm-case-num" aria-hidden="true"><?= h($c['case_number']) ?></div>
            <?php endif; ?>
            <div class="crm-case-title"><?= h($c['title']) ?></div>
            <div class="crm-case-meta">
              <i class="bi bi-person me-1" aria-hidden="true"></i><?= h($c['contact_name'] ?? '—') ?>
              <?php if ($c['notes_count']): ?><span class="ms-2"><i class="bi bi-chat me-1" aria-hidden="true"></i><?= (int)$c['notes_count'] ?></span><?php endif; ?>
              <?php if ($c['files_count']): ?><span class="ms-2"><i class="bi bi-paperclip me-1" aria-hidden="true"></i><?= (int)$c['files_count'] ?></span><?php endif; ?>
            </div>
          </div>
          <span class="crm-case-pill flex-shrink-0" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
            <i class="bi <?= $sc['icon'] ?>" aria-hidden="true"></i><?= $sc['label'] ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-empty">
        <i class="crm-empty-icon bi bi-briefcase"></i>
        <h5>Brak otwartych spraw</h5>
        <p>Wszystkie sprawy są zamknięte albo nie utworzono jeszcze żadnej.</p>
        <?php if ($can_write): ?>
        <a href="<?= APP_URL ?>/crm/cases/add.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowa sprawa</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Prawa kolumna ─────────────────────────────────────────────────── -->
  <div class="col-lg-4">

    <?php if ($can_write || $can_mailing): ?>
    <div class="crm-panel mb-3">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-lightning-fill text-warning me-1"></i>Szybkie akcje</div>
      </div>
      <div class="crm-panel-body">
        <div class="d-grid gap-1">
          <?php if ($can_write): ?>
          <a href="<?= APP_URL ?>/crm/cases/add.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-briefcase-fill"></i></div>
            <div><div class="fw-semibold" style="font-size:.87rem">Nowa sprawa</div>
                 <div class="text-muted" style="font-size:.75rem">Powiązana z kontaktem</div></div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <a href="<?= APP_URL ?>/crm/offers/form.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#E6F6F2;color:#0F766E"><i class="bi bi-file-earmark-ruled-fill"></i></div>
            <div><div class="fw-semibold" style="font-size:.87rem">Nowa oferta</div>
                 <div class="text-muted" style="font-size:.75rem">Działalność odpłatna, warianty, PDF</div></div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <?php endif; ?>
          <?php if ($can_mailing): ?>
          <a href="<?= APP_URL ?>/crm/communicate.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#FEF3E2;color:#D97706"><i class="bi bi-send-fill"></i></div>
            <div><div class="fw-semibold" style="font-size:.87rem">Wyślij wiadomość</div>
                 <div class="text-muted" style="font-size:.75rem">E-mail, SMS, szablony</div></div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/crm/calendar.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#EEF4FF;color:#0176D3"><i class="bi bi-calendar3-fill"></i></div>
            <div><div class="fw-semibold" style="font-size:.87rem">Kalendarz</div>
                 <div class="text-muted" style="font-size:.75rem">Zdarzenia, zadania, terminy</div></div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Porządek w bazie -->
    <?php if ($can_write): ?>
    <div class="crm-panel mb-3">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-tools text-muted me-1"></i>Porządek w bazie</div>
      </div>
      <div class="crm-panel-body p-0">
        <?php
        $hyg = [
            ['/crm/contact/merge.php',    'bi-intersect',   $dup_groups,  'Podejrzenia duplikatów',
             'Ten sam NIP, e-mail, telefon albo nazwa'],
            ['/crm/contact/domains.php',  'bi-diagram-3',   $dom_suggest, 'Domeny do podpięcia',
             'Osoby z domeny podmiotu, który jest już w bazie'],
            ['/crm/index.php?owner=none', 'bi-person-dash', $no_owner,    'Kontakty bez opiekuna',
             'Nikt nie prowadzi tej relacji'],
            ['/crm/contact/analyze.php',  'bi-funnel',      null,         'Analiza kartotek z poczty',
             'Kandydaci firmowi i śmieci z auto-kartoteki'],
        ];
        foreach ($hyg as [$href, $icon, $n, $label, $desc]): ?>
        <a href="<?= APP_URL . $href ?>" class="crm-hyg-row text-decoration-none">
          <i class="bi <?= h($icon) ?> crm-hyg-ico" aria-hidden="true"></i>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-semibold" style="font-size:.83rem"><?= h($label) ?></div>
            <div class="text-muted" style="font-size:.73rem"><?= h($desc) ?></div>
          </div>
          <?php if ($n !== null): ?>
          <span class="crm-hyg-n<?= $n ? '' : ' crm-hyg-n--zero' ?>"><?= (int)$n ?></span>
          <?php else: ?>
          <i class="bi bi-chevron-right text-muted opacity-50" aria-hidden="true"></i>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Statusy kontaktów -->
    <?php if ($status_counts): ?>
    <div class="crm-panel">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-pie-chart text-muted me-1"></i>Statusy kontaktów</div>
      </div>
      <div class="crm-panel-body">
        <?php
        $total_sc = max(1, array_sum($status_counts));
        foreach (crm_statuses() as $sk => $sv):
          $cnt = $status_counts[$sk] ?? 0;
          if (!$cnt) continue;
          $pct = round($cnt / $total_sc * 100);
          $col = $sv['color'] ?? '#6B7280';
        ?>
        <a href="<?= APP_URL ?>/crm/index.php?status=<?= h($sk) ?>" class="d-block mb-2 text-decoration-none">
          <div class="d-flex justify-content-between" style="font-size:.8rem;margin-bottom:.2rem">
            <span style="color:<?= h($col) ?>;font-weight:600"><?= h($sv['label']) ?></span>
            <span class="text-muted"><?= $cnt ?> (<?= $pct ?>%)</span>
          </div>
          <div style="height:6px;background:#F3F4F6;border-radius:3px">
            <div style="height:6px;border-radius:3px;background:<?= h($col) ?>;width:<?= $pct ?>%"></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ══ OSTATNIA AKTYWNOŚĆ ════════════════════════════════════════════════════ -->
<div class="row g-3 mt-0">
  <div class="col-lg-6">
    <div class="crm-panel h-100">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-clock-history text-muted me-1"></i>Ostatnio zmienione kontakty</div>
        <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wszystkie <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
      <?php if ($recent): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($recent as $r):
          $ini    = $r['avatar_initials'] ?: CrmManager::makeInitials($r['imie_nazwisko']);
          $sc     = crm_statuses()[$r['status']] ?? ['label' => $r['status']];
          $is_org = !empty(CRM_CONTACT_TYPES[$r['type']]['org_like']);
        ?>
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['id'] ?>" class="crm-contact-row text-decoration-none">
          <div class="crm-avatar <?= $is_org ? 'org' : '' ?>"
               style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>;flex-shrink:0" aria-hidden="true">
            <?= h($ini) ?>
          </div>
          <div class="crm-contact-row-info">
            <div class="crm-contact-row-name"><?= h($r['imie_nazwisko']) ?></div>
            <?php if ($r['organizacja']): ?>
            <div class="crm-contact-row-sub"><?= h($r['organizacja']) ?></div>
            <?php endif; ?>
          </div>
          <div class="ms-auto d-flex align-items-center gap-2">
            <span class="crm-badge crm-badge-<?= h($r['status']) ?>" style="font-size:.7rem"><?= h($sc['label']) ?></span>
            <span class="text-muted" style="font-size:.75rem;white-space:nowrap"><?= date_pl($r['updated_at']) ?></span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-empty">
        <i class="crm-empty-icon bi bi-people"></i>
        <h5>Brak kontaktów</h5>
        <p>Dodaj pierwsze kontakty CRM.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="crm-panel h-100">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-chat-dots text-muted me-1"></i>Ostatnia komunikacja</div>
        <?php if ($can_mailing): ?>
        <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wyślij <i class="bi bi-arrow-right ms-1"></i>
        </a>
        <?php endif; ?>
      </div>
      <?php if ($recent_comms): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($recent_comms as $c):
          $ch_icons = ['email'=>'bi-envelope-fill','sms'=>'bi-phone-fill','telefon'=>'bi-telephone-fill','osobisty'=>'bi-person-fill'];
          $ch_colors= ['email'=>'#0176D3','sms'=>'#D97706','telefon'=>'#2E844A','osobisty'=>'#7C3AED'];
          $ic = $ch_icons[$c['channel']] ?? 'bi-chat-fill';
          $cc = $ch_colors[$c['channel']] ?? '#6B7280';
        ?>
        <div class="crm-comm-row">
          <div class="crm-comm-ch-icon" style="color:<?= $cc ?>;background:<?= $cc ?>18"><i class="bi <?= $ic ?>"></i></div>
          <div class="crm-comm-row-info">
            <div class="fw-semibold" style="font-size:.82rem;color:#111827"><?= h($c['imie_nazwisko'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.75rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px">
              <?= $c['subject'] ? h($c['subject']) : h(mb_substr(strip_tags((string)$c['body']), 0, 55)) ?>
            </div>
          </div>
          <div class="text-muted ms-auto" style="font-size:.72rem;white-space:nowrap"><?= date_pl($c['sent_at']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-empty">
        <i class="crm-empty-icon bi bi-chat-dots"></i>
        <h5>Brak komunikacji</h5>
        <p>Wysłane wiadomości pojawią się tutaj.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══ STAN BAZY ═════════════════════════════════════════════════════════════
     Liczby inwentarzowe. Nie mówią, co robić, więc nie zaczynają ekranu —
     ale raz na jakiś czas ktoś chce je zobaczyć. Jeden pasek, na dole. -->
<div class="crm-stats-bar mt-3">
  <a href="<?= APP_URL ?>/crm/index.php"><strong><?= number_format((int)$stats['total'], 0, ',', ' ') ?></strong> kontaktów</a>
  <span aria-hidden="true">·</span>
  <a href="<?= APP_URL ?>/crm/index.php?type=osoba"><strong><?= $persons ?></strong> osób</a>
  <span aria-hidden="true">·</span>
  <a href="<?= APP_URL ?>/crm/index.php?type=organizacja"><strong><?= $orgs ?></strong> podmiotów</a>
  <span aria-hidden="true">·</span>
  <span><strong><?= $comms_week ?></strong> wiadomości przez 7 dni<?= $comms_today ? ', w tym ' . $comms_today . ' dziś' : '' ?></span>
  <?php if (!empty($stats['new_this_month'])): ?>
  <span aria-hidden="true">·</span>
  <span class="crm-stats-up">+<?= (int)$stats['new_this_month'] ?> w tym miesiącu</span>
  <?php endif; ?>
</div>

<style>
/* ── Nagłówek sekcji ──────────────────────────────── */
.crm-sect-hd {
  font-size: .78rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: #6B7280; margin: 0 0 .6rem; display: flex; align-items: center; gap: .4rem;
}

/* ── Kafelki „wymaga reakcji" ─────────────────────── */
.crm-tile {
  display: block; background: #fff; border: 1px solid #E5E7EB; border-radius: 10px;
  padding: .9rem 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.04);
  text-decoration: none; color: inherit; transition: box-shadow .15s, border-color .15s;
  height: 100%;
}
.crm-tile:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); border-color: #D1D5DB; color: inherit; }
.crm-tile:focus-visible { outline: 2px solid var(--crm-primary); outline-offset: 2px; }
/* Zero nie znika — znika tylko jego natarczywość. Ukrywanie zera kazałoby
   zgadywać, czy licznik jest pusty, czy kafelka w ogóle nie ma. */
.crm-tile--zero { opacity: .62; }
.crm-tile-ico {
  width: 34px; height: 34px; border-radius: 9px; display: flex;
  align-items: center; justify-content: center; font-size: 1rem; margin-bottom: .45rem;
}
.crm-tile-val { font-size: 1.6rem; font-weight: 800; color: #111827; line-height: 1.05; }
.crm-tile-lbl { font-size: .75rem; color: #6B7280; margin-top: .15rem; }
.crm-tile-note{ font-size: .72rem; font-weight: 600; margin-top: .3rem; }

/* ── Panel ────────────────────────────────────────── */
.crm-panel {
  background: #fff; border: 1px solid #E5E7EB; border-radius: 10px;
  overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.crm-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1rem .6rem; border-bottom: 1px solid #F3F4F6;
}
.crm-panel-title { font-size: .82rem; font-weight: 600; color: #374151; }
.crm-panel-body  { padding: .75rem 1rem; }
.crm-count-chip {
  display:inline-block; min-width:1.4rem; text-align:center;
  margin-left:.4rem; padding:0 .45rem;
  font-size:.72rem; font-weight:700; line-height:1.4rem;
  color:#1D4ED8; background:#EEF4FF; border-radius:1rem;
}

/* ── Wiersze kontaktów ────────────────────────────── */
.crm-contact-row {
  display: flex; align-items: center; gap: .75rem; padding: .6rem 1rem;
  border-bottom: 1px solid #F9FAFB; transition: background .1s; color: #111827;
}
.crm-contact-row:last-child { border-bottom: none; }
.crm-contact-row:hover { background: #F9FAFB; }
.crm-contact-row-name { font-size: .85rem; font-weight: 600; color: #111827; }
.crm-contact-row-sub  { font-size: .74rem; color: #9CA3AF; margin-top: 1px; }

/* ── Szybkie akcje ────────────────────────────────── */
.crm-quick-action {
  display: flex; align-items: center; gap: .75rem; padding: .5rem .6rem;
  border-radius: 8px; text-decoration: none; color: #111827;
  transition: background .1s; border: 1px solid transparent;
}
.crm-quick-action:hover { background: #F9FAFB; border-color: #E5E7EB; color: #111827; }
.crm-quick-icon {
  width: 34px; height: 34px; border-radius: 8px; display: flex;
  align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0;
}

/* ── Porządek w bazie ─────────────────────────────── */
.crm-hyg-row {
  display: flex; align-items: center; gap: .6rem; padding: .55rem 1rem;
  border-bottom: 1px solid #F9FAFB; color: #111827; transition: background .1s;
}
.crm-hyg-row:last-child { border-bottom: none; }
.crm-hyg-row:hover { background: #F9FAFB; color: #111827; }
.crm-hyg-ico { color: #6B7280; font-size: .95rem; width: 1.2rem; text-align: center; }
.crm-hyg-n {
  min-width: 1.6rem; text-align: center; padding: 0 .4rem; border-radius: 1rem;
  font-size: .74rem; font-weight: 700; line-height: 1.5rem;
  background: #FEF3C7; color: #92400E;
}
.crm-hyg-n--zero { background: #F3F4F6; color: #9CA3AF; }

/* ── Wiersze komunikacji ──────────────────────────── */
.crm-comm-row {
  display: flex; align-items: center; gap: .65rem; padding: .55rem 1rem;
  border-bottom: 1px solid #F9FAFB;
}
.crm-comm-row:last-child { border-bottom: none; }
.crm-comm-ch-icon {
  width: 28px; height: 28px; border-radius: 50%; display: flex;
  align-items: center; justify-content: center; font-size: .75rem; flex-shrink: 0;
}
.crm-comm-row-info { overflow: hidden; }

/* ── Wiersze spraw i działań ──────────────────────── */
.crm-case-row {
  display: flex; align-items: center; gap: .75rem; padding: .65rem 1rem;
  border-bottom: 1px solid #F9FAFB; transition: background .1s; color: #111827;
}
.crm-case-row:last-child { border-bottom: none; }
.crm-case-row:hover { background: #F9FAFB; }
.crm-case-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.crm-case-num { font-family: monospace; font-size: .68rem; font-weight: 700; color: #1D4ED8; letter-spacing: .04em; }
.crm-case-title { font-size: .87rem; font-weight: 600; color: #111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.crm-case-meta { font-size: .75rem; color: #9CA3AF; }
.crm-case-pill {
  display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .6rem;
  border-radius: 2rem; font-size: .72rem; font-weight: 600; white-space: nowrap;
}

/* ── Pasek stanu bazy ─────────────────────────────── */
.crm-stats-bar {
  display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
  padding: .55rem .9rem; border: 1px solid #E5E7EB; border-radius: 10px;
  background: #FBFCFD; font-size: .8rem; color: #6B7280;
}
.crm-stats-bar a { color: #6B7280; text-decoration: none; }
.crm-stats-bar a:hover { color: #111827; text-decoration: underline; }
.crm-stats-bar strong { color: #111827; font-weight: 700; }
.crm-stats-up { color: #2E844A; font-weight: 600; }
</style>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
