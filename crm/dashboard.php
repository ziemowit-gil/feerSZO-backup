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
$can_write  = can_write('crm') || is_admin();
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
  <?php if ($can_write): ?>
  <div class="crm-page-actions">
    <a href="<?= APP_URL ?>/crm/contact/add_person.php" class="btn btn-crm-primary btn-sm">
      <i class="bi bi-person-plus me-1"></i>Nowy kontakt
    </a>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1"></i>Wyślij wiadomość
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- ══ KARTY STATYSTYK ═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-3">
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

  <div class="col-6 col-md-3">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#EEF4FF;color:#2563EB">
        <i class="bi bi-person"></i>
      </div>
      <div class="crm-kpi-value"><?= $persons ?></div>
      <div class="crm-kpi-label">Osób fizycznych</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="crm-kpi-card">
      <div class="crm-kpi-icon" style="background:#F3E8F9;color:#7C3AED">
        <i class="bi bi-building"></i>
      </div>
      <div class="crm-kpi-value"><?= $orgs ?></div>
      <div class="crm-kpi-label">Firm i organizacji</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
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

<!-- ══ GŁÓWNA SEKCJA (kontakty + aktywność) ══════════════════════════════════ -->
<div class="row g-3">

  <!-- Lewa: ostatnie kontakty -->
  <div class="col-lg-7">
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
          $sc     = CRM_STATUSES[$r['status']] ?? ['label' => $r['status']];
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

  <!-- Prawa: aktywność + szybkie akcje -->
  <div class="col-lg-5">

    <!-- Szybkie akcje -->
    <?php if ($can_write): ?>
    <div class="crm-panel mb-3">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-lightning-fill text-warning me-1"></i>Szybkie akcje</div>
      </div>
      <div class="crm-panel-body">
        <div class="d-grid gap-2">
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
          <a href="<?= APP_URL ?>/crm/communicate.php" class="crm-quick-action">
            <div class="crm-quick-icon" style="background:#FEF3E2;color:#D97706"><i class="bi bi-send-fill"></i></div>
            <div>
              <div class="fw-semibold" style="font-size:.87rem">Wyślij wiadomość</div>
              <div class="text-muted" style="font-size:.75rem">E-mail, SMS, szablony</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
          </a>
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

    <!-- Ostatnia komunikacja -->
    <?php if ($recent_comms): ?>
    <div class="crm-panel">
      <div class="crm-panel-header">
        <div class="crm-panel-title"><i class="bi bi-chat-dots text-muted me-1"></i>Ostatnia komunikacja</div>
      </div>
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
            <div class="text-muted" style="font-size:.75rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px">
              <?= $c['subject'] ? h($c['subject']) : mb_substr(strip_tags($c['body']), 0, 55) ?>
            </div>
          </div>
          <div class="text-muted ms-auto" style="font-size:.72rem;white-space:nowrap">
            <?= date_pl($c['sent_at']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Statusy kontaktów -->
    <?php if ($status_counts): ?>
    <div class="crm-panel mt-3">
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
</style>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
