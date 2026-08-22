<?php
/**
 * crm/inbox.php — Skrzynka odbiorcza współdzielona w CRM.
 *
 * Ta sama baza wiadomości co Inbox Ogólny EZD (crm_communications zasilane przez
 * PocztaScanService), ale widok sprzedażowy: przy każdej wiadomości widać kontakt,
 * jego sprawy i oferty, a akcje prowadzą do pracy w CRM. Status „przeczytane"
 * i przypisanie są wspólne z EZD — to te same kolumny, nie kopia.
 *
 * Układ master-detail (lista + treść), bez modali — działa z klawiatury i czytnikiem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE = 'Skrzynka CRM';
$can_write  = can_write('crm') || is_admin();
$uid        = (int)(current_user()['id'] ?? 0);
$ready      = crm_mailbox_ready();

// ── Akcje (POST → redirect, żeby odświeżenie nie powtarzało operacji) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('danger', 'Brak uprawnień.'); }
    else {
        $op  = (string)($_POST['_op'] ?? '');
        $mid = (int)($_POST['msg_id'] ?? 0);
        $back = 'inbox.php?' . http_build_query(array_filter([
            'view'       => $_POST['view'] ?? null,
            'mailbox_id' => $_POST['mailbox_id'] ?? null,
            'q'          => $_POST['q'] ?? null,
            'msg'        => $mid ?: null,
        ]));

        switch ($op) {
            case 'read':      crm_mailbox_mark_read($mid, true);  flash_set('success', 'Oznaczono jako przeczytane.'); break;
            case 'unread':    crm_mailbox_mark_read($mid, false); flash_set('success', 'Oznaczono jako nieprzeczytane.'); break;
            case 'archive':   crm_mailbox_set_status($mid, 'archived'); flash_set('success', 'Wiadomość załatwiona.'); break;
            case 'spam':      crm_mailbox_set_status($mid, 'spam');     flash_set('success', 'Oznaczono jako spam.'); break;
            case 'restore':   crm_mailbox_set_status($mid, 'active');   flash_set('success', 'Przywrócono do skrzynki.'); break;
            case 'assign_me': crm_mailbox_assign($mid, $uid);           flash_set('success', 'Przypisano Tobie.'); break;
            case 'assign':
                crm_mailbox_assign($mid, (int)($_POST['user_id'] ?? 0) ?: null);
                flash_set('success', 'Przypisanie zmienione.');
                break;
            case 'case':
                $r = crm_mailbox_create_case($mid);
                if (!empty($r['ok'])) {
                    flash_set('success', 'Sprawa utworzona z wiadomości.');
                    header('Location: ' . $r['url']); exit;
                }
                flash_set('danger', $r['error'] ?? 'Nie udało się utworzyć sprawy.');
                break;
            case 'scan':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                $r = crm_mailbox_scan((int)($_POST['scan_mailbox'] ?? 0) ?: null);
                if (empty($r['ok'])) flash_set('danger', $r['error'] ?? 'Skanowanie nieudane.');
                else {
                    flash_set($r['errors'] ? 'warning' : 'success', sprintf(
                        'Pobrano %d, dopasowano %d, zapisano %d, pominięto %d.%s',
                        $r['fetched'], $r['matched'], $r['created'], $r['skipped'],
                        $r['errors'] ? ' Uwagi: ' . implode(' | ', array_slice($r['errors'], 0, 2)) : ''
                    ));
                }
                break;
            case 'autocreate':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                crm_setting_save('poczta_autocreate_contacts', !empty($_POST['on']) ? '1' : '0');
                flash_set('success', 'Ustawienie zapisane.');
                break;
        }
        header('Location: ' . $back); exit;
    }
}

// ── Filtry ──────────────────────────────────────────────────────────────────
$view    = isset(CRM_MAILBOX_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'new';
$mbox_f  = (int)($_GET['mailbox_id'] ?? 0);
$search  = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$sel_id  = (int)($_GET['msg'] ?? 0);

$inbox   = crm_mailbox_inbox(['view' => $view, 'mailbox_id' => $mbox_f, 'q' => $search, 'page' => $page, 'per_page' => 20]);
$counts  = crm_mailbox_counts();
$boxes   = crm_mailbox_list(false);
$users   = [];
try { $users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}

// Domyślnie otwieramy pierwszą wiadomość z listy — ekran nie jest pusty
if (!$sel_id && $inbox['rows']) $sel_id = (int)$inbox['rows'][0]['id'];
$msg = $sel_id ? crm_mailbox_message($sel_id) : null;
if ($msg && !(int)$msg['is_read'] && $can_write) {
    // Otwarcie = przeczytanie (jak w każdym kliencie poczty)
    crm_mailbox_mark_read($sel_id, true);
    $msg['is_read'] = 1;
}

$qs = static function (array $over = []) use ($view, $mbox_f, $search, $sel_id): string {
    return http_build_query(array_filter(array_merge([
        'view' => $view, 'mailbox_id' => $mbox_f ?: null, 'q' => $search ?: null, 'msg' => $sel_id ?: null,
    ], $over), static fn($v) => $v !== null && $v !== ''));
};

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.mb-wrap { display:grid; grid-template-columns: minmax(300px, 380px) 1fr; gap:1rem; align-items:start }
@media (max-width: 991px) { .mb-wrap { grid-template-columns: 1fr } }
.mb-list { background:#fff; border:1px solid #E5E7EB; border-radius:10px; overflow:hidden }
.mb-item { display:block; padding:.6rem .8rem; border-bottom:1px solid #F3F4F6; text-decoration:none; color:#111827 }
.mb-item:last-child { border-bottom:none }
.mb-item:hover { background:#F9FAFB }
.mb-item.active { background:#EEF4FF; box-shadow:inset 3px 0 0 var(--crm-primary) }
.mb-item.unread .mb-subj { font-weight:700 }
.mb-subj { font-size:.86rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.mb-from { font-size:.76rem; color:#6B7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.mb-date { font-size:.72rem; color:#9CA3AF; white-space:nowrap }
.mb-dot { width:8px; height:8px; border-radius:50%; background:var(--crm-primary); flex-shrink:0 }
.mb-pane { background:#fff; border:1px solid #E5E7EB; border-radius:10px }
.mb-pane .hd { padding:.7rem .95rem; border-bottom:1px solid #F3F4F6 }
.mb-pane .bd { padding:.95rem }
.mb-body { font-size:.9rem; line-height:1.55; overflow-wrap:anywhere }
.mb-body img { max-width:100%; height:auto }
.mb-pill { display:inline-flex; align-items:center; gap:.35rem; padding:.3rem .75rem; border-radius:2rem;
  font-size:.78rem; font-weight:600; text-decoration:none; border:2px solid transparent }
.mb-ctx { font-size:.8rem }
.mb-ctx a { text-decoration:none }
</style>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-inbox-fill" style="color:#0176D3"></i> Skrzynka CRM</div>
    <div class="crm-page-subtitle">
      Wspólna skrzynka odbiorcza — ta sama baza wiadomości co Poczta EZD, widok sprzedażowy
    </div>
  </div>
  <div class="crm-page-actions">
    <?php if (is_admin()): ?>
    <form method="post" class="d-flex gap-1">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="scan">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <?php if (count($boxes) > 1): ?>
      <select name="scan_mailbox" class="form-select form-select-sm" style="max-width:210px">
        <option value="">wszystkie skrzynki</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>"><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-crm-outline btn-sm" <?= $ready && $boxes ? '' : 'disabled' ?>>
        <i class="bi bi-arrow-repeat me-1"></i>Sprawdź teraz
      </button>
    </form>
    <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-crm-ghost btn-sm" title="Konfiguracja skrzynek">
      <i class="bi bi-gear"></i>
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warning">
  <strong>Moduł poczty nie jest gotowy.</strong> Skrzynka CRM czyta wiadomości pobierane przez moduł
  poczty (skrzynki M365). Skonfiguruj skrzynkę w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a>.
</div>
<?php elseif (!$boxes): ?>
<div class="alert alert-info">
  Nie dodano jeszcze żadnej skrzynki. Dodaj ją w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a> —
  wiadomości pojawią się tu po pierwszym skanowaniu.
</div>
<?php endif; ?>

<?php if (is_admin()): ?>
<form method="post" class="card border-0 shadow-sm mb-3"><div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="autocreate">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="form-check form-switch mb-0">
    <input class="form-check-input" type="checkbox" role="switch" name="on" value="1" id="acsw"
           <?= crm_mailbox_autocreate() ? 'checked' : '' ?> onchange="this.form.submit()">
    <label class="form-check-label" for="acsw" style="font-size:.85rem">
      Zakładaj kartotekę dla nieznanego nadawcy
    </label>
  </div>
  <span class="text-muted" style="font-size:.78rem">
    Wyłączone = wiadomości od osób poza kartoteką są pomijane (tak działał dotąd Inbox EZD),
    więc zapytania od nowych klientów nie trafiają do CRM.
  </span>
</div></form>
<?php endif; ?>

<!-- Widoki -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach (CRM_MAILBOX_VIEWS as $vk => $vv): $n = (int)($counts[$vk] ?? 0); ?>
  <a href="?<?= $qs(['view' => $vk, 'msg' => null, 'page' => null]) ?>" class="mb-pill"
     style="background:<?= $view === $vk ? 'var(--crm-primary-bg)' : '#F3F4F6' ?>;
            color:<?= $view === $vk ? 'var(--crm-primary)' : '#374151' ?>;
            border-color:<?= $view === $vk ? 'var(--crm-primary)' : 'transparent' ?>">
    <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
    <?php if ($n): ?><strong><?= $n ?></strong><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="row g-2 align-items-center">
    <div class="col-md-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input name="q" class="form-control" placeholder="Temat, nadawca, kontakt…" value="<?= h($search) ?>">
      </div>
    </div>
    <?php if (count($boxes) > 1): ?>
    <div class="col-md-4">
      <select name="mailbox_id" class="form-select form-select-sm">
        <option value="">— wszystkie skrzynki —</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $mbox_f === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filtruj</button>
      <?php if ($search || $mbox_f): ?>
      <a href="?view=<?= h($view) ?>" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</div></form>

<div class="mb-wrap">

  <!-- ── LISTA ──────────────────────────────────────────────────────────── -->
  <div>
    <div class="mb-list" role="list" aria-label="Wiadomości">
      <?php if (!$inbox['rows']): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox display-6 d-block mb-2 opacity-25"></i>
        <div style="font-size:.88rem">Brak wiadomości w tym widoku.</div>
      </div>
      <?php else: foreach ($inbox['rows'] as $r):
        $act = (int)$r['id'] === $sel_id; ?>
      <a role="listitem" class="mb-item<?= $act ? ' active' : '' ?><?= (int)$r['is_read'] ? '' : ' unread' ?>"
         href="?<?= $qs(['msg' => (int)$r['id']]) ?>"
         aria-current="<?= $act ? 'true' : 'false' ?>">
        <div class="d-flex align-items-center gap-2">
          <?php if (!(int)$r['is_read']): ?><span class="mb-dot" aria-label="Nieprzeczytana"></span><?php endif; ?>
          <div class="mb-subj flex-grow-1"><?= h($r['subject'] ?: '(bez tematu)') ?></div>
          <?php if ((int)$r['has_attachments']): ?><i class="bi bi-paperclip text-muted" aria-label="Załącznik"></i><?php endif; ?>
          <span class="mb-date"><?= h(date('d.m H:i', strtotime((string)$r['sent_at']))) ?></span>
        </div>
        <div class="mb-from">
          <?= h($r['from_name'] ?: $r['from_email'] ?: $r['contact_name'] ?: '—') ?>
          <?php if (!empty($r['assigned_name'])): ?>
          · <span style="color:#4338CA"><i class="bi bi-person-check"></i> <?= h($r['assigned_name']) ?></span>
          <?php endif; ?>
          <?php if ($r['inbox_status'] === 'archived'): ?>
          · <span class="text-success">załatwione</span>
          <?php elseif ($r['inbox_status'] === 'spam'): ?>
          · <span class="text-danger">spam</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; endif; ?>
    </div>

    <?php $pages = (int)ceil($inbox['total'] / max(1, $inbox['per_page'])); if ($pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-2">
      <small class="text-muted">Łącznie: <strong><?= (int)$inbox['total'] ?></strong></small>
      <div class="d-flex gap-1 flex-wrap">
        <?php for ($p = 1; $p <= min($pages, 12); $p++): ?>
        <a href="?<?= $qs(['page' => $p, 'msg' => null]) ?>"
           class="btn btn-sm <?= $p === (int)$inbox['page'] ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── TREŚĆ ──────────────────────────────────────────────────────────── -->
  <div>
  <?php if (!$msg): ?>
    <div class="mb-pane"><div class="bd text-muted" style="font-size:.9rem">
      Wybierz wiadomość z listy.
    </div></div>
  <?php else: ?>
    <div class="mb-pane">
      <div class="hd">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
          <div style="min-width:0">
            <div class="fw-bold" style="font-size:1rem"><?= h($msg['subject'] ?: '(bez tematu)') ?></div>
            <div class="text-muted" style="font-size:.8rem">
              <?= h($msg['from_name'] ?: '') ?>
              <?php if (!empty($msg['from_email'])): ?>&lt;<?= h($msg['from_email']) ?>&gt;<?php endif; ?>
              · <?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?>
              <?php if (!empty($msg['mailbox_name'])): ?> · do <?= h($msg['mailbox_name']) ?><?php endif; ?>
            </div>
          </div>
          <div class="text-end" style="font-size:.8rem">
            <?php if (!empty($msg['contact_id'])): ?>
            <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$msg['contact_id'] ?>" class="fw-semibold">
              <i class="bi <?= h(CRM_CONTACT_TYPES[$msg['contact_type']]['icon'] ?? 'bi-person') ?>"></i>
              <?= h($msg['contact_name']) ?>
            </a>
            <div class="text-muted"><?= h(CRM_CONTACT_TYPES[$msg['contact_type']]['label'] ?? '') ?></div>
            <?php endif; ?>
            <?php if (!empty($msg['assigned_name'])): ?>
            <div style="color:#4338CA"><i class="bi bi-person-check"></i> <?= h($msg['assigned_name']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($can_write): ?>
        <div class="d-flex gap-1 flex-wrap mt-2">
          <?php
          $hidden = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
                  . '<input type="hidden" name="msg_id" value="' . (int)$msg['id'] . '">'
                  . '<input type="hidden" name="view" value="' . h($view) . '">'
                  . '<input type="hidden" name="q" value="' . h($search) . '">';
          ?>
          <?php if ((int)($msg['assigned_to'] ?? 0) !== $uid): ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="assign_me">
            <button class="btn btn-crm-primary btn-sm"><i class="bi bi-person-check me-1"></i>Wezmę to</button></form>
          <?php endif; ?>
          <?php if (!empty($msg['contact_id'])): ?>
          <button class="btn btn-crm-outline btn-sm" onclick="openCommModal(<?= (int)$msg['contact_id'] ?>,'email')">
            <i class="bi bi-reply me-1"></i>Odpowiedz
          </button>
          <?php endif; ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="case">
            <button class="btn btn-crm-outline btn-sm"><i class="bi bi-briefcase me-1"></i>Utwórz sprawę</button></form>
          <?php if (!empty($msg['contact_id'])): ?>
          <a class="btn btn-crm-outline btn-sm" href="<?= APP_URL ?>/crm/offers/form.php?contact_id=<?= (int)$msg['contact_id'] ?>">
            <i class="bi bi-file-earmark-ruled me-1"></i>Nowa oferta
          </a>
          <?php endif; ?>
          <?php if ($msg['inbox_status'] === 'active'): ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="archive">
            <button class="btn btn-crm-ghost btn-sm"><i class="bi bi-archive me-1"></i>Załatwione</button></form>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="spam">
            <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako spam"><i class="bi bi-slash-circle"></i></button></form>
          <?php else: ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="restore">
            <button class="btn btn-crm-ghost btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć</button></form>
          <?php endif; ?>
          <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unread">
            <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako nieprzeczytane"><i class="bi bi-envelope"></i></button></form>
          <form method="post" class="d-flex gap-1"><?= $hidden ?><input type="hidden" name="_op" value="assign">
            <select name="user_id" class="form-select form-select-sm" style="max-width:190px" onchange="this.form.submit()">
              <option value="">— przypisz komuś —</option>
              <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)($msg['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                <?= h($u['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <div class="bd">
        <?php if (!empty($msg['attachments'])): ?>
        <div class="mb-3" style="font-size:.82rem">
          <span class="text-muted"><i class="bi bi-paperclip"></i> Załączniki:</span>
          <?php foreach ($msg['attachments'] as $a): ?>
          <?php if (!empty($a['stored_path'])): ?>
          <a href="<?= h(upload_link($a['stored_path'])) ?>" target="_blank" class="ms-1"><?= h($a['original_name']) ?></a>
          <?php else: ?>
          <span class="ms-1" title="Plik nie został pobrany na serwer"><?= h($a['original_name']) ?></span>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="mb-body">
          <?php if (!empty($msg['body_html'])): ?>
            <?php
            // Treść z zewnątrz — przepuszczamy tylko podstawowe formatowanie,
            // bez skryptów, iframe'ów i zdarzeń on*.
            $html = (string)$msg['body_html'];
            $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
            $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html);
            $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
            $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '$1="#"', $html);
            echo $html;
            ?>
          <?php else: ?>
            <?= nl2br(h((string)$msg['body'])) ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($msg['ctx']['cases']) || !empty($msg['ctx']['offers']) || count($msg['ctx']['thread']) > 1): ?>
      <div class="bd border-top mb-ctx">
        <div class="row g-3">
          <?php if ($msg['ctx']['cases']): ?>
          <div class="col-md-4">
            <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Sprawy kontaktu</div>
            <?php foreach ($msg['ctx']['cases'] as $c): ?>
            <div><a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($msg['ctx']['offers']): ?>
          <div class="col-md-4">
            <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Oferty</div>
            <?php foreach ($msg['ctx']['offers'] as $o): ?>
            <div><a href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$o['id'] ?>"><?= h($o['offer_number']) ?></a>
              — <?= h(number_format((float)$o['total_gross'], 0, ',', ' ')) ?> <?= h($o['currency']) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (count($msg['ctx']['thread']) > 1): ?>
          <div class="col-md-4">
            <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Wątek</div>
            <?php foreach ($msg['ctx']['thread'] as $t): ?>
            <div>
              <a href="?<?= $qs(['msg' => (int)$t['id']]) ?>" class="<?= (int)$t['id'] === $sel_id ? 'fw-bold' : '' ?>">
                <i class="bi bi-<?= $t['direction'] === 'in' ? 'arrow-down-left' : 'arrow-up-right' ?>"></i>
                <?= h(date('d.m.Y H:i', strtotime((string)$t['sent_at']))) ?>
              </a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
