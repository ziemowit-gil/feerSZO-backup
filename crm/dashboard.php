<?php
/**
 * crm/dashboard.php — Dashboard CRM z podsumowaniem i ostatnią aktywnością.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE = 'Dashboard CRM';
$user       = current_user();
$can_write   = can_write('crm') || is_admin();
$can_mailing = can_write('crm_mailing') || is_admin();
$stats      = CrmManager::getStats();

$u_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (!$u_name) $u_name = explode(' ', $user['name'] ?? '')[0] ?? '';
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Witaj' : 'Dobry wieczór');

// Statystyki
$persons = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND type='osoba'")['c'] ?? 0);
$orgs    = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND type='organizacja'")['c'] ?? 0);
$comms_today = (int)(db_one("SELECT COUNT(*) AS c FROM crm_communications WHERE DATE(sent_at)=DATE('now')")['c'] ?? 0);
$comms_week  = (int)(db_one("SELECT COUNT(*) AS c FROM crm_communications WHERE sent_at >= DATE('now','-7 days')")['c'] ?? 0);

// Statystyki po statusie
$status_counts = [];
try {
    $rows = db_all("SELECT status, COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 GROUP BY status");
    foreach ($rows as $r) $status_counts[$r['status']] = (int)$r['c'];
} catch (\Throwable $e) {}

// Ostatnie kontakty (8)
$recent = db_all(
    "SELECT c.*, (SELECT GROUP_CONCAT(t.tag,',') FROM crm_tags t WHERE t.contact_id=c.id) AS tags_csv
     FROM crm_contacts c WHERE c.crm_active=1 ORDER BY c.updated_at DESC LIMIT 8"
);

// Ostatnia komunikacja (6)
$recent_comms = db_all(
    "SELECT cc.*, ct.imie_nazwisko, ct.type AS contact_type
     FROM crm_communications cc
     LEFT JOIN crm_contacts ct ON ct.id=cc.contact_id
     ORDER BY cc.sent_at DESC LIMIT 6"
);

// ── Sprawy CRM (otwarte) ───────────────────────────────────────────────────
$case_status_cfg = [
    'open'        => ['label'=>'Otwarta', 'color'=>'#2563EB','bg'=>'#EEF4FF','icon'=>'bi-circle'],
    'in_progress' => ['label'=>'W toku',  'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-arrow-clockwise'],
];
$case_priority_cfg = [
    'low'    => ['label'=>'Niski',  'color'=>'#9CA3AF'],
    'medium' => ['label'=>'Średni', 'color'=>'#D97706'],
    'high'   => ['label'=>'Wysoki', 'color'=>'#DC2626'],
];
$cases_open_count = (int)(db_one("SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress')")['c'] ?? 0);
$cases_high_count = (int)(db_one("SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress') AND priority='high'")['c'] ?? 0);
$open_cases = db_all(
    "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type,
            (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=c.id) AS notes_count,
            (SELECT COUNT(*) FROM crm_case_files f WHERE f.case_id=c.id) AS files_count
     FROM crm_cases c
     LEFT JOIN crm_contacts ct ON ct.id=c.contact_id
     WHERE c.status IN ('open','in_progress')
     ORDER BY CASE c.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
              c.updated_at DESC
     LIMIT 8"
);

include __DIR__ . '/includes/header_crm.php';
?>

<!-- ══ PAGE HEADER ═══════════════════════════════════════════════════════════ -->
<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title">
      <span><?= h($greeting) ?>, <?= h($u_name ?: 'Użytkowniku') ?></span>
    </div>
    <div class="crm-page-subtitle">
      CRM · <?= h(org_setting('org_name') ?: ORG_NAME) ?> · <?= date('l, d F Y') ?>
    </div>
  </div>
  <?php if ($can_write || $can_mailing): ?>
  <div class="crm-page-actions">
    <?php if ($can_write): ?>
    <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-person-plus me-1"></i>Nowy kontakt
    </a>
    <?php endif; ?>
    <?php if ($can_mailing): ?>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1"></i>Wyślij wiadomość
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ KARTY STATYSTYK ═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

  <!-- Otwarte sprawy — wyróżniony KPI (klik → lista spraw) -->
  <div class="col-6 col-lg">
    <a href="<?= APP_URL ?>/crm/cases/index.php?status=open" class="crm-kpi-card crm-kpi-accent text-decoration-none d-block">
      <div class="crm-kpi-icon" style="background:#EEF4FF;color:#2563EB">
        <i class="bi bi-briefcase-fill"></i>
      </div>
      <div class="crm-kpi-value"><?= number_format($cases_open_count) ?></div>
      <div class="crm-kpi-label">Otwarte sprawy</div>
      <?php if ($cases_high_count): ?>
      <div class="crm-kpi-delta" style="color:#DC2626">
        <i class="bi bi-exclamation-triangle-fill"></i><?= $cases_high_count ?> wysoki priorytet
      </div>
      <?php endif; ?>
    </a>
  </div>

  <div class="col-6 col-lg">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#EFF7ED;color:#2E844A">
        <i class="bi bi-people-fill"></i>
      </div>
      <div class="crm-kpi-value"><?= number_format($stats['total']) ?></div>
      <div class="crm-kpi-label">Wszystkich kontaktów</div>
      <?php if ($stats['new_this_month']): ?>
      <div class="crm-kpi-delta">
        <i class="bi bi-arrow-up-short"></i>+<?= $stats['new_this_month'] ?> w tym miesiącu
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-6 col-lg">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#EEF4FF;color:#2563EB">
        <i class="bi bi-person"></i>
      </div>
      <div class="crm-kpi-value"><?= $persons ?></div>
      <div class="crm-kpi-label">Osób fizycznych</div>
    </div>
  </div>

  <div class="col-6 col-lg">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#F3E8F9;color:#7C3AED">
        <i class="bi bi-building"></i>
      </div>
      <div class="crm-kpi-value"><?= $orgs ?></div>
      <div class="crm-kpi-label">Firm i organizacji</div>
    </div>
  </div>

  <div class="col-6 col-lg">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#FEF3E2;color:#D97706">
        <i class="bi bi-chat-dots-fill"></i>
      </div>
      <div class="crm-kpi-value"><?= $comms_week ?></div>
      <div class="crm-kpi-label">Wiadomości (7 dni)</div>
      <?php if ($comms_today): ?>
      <div class="crm-kpi-delta" style="color:#D97706">
        <i class="bi bi-circle-fill" style="font-size:.45rem;vertical-align:middle"></i>
        <?= $comms_today ?> dziś
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /kpi row -->

<!-- ══ GŁÓWNA SEKCJA — układ „sprawy najpierw" ═══════════════════════════════ -->
<div class="row g-3">

  <!-- Lewa: OTWARTE SPRAWY (główny widok) -->
  <div class="col-lg-8">
    <div class="crm-panel">
      <div class="crm-panel-header">
        <div class="crm-panel-title">
          <i class="bi bi-briefcase-fill me-1" style="color:#2563EB"></i>Otwarte sprawy
          <span class="crm-count-chip"><?= $cases_open_count ?></span>
        </div>
        <a href="<?= APP_URL ?>/crm/cases/index.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wszystkie sprawy <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <?php if ($open_cases): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($open_cases as $c):
          $sc = $case_status_cfg[$c['status']] ?? $case_status_cfg['open'];
          $pc = $case_priority_cfg[$c['priority']] ?? $case_priority_cfg['medium'];
        ?>
        <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$c['id'] ?>"
           class="crm-case-row text-decoration-none"
           aria-label="Sprawa: <?= h($c['title']) ?><?= $c['contact_name'] ? ', '.h($c['contact_name']) : '' ?> — <?= h($sc['label']) ?>, priorytet <?= h($pc['label']) ?>">
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
          <span class="text-muted d-none d-sm-inline flex-shrink-0" style="font-size:.74rem;white-space:nowrap">
            <?= date_pl($c['updated_at']) ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-empty">
        <i class="crm-empty-icon bi bi-briefcase"></i>
        <h5>Brak otwartych spraw</h5>
        <p>Wszystkie sprawy są zamknięte lub nie utworzono jeszcze żadnej.</p>
        <?php if ($can_write): ?>
        <a href="<?= APP_URL ?>/crm/cases/add.php" class="btn btn-crm-primary btn-sm">
          <i class="bi bi-plus-lg me-1"></i>Nowa sprawa
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Prawa: szybkie akcje + statusy -->
  <div class="col-lg-4">

    <?php if ($can_write || $can_mailing): ?>
    <div class="crm-panel mb-3">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-lightning-fill text-warning me-1"></i>Szybkie akcje</div>
      </div>
      <div class="crm-panel-body">
        <div class="d-grid gap-2">
          <?php if ($can_write): ?>
          <a href="<?= APP_URL ?>/crm/cases/add.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-briefcase-fill"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Nowa sprawa</div>
              <div class="text-muted" style="font-size:.75rem">Powiązana z kontaktem</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#EFF7ED;color:#2E844A"><i class="bi bi-person-plus-fill"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Nowa osoba fizyczna</div>
              <div class="text-muted" style="font-size:.75rem">Wolontariusz, pracownik, kontakt</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <a href="<?= APP_URL ?>/crm/contact/add_org.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#F3E8F9;color:#7C3AED"><i class="bi bi-building-add"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Nowa firma / organizacja</div>
              <div class="text-muted" style="font-size:.75rem">Partner, darczyńca, instytucja</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <?php endif; ?>
          <?php if ($can_mailing): ?>
          <a href="<?= APP_URL ?>/crm/communicate.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#FEF3E2;color:#D97706"><i class="bi bi-send-fill"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Wyślij wiadomość</div>
              <div class="text-muted" style="font-size:.75rem">E-mail, SMS, szablony</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/crm/calendar.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#EEF4FF;color:#0176D3"><i class="bi bi-calendar3-fill"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Kalendarz</div>
              <div class="text-muted" style="font-size:.75rem">Zdarzenia, zadania, terminy</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
        </div>
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
        $status_cfg = [
          'aktywny'    => ['label'=>'Aktywny',    'color'=>'#2E844A'],
          'prospect'   => ['label'=>'Prospect',   'color'=>'#0176D3'],
          'partner'    => ['label'=>'Partner',    'color'=>'#7C3AED'],
          'darczyńca'  => ['label'=>'Darczyńca',  'color'=>'#D97706'],
          'klient'     => ['label'=>'Klient',     'color'=>'#032D60'],
          'nieaktywny' => ['label'=>'Nieaktywny', 'color'=>'#9CA3AF'],
        ];
        $total_sc = max(1, array_sum($status_counts));
        foreach ($status_cfg as $sk => $sv):
          $cnt = $status_counts[$sk] ?? 0;
          if (!$cnt) continue;
          $pct = round($cnt / $total_sc * 100);
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between" style="font-size:.8rem;margin-bottom:.2rem">
            <span style="color:<?= $sv['color'] ?>;font-weight:600"><?= $sv['label'] ?></span>
            <span class="text-muted"><?= $cnt ?> (<?= $pct ?>%)</span>
          </div>
          <div style="height:6px;background:#F3F4F6;border-radius:3px">
            <div style="height:6px;border-radius:3px;background:<?= $sv['color'] ?>;width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div><!-- /row -->

<!-- ══ DRUGI RZĄD — kontakty + komunikacja ═══════════════════════════════════ -->
<div class="row g-3 mt-0">

  <!-- Ostatnio zmienione kontakty -->
  <div class="col-lg-6">
    <div class="crm-panel">
      <div class="crm-panel-header">
        <div class="crm-panel-title">
          <i class="bi bi-clock-history text-muted me-1"></i>Ostatnio zmienione kontakty
        </div>
        <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-crm-outline btn-sm py-0" style="font-size:.75rem">
          Wszystkie <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
      <?php if ($recent): ?>
      <div class="crm-panel-body p-0">
        <?php foreach ($recent as $r):
          $ini    = $r['avatar_initials'] ?: CrmManager::makeInitials($r['imie_nazwisko']);
          $sc     = crm_statuses()[$r['status']] ?? ['label' => $r['status']];
          $is_org = $r['type'] === 'organizacja';
        ?>
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['id'] ?>"
           class="crm-contact-row text-decoration-none"
           aria-label="Otwórz <?= h($r['imie_nazwisko']) ?>">
          <div class="crm-avatar <?= $is_org ? 'org' : '' ?>"
               style="background:<?= $is_org ? 'var(--crm-navy)' : 'var(--crm-primary)' ?>;flex-shrink:0"
               aria-hidden="true">
            <?= h($ini) ?>
          </div>
          <div class="crm-contact-row-info">
            <div class="crm-contact-row-name"><?= h($r['imie_nazwisko']) ?></div>
            <?php if ($r['organizacja']): ?>
            <div class="crm-contact-row-sub"><?= h($r['organizacja']) ?></div>
            <?php endif; ?>
          </div>
          <div class="ms-auto d-flex align-items-center gap-2">
            <span class="crm-badge crm-badge-<?= h($r['status']) ?>" style="font-size:.7rem">
              <?= h($sc['label']) ?>
            </span>
            <span class="text-muted" style="font-size:.75rem;white-space:nowrap">
              <?= date_pl($r['updated_at']) ?>
            </span>
          </div>
          <i class="bi bi-chevron-right text-muted" style="font-size:.7rem;opacity:.4"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="crm-empty">
        <i class="crm-empty-icon bi bi-people"></i>
        <h5>Brak kontaktów</h5>
        <p>Dodaj pierwszych kontaktów CRM</p>
        <?php if ($can_write): ?>
        <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-crm-primary btn-sm">
          <i class="bi bi-person-plus me-1"></i>Dodaj osobę
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ostatnia komunikacja -->
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
          <div class="crm-comm-ch-icon" style="color:<?= $cc ?>;background:<?= $cc ?>18">
            <i class="bi <?= $ic ?>"></i>
          </div>
          <div class="crm-comm-row-info">
            <div class="fw-semibold" style="font-size:.82rem;color:#111827">
              <?= h($c['imie_nazwisko'] ?? '—') ?>
            </div>
            <div class="text-muted" style="font-size:.75rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px">
              <?= $c['subject'] ? h($c['subject']) : mb_substr(strip_tags($c['body']), 0, 55) ?>
            </div>
          </div>
          <div class="text-muted ms-auto" style="font-size:.72rem;white-space:nowrap">
            <?= date_pl($c['sent_at']) ?>
          </div>
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

</div><!-- /row 2 -->

<style>
/* ── KPI cards ─────────────────────────────────── */
.crm-kpi-card {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: 1rem 1.1rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  transition: box-shadow .15s;
}
.crm-kpi-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.crm-kpi-icon {
  width: 38px; height: 38px;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; margin-bottom: .5rem;
}
.crm-kpi-value { font-size: 1.75rem; font-weight: 800; color: #111827; line-height: 1; margin-bottom: .2rem; }
.crm-kpi-label { font-size: .75rem; color: #6B7280; }
.crm-kpi-delta { font-size: .73rem; font-weight: 600; color: #2E844A; margin-top: .35rem; }

/* ── Panel (white card) ────────────────────────── */
.crm-panel {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.crm-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1rem .6rem;
  border-bottom: 1px solid #F3F4F6;
}
.crm-panel-title { font-size: .82rem; font-weight: 600; color: #374151; }
.crm-panel-body  { padding: .75rem 1rem; }

/* ── Contact rows ──────────────────────────────── */
.crm-contact-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .65rem 1rem;
  border-bottom: 1px solid #F9FAFB;
  transition: background .1s;
  color: #111827;
}
.crm-contact-row:last-child { border-bottom: none; }
.crm-contact-row:hover { background: #F9FAFB; }
.crm-contact-row-name { font-size: .85rem; font-weight: 600; color: #111827; }
.crm-contact-row-sub  { font-size: .74rem; color: #9CA3AF; margin-top: 1px; }

/* ── Quick actions ─────────────────────────────── */
.crm-quick-action {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem .65rem;
  border-radius: 8px;
  text-decoration: none;
  color: #111827;
  transition: background .1s;
  border: 1px solid transparent;
}
.crm-quick-action:hover { background: #F9FAFB; border-color: #E5E7EB; color: #111827; }
.crm-quick-icon {
  width: 36px; height: 36px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}

/* ── Comm rows ─────────────────────────────────── */
.crm-comm-row {
  display: flex; align-items: center; gap: .65rem;
  padding: .55rem 1rem;
  border-bottom: 1px solid #F9FAFB;
}
.crm-comm-row:last-child { border-bottom: none; }
.crm-comm-ch-icon {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; flex-shrink: 0;
}
.crm-comm-row-info { overflow: hidden; }

/* ── KPI: wyróżniona karta „Otwarte sprawy" ───────── */
.crm-kpi-accent { border-color: #BFD3FF; background: linear-gradient(180deg,#F7FAFF,#fff); color: inherit; }
.crm-kpi-accent:hover { box-shadow: 0 4px 14px rgba(37,99,235,.16); }

/* ── Licznik przy tytule panelu ───────────────────── */
.crm-count-chip {
  display:inline-block; min-width:1.4rem; text-align:center;
  margin-left:.4rem; padding:0 .45rem;
  font-size:.72rem; font-weight:700; line-height:1.4rem;
  color:#1D4ED8; background:#EEF4FF; border-radius:1rem;
}

/* ── Wiersze spraw na dashboardzie ─────────────────── */
.crm-case-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .7rem 1rem;
  border-bottom: 1px solid #F9FAFB;
  transition: background .1s;
  color: #111827;
}
.crm-case-row:last-child { border-bottom: none; }
.crm-case-row:hover { background: #F9FAFB; }
.crm-case-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.crm-case-num { font-family: monospace; font-size: .68rem; font-weight: 700; color: #1D4ED8; letter-spacing: .04em; }
.crm-case-title { font-size: .87rem; font-weight: 600; color: #111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.crm-case-meta { font-size: .75rem; color: #9CA3AF; }
.crm-case-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .2rem .6rem; border-radius: 2rem;
  font-size: .72rem; font-weight: 600; white-space: nowrap;
}
</style>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
