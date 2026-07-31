<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
if (module_enabled('org_enabled')) {
    require_once dirname(dirname(__DIR__)) . '/includes/org.php';
    require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
    require_once dirname(dirname(__DIR__)) . '/includes/notification_service.php';
}

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if (!$access) { flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

$PAGE_TITLE = $sprawa['znak_sprawy'] . ' — ' . $sprawa['title'];

$timeline    = ezd_timeline($id);
$dekretacje  = ezd_dekretacje_by_sprawa($id);
$zalaczniki  = ezd_zalaczniki_by($id);
$log_entries = ezd_log_by_sprawa($id, 30);
$users       = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$podsprawy   = ezd_podsprawy_by_parent($id);
$dokumenty   = ezd_dokumenty_by_sprawa($id);
$notatki     = ezd_notatki_by_sprawa($id);
$grupy       = ezd_grupy_by_sprawa($id);
$shares      = ezd_sprawa_share_list($id);

// Pliki repozytorium pogrupowane: grupa_id => [pliki], 0 => bez grupy
$grupy_map = [];
foreach ($grupy as $g) $grupy_map[(int)$g['id']] = [];
$bez_grupy = [];
foreach ($zalaczniki as $z) {
    $gid = (int)($z['grupa_id'] ?? 0);
    if ($gid && isset($grupy_map[$gid])) $grupy_map[$gid][] = $z;
    else $bez_grupy[] = $z;
}

$is_closed        = $sprawa['status'] === 'closed';
$can_edit_case    = $access === 'write';           // zarządzanie sprawą (metadane, współdzielenie) — niezależnie od zamknięcia
$can_act          = $can_edit_case && !$is_closed; // dodawanie treści do sprawy — tylko gdy otwarta
$can_manage_share = ezd_sprawa_can_manage_share($sprawa, $user_id);
$mini             = ezd_mini(); // tryb uproszczony — ukrywa metrykę i obieg/workflow

// Obsługa POST (upload + dekretacja + zmiana statusu sprawy)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload') {
        if (!$can_act) { http_response_code(403); exit; }
        $grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
        $custom_name = trim($_POST['custom_name'] ?? '');
        $new_zal_id = null;
        $err = ezd_upload('file', $id, $user_id, null, null, null, null, $grupa_id, $custom_name ?: null, $new_zal_id);
        $msg = $err ?: 'Plik dodany do repozytorium koszulki.';
        if (!$err && $new_zal_id && !empty($_POST['convert_pdf'])) {
            $conv = ezd_convert_to_pdf($new_zal_id, $user_id);
            $msg .= $conv['ok'] ? ' Utworzono też wersję PDF.' : (' Konwersja na PDF nie powiodła się: ' . $conv['error']);
        }
        flash_set($err ? 'error' : 'success', $msg);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'new_office_file') {
        if (!$can_act) { http_response_code(403); exit; }
        $grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
        $with_znak = !empty($_POST['with_znak']);
        $r = ezd_new_office_file($id, $user_id, $_POST['filetype'] ?? '', trim($_POST['name'] ?? ''), $grupa_id, $with_znak);
        flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Utworzono nowy plik.' : $r['error']);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'convert_pdf') {
        if (!$can_act) { http_response_code(403); exit; }
        $conv = ezd_convert_to_pdf((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set($conv['ok'] ? 'success' : 'error', $conv['ok'] ? 'Utworzono wersję PDF.' : ('Nie udało się przekonwertować: ' . $conv['error']));
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'del_file') {
        if (!$can_act) { http_response_code(403); exit; }
        ezd_zal_delete((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set('success', 'Plik usunięty.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'pismo_add' && $can_act) {
        try {
            $pid = ezd_pismo_create([
                'sprawa_id'     => $id,
                'kierunek'      => $_POST['kierunek']     ?? 'przychodzace',
                'title'         => trim($_POST['title']   ?? ''),
                'tresc'         => $_POST['tresc']        ?? '',
                'nadawca'       => trim($_POST['nadawca'] ?? ''),
                'odbiorca'      => trim($_POST['odbiorca']?? ''),
                'data_pisma'    => $_POST['data_pisma']   ?? '',
                'data_wplywu'   => $_POST['data_wplywu']  ?? '',
                'data_wysylki'  => $_POST['data_wysylki'] ?? '',
                'status'        => $_POST['status']       ?? 'nowe',
                'owner_id'      => (int)($_POST['owner_id'] ?? 0) ?: null,
                'rodzaj_medium' => $_POST['rodzaj_medium'] ?? 'papier',
            ], $user_id);
            flash_set('success', 'Pismo dodane.');
            header('Location: ' . APP_URL . '/ezd/pisma/view.php?id=' . $pid); exit;
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#pisma'); exit;
    }

    if ($action === 'grupa_add' && $can_act) {
        try { ezd_grupa_create($id, $_POST['nazwa'] ?? '', $user_id); flash_set('success','Grupa plików utworzona.'); }
        catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'grupa_rename' && $can_act) {
        try { ezd_grupa_rename((int)($_POST['grupa_id'] ?? 0), $_POST['nazwa'] ?? '', $user_id); flash_set('success','Nazwę grupy zmieniono.'); }
        catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'grupa_del' && $can_act) {
        ezd_grupa_delete((int)($_POST['grupa_id'] ?? 0), $user_id);
        flash_set('success', 'Grupę usunięto (pliki pozostały bez grupy).');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'zal_move' && $can_act) {
        ezd_zal_set_grupa((int)($_POST['zal_id'] ?? 0), (int)($_POST['grupa_id'] ?? 0) ?: null, $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'zal_przekaz' && $can_act) {
        $przekaz_user_id = (int)($_POST['przekaz_user_id'] ?? 0);
        $przekaz_zal_ids = $_POST['zal_ids'] ?? [];
        if (!$przekaz_user_id || !$przekaz_zal_ids) {
            flash_set('error', 'Wybierz co najmniej jeden plik i osobę.');
        } else {
            $n = ezd_zal_access_grant($przekaz_zal_ids, $przekaz_user_id, $user_id, trim($_POST['przekaz_note'] ?? ''));
            flash_set($n ? 'success' : 'warning', $n ? "Przekazano dostęp do {$n} plik(ów)." : 'Ta osoba miała już dostęp do wybranych plików.');
        }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'share_add' && $can_manage_share) {
        $su_id = (int)($_POST['share_user_id'] ?? 0);
        $su_upr = $_POST['share_uprawnienie'] ?? 'odczyt';
        if ($su_id) { ezd_sprawa_share_add($id, $su_id, $su_upr, $user_id); flash_set('success', 'Sprawa udostępniona.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '&share=1'); exit;
    }
    if ($action === 'share_del' && $can_manage_share) {
        ezd_sprawa_share_remove($id, (int)($_POST['share_user_id'] ?? 0), $user_id);
        flash_set('success', 'Odebrano współdzielenie.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '&share=1'); exit;
    }

    if ($action === 'dekretacja' && $can_act) {
        try {
            $unit_id_d    = (int)($_POST['unit_id'] ?? 0) ?: null;
            $wykonawca_id = (int)($_POST['wykonawca_id'] ?? 0);

            // Jeśli dekretacja na jednostkę i brak wykonawcy — ustaw głowę jednostki
            if ($unit_id_d && !$wykonawca_id && module_enabled('org_enabled')) {
                $head = org_unit_head($unit_id_d);
                if ($head) $wykonawca_id = (int)$head['user_id'];
            }
            if (!$wykonawca_id) throw new \RuntimeException('Wybierz osobę lub jednostkę, której przekazujesz koszulkę.');

            $dekr_id = ezd_dekretacja_create([
                'sprawa_id'    => $id,
                'pismo_id'     => null,
                'umowa_id'     => null,
                'wykonawca_id' => $wykonawca_id,
                'unit_id'      => $unit_id_d,
                'dyspozycja'   => $_POST['dyspozycja'] ?? 'do_zalat',
                'tresc'        => trim($_POST['tresc'] ?? ''),
                'deadline'     => $_POST['deadline'] ?: null,
            ], $user_id);

            // Wyślij powiadomienie e-mail
            if (module_enabled('org_enabled') && $dekr_id) {
                try {
                    if ($unit_id_d) {
                        NotificationService::onUnitAssignment($dekr_id, $unit_id_d);
                    } else {
                        NotificationService::onDekretacja($dekr_id);
                    }
                } catch (\Throwable $e) { /* powiadomienie nie blokuje akcji */ }
            }

            flash_set('success', 'Przekazano.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    if ($action === 'dekr_done') {
        ezd_dekretacja_complete((int)($_POST['dekr_id'] ?? 0), $user_id);
        flash_set('success', 'Zadanie oznaczone jako wykonane.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    if ($action === 'set_etap' && $can_act) {
        try {
            ezd_sprawa_set_etap($id, $_POST['etap'] ?? '', $user_id);
            flash_set('success', 'Zmieniono etap obiegu koszulki.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#workflow'); exit;
    }

    // ── Notatki ──────────────────────────────────────────────────────────────
    if ($action === 'note_add' && $can_act) {
        $tresc = trim($_POST['tresc'] ?? '');
        if ($tresc !== '') { ezd_notatka_create($id, $tresc, $user_id); flash_set('success', 'Notatka dodana.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_edit' && $can_act) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_update((int)$n['id'], trim($_POST['tresc'] ?? ''), $user_id);
            flash_set('success', 'Notatka zaktualizowana.');
        } else { flash_set('error', 'Brak uprawnień do edycji notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_del' && $can_act) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_delete((int)$n['id'], $user_id);
            flash_set('success', 'Notatka usunięta.');
        } else { flash_set('error', 'Brak uprawnień do usunięcia notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_pin' && $can_act) {
        ezd_notatka_toggle_pin((int)($_POST['note_id'] ?? 0), $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }

    header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Hero koszulki ───────────────────────── */
.ezd-hero{display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap;background:linear-gradient(135deg,#fef2f2 0%,#ffffff 60%);border:1px solid #fee2e2;border-radius:16px;padding:1.15rem 1.4rem;margin-bottom:1.5rem;}
.ezd-hero-icon{width:52px;height:52px;border-radius:14px;background:#dc2626;color:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.55rem;box-shadow:0 4px 10px rgba(220,38,38,.28);}
.ezd-hero-title{font-size:1.3rem;font-weight:800;color:#0f172a;line-height:1.2;margin:.15rem 0 .1rem;}
.ezd-hero-desc{font-size:.83rem;color:#5b6472;line-height:1.5;}

/* ── Bento karty ─────────────────────────── */
.bc{background:#fff;border:1.5px solid #e8edf3;border-radius:12px;overflow:hidden;margin-bottom:1rem;}
.bc-h{padding:.55rem 1rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;border-bottom:1px solid #f1f5f9;background:#fafbfc;display:flex;align-items:center;gap:.4rem;}
.bc-b{padding:.8rem 1rem;}

/* ── Timeline ────────────────────────────── */
.tl-wrap{position:relative;padding-left:2rem;}
.tl-wrap::before{content:'';position:absolute;left:.55rem;top:0;bottom:0;width:2px;background:#e2e8f0;border-radius:2px;}
.tl-item{position:relative;margin-bottom:1.1rem;}
.tl-dot{position:absolute;left:-1.45rem;top:.3rem;width:14px;height:14px;border-radius:50%;border:2.5px solid #fff;box-shadow:0 0 0 2px #2563eb;background:#2563eb;}
.tl-dot.pismo-in  {background:#0891b2;box-shadow:0 0 0 2px #0891b2;}
.tl-dot.pismo-out {background:#2563eb;box-shadow:0 0 0 2px #2563eb;}
.tl-dot.pismo-int {background:#94a3b8;box-shadow:0 0 0 2px #94a3b8;}
.tl-dot.umowa-card{background:#d97706;box-shadow:0 0 0 2px #d97706;}

.tl-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.75rem 1rem;transition:border-color .15s,box-shadow .15s;text-decoration:none;color:inherit;display:block;}
.tl-card:hover{border-color:#93c5fd;box-shadow:0 2px 10px rgba(37,99,235,.1);}
.tl-card.umowa-card{border-left:3px solid #d97706;}
.tl-card.pismo-in  {border-left:3px solid #0891b2;}
.tl-card.pismo-out {border-left:3px solid #2563eb;}
.tl-syg{font-family:monospace;font-size:.73rem;color:#64748b;font-weight:600;}
.tl-title{font-weight:700;font-size:.88rem;color:#1e293b;margin:.1rem 0;}
.tl-meta{font-size:.72rem;color:#94a3b8;display:flex;flex-wrap:wrap;gap:.3rem .75rem;}

/* ── Dekretacja ──────────────────────────── */
.dekr-row{display:flex;align-items:flex-start;gap:.65rem;padding:.45rem 0;border-bottom:1px solid #f8fafc;font-size:.78rem;}
.dekr-row:last-child{border-bottom:none;}

/* ── Stepper workflow BPM ────────────────── */
.ezd-stepper{display:flex;align-items:flex-start;gap:.25rem;overflow-x:auto;padding:.25rem 0;}
.ezd-step{flex:1 1 0;min-width:64px;text-align:center;position:relative;}
.ezd-step::before{content:'';position:absolute;top:14px;left:-50%;width:100%;height:2px;background:#e2e8f0;z-index:0;}
.ezd-step:first-child::before{display:none;}
.ezd-step-dot{position:relative;z-index:1;width:30px;height:30px;border-radius:50%;margin:0 auto .35rem;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#94a3b8;font-size:.85rem;border:2px solid #fff;box-shadow:0 0 0 1px #e2e8f0;}
.ezd-step-lbl{font-size:.66rem;color:#94a3b8;line-height:1.15;}
.ezd-step-done .ezd-step-dot{background:#22c55e;color:#fff;box-shadow:0 0 0 1px #22c55e;}
.ezd-step-done::before{background:#22c55e;}
.ezd-step-done .ezd-step-lbl{color:#16a34a;}
.ezd-step-current .ezd-step-dot{background:#2563eb;color:#fff;box-shadow:0 0 0 3px #bfdbfe;}
.ezd-step-current .ezd-step-lbl{color:#1d4ed8;font-weight:700;}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>"><?= h($sprawa['teczka_symbol']) ?></a></li>
    <li class="breadcrumb-item active"><?= h($sprawa['znak_sprawy']) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if ($is_closed): ?>
<div class="alert alert-secondary d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.84rem">
  <i class="bi bi-lock-fill"></i>
  <span>Koszulka jest <strong>zamknięta</strong>. Dodawanie dokumentów i edycja są zablokowane.
  <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>">Przywróć</a><?php endif; ?></span>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- ══ LEWA: Timeline ══════════════════════════════════════════════════════ -->
  <div class="col-lg-8">

    <!-- Nagłówek sprawy (hero) -->
    <div class="ezd-hero">
      <div class="ezd-hero-icon"><i class="bi bi-folder2-open"></i></div>
      <div class="d-flex align-items-start gap-3 flex-wrap flex-grow-1">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <code class="bg-white px-2 py-0 rounded fw-bold border" style="font-size:.82rem;color:#1d4ed8"><?= h($sprawa['znak_sprawy']) ?></code>
              <?= ezd_status_badge_sprawa($sprawa['status']) ?>
              <?= ezd_priority_badge($sprawa['priority']) ?>
              <?php if(!empty($sprawa['ciagla'])): ?>
              <span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.7rem"><i class="bi bi-infinity me-1"></i>Koszulka ciągła</span>
              <?php elseif($sprawa['deadline']): ?>
              <span class="<?= $sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'text-muted' ?>" style="font-size:.76rem">
                <i class="bi bi-calendar-event me-1"></i><?= date_pl($sprawa['deadline']) ?>
              </span>
              <?php endif; ?>
            </div>
            <h1 class="ezd-hero-title"><?= h($sprawa['title']) ?></h1>
            <?php if($sprawa['parent_id']): ?>
            <div class="mb-1" style="font-size:.78rem">
              <span class="badge bg-info bg-opacity-15 text-info border border-info"><i class="bi bi-diagram-3 me-1"></i>Podkoszulka</span>
              <span class="text-muted ms-1">sprawy <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa['parent_id'] ?>" class="font-monospace text-decoration-none"><?= h($sprawa['parent_znak']) ?></a></span>
            </div>
            <?php endif; ?>
            <?php if($sprawa['description']): ?>
            <div class="ezd-hero-desc"><?= nl2br(h($sprawa['description'])) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap flex-shrink-0">
            <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" title="Drukuj koszulkę do PDF (wszystkie lub jeden dokument)">
              <i class="bi bi-printer me-1"></i>Drukuj koszulkę
            </a>
            <?php if(!$mini): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/metryka.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary" title="Metryka koszulki">
              <i class="bi bi-clipboard-check me-1"></i>Metryka
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#notatkiModal">
              <i class="bi bi-sticky me-1"></i>Notatki
              <?php if($notatki): ?><span class="badge rounded-pill bg-secondary ms-1"><?= count($notatki) ?></span><?php endif; ?>
            </button>
            <?php if($can_manage_share || $shares): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#shareModal" title="Współdzielenie koszulki">
              <i class="bi bi-people me-1"></i>Współdzielenie
              <?php if($shares): ?><span class="badge rounded-pill bg-secondary ms-1"><?= count($shares) ?></span><?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if($can_act): ?>
            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#pismoModal">
              <i class="bi bi-envelope-plus me-1"></i>Pismo
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#dekrModal">
              <i class="bi bi-person-lines-fill me-1"></i>Przekaż osobie
            </button>
            <?php endif; ?>
            <?php if($can_edit_case): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary" title="Przerejestruj koszulkę do Nowego JRWA (asystent AI)">
              <i class="bi bi-stars me-1"></i>Przerejestruj
            </a>
            <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif; ?>
          </div>
      </div>
    </div>

    <!-- Workflow BPM — etapy obiegu (wg JRWA sprawy) -->
    <?php if(!$mini): ?>
    <?php
      $wf_steps  = ezd_sprawa_workflow($sprawa);
      $wf_keys   = array_column($wf_steps, 'key');
      $cur_etap  = $sprawa['etap'] ?: ($wf_keys[0] ?? 'wszczeta');
      $cur_idx   = array_search($cur_etap, $wf_keys, true);
      if ($cur_idx === false) $cur_idx = -1; // etap spoza tego workflow → przed startem
      $next_etap = $wf_keys[$cur_idx+1] ?? null;
      $next_step = $next_etap !== null ? $wf_steps[$cur_idx+1] : null;
      $wf_custom = (bool) ezd_workflow_get((int)($sprawa['jrwa_id'] ?? 0));
    ?>
    <div class="d-flex align-items-center gap-2 mb-4" id="workflow" style="font-size:.8rem">
      <span class="text-muted"><i class="bi bi-diagram-2 me-1"></i>Etap:</span>
      <?= ezd_etap_badge($cur_etap) ?>
      <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none" data-bs-toggle="modal" data-bs-target="#etapModal">
        <i class="bi bi-arrow-left-right me-1"></i>Zmień etap
      </button>
    </div>

    <div class="modal fade" id="etapModal" tabindex="-1" aria-labelledby="etapModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5" id="etapModalLabel"><i class="bi bi-diagram-2 text-primary me-2" aria-hidden="true"></i>Obieg koszulki
              <?php if($wf_custom): ?><span class="badge bg-info bg-opacity-15 text-info border border-info ms-2" style="font-size:.6rem">wg JRWA <?= h($sprawa['teczka_symbol']) ?></span><?php endif; ?>
            </h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
          </div>
          <div class="modal-body">
            <?php if(is_admin()): ?>
            <div class="text-end mb-2"><a href="<?= APP_URL ?>/admin/ezd_workflows.php?jrwa_id=<?= (int)($sprawa['jrwa_id'] ?? 0) ?>" class="text-muted" style="font-size:.75rem"><i class="bi bi-pencil me-1"></i>Edytuj workflow tej JRWA</a></div>
            <?php endif; ?>
            <div class="ezd-stepper">
              <?php foreach ($wf_steps as $i => $st):
                $state = $i < $cur_idx ? 'done' : ($i === $cur_idx ? 'current' : 'todo'); ?>
              <div class="ezd-step ezd-step-<?= $state ?>" title="<?= h($st['label']) ?>">
                <div class="ezd-step-dot"><i class="bi <?= $state==='done'?'bi-check-lg':($st['icon'] ?: 'bi-record-circle') ?>"></i></div>
                <div class="ezd-step-lbl"><?= h($st['label']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if($can_act): ?>
            <div class="d-flex gap-2 flex-wrap align-items-center mt-3 pt-3 border-top">
              <?php if($next_step): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="set_etap">
                <input type="hidden" name="etap" value="<?= h($next_etap) ?>">
                <button class="btn btn-sm btn-primary"><i class="bi <?= h($next_step['icon'] ?: 'bi-arrow-right') ?> me-1"></i>Dalej: <?= h($next_step['label']) ?> →</button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-inline d-flex gap-1">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="set_etap">
                <select name="etap" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                  <option disabled selected>Przejdź do etapu…</option>
                  <?php foreach($wf_steps as $st): ?>
                  <option value="<?= h($st['key']) ?>" <?= $st['key']===$cur_etap?'disabled':'' ?>><?= h($st['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; /* !mini */ ?>

    <?php if ($podsprawy): ?>
    <!-- Podsprawy -->
    <div class="mt-4" id="podsprawy">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="text-muted text-uppercase fw-bold mb-0" style="font-size:.7rem;letter-spacing:.1em">
          <i class="bi bi-diagram-3 me-1"></i>Podkoszulki (<?= count($podsprawy) ?>)
        </h6>
      </div>
      <div class="card shadow-sm"><div class="card-body p-0">
        <?php foreach($podsprawy as $ps): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $ps['id'] ?>" class="d-flex align-items-center gap-2 px-3 py-2 border-bottom text-decoration-none text-reset" style="font-size:.82rem">
          <i class="bi bi-folder2 text-info flex-shrink-0"></i>
          <code class="flex-shrink-0" style="font-size:.72rem;color:#1d4ed8"><?= h($ps['znak_sprawy']) ?></code>
          <span class="flex-grow-1 text-truncate fw-semibold"><?= h($ps['title']) ?></span>
          <?= ezd_priority_badge($ps['priority']) ?>
          <?= ezd_status_badge_sprawa($ps['status']) ?>
        </a>
        <?php endforeach; ?>
      </div></div>
    </div>
    <?php endif; ?>

    <!-- Repozytorium plików sprawy -->
    <div class="mt-4" id="files">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="text-muted text-uppercase fw-bold mb-0" style="font-size:.7rem;letter-spacing:.1em">
          <i class="bi bi-folder2 me-1"></i>Repozytorium plików koszulki (<?= count($zalaczniki) ?>)
        </h6>
        <?php if($can_act): ?>
        <div class="d-flex gap-2">
          <div class="dropdown">
            <button class="btn btn-xs btn-outline-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-file-earmark-plus me-1"></i>Nowy plik
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item ezd-new-office-file" href="#" data-type="docx" data-bs-toggle="modal" data-bs-target="#newOfficeFileModal"><i class="bi bi-file-earmark-word text-primary me-2"></i>Word (.docx)</a></li>
              <li><a class="dropdown-item ezd-new-office-file" href="#" data-type="xlsx" data-bs-toggle="modal" data-bs-target="#newOfficeFileModal"><i class="bi bi-file-earmark-excel text-success me-2"></i>Excel (.xlsx)</a></li>
            </ul>
          </div>
          <a href="<?= APP_URL ?>/ezd/sprawy/spinacz.php?sprawa_id=<?= $id ?>" class="btn btn-xs btn-outline-info btn-sm" title="Połącz kilka plików w jeden PDF"><i class="bi bi-paperclip me-1"></i>Spinacz</a>
          <button class="btn btn-xs btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#new-grupa"><i class="bi bi-folder-plus me-1"></i>Nowa grupa</button>
          <?php if($zalaczniki): ?>
          <button class="btn btn-xs btn-outline-warning btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#przekazDokModal"><i class="bi bi-send-check me-1"></i>Przekaż dokumenty</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if($can_act): ?>
      <div class="collapse mb-3" id="new-grupa">
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="grupa_add">
          <input type="text" name="nazwa" class="form-control form-control-sm" placeholder="Nazwa grupy, np. Faktury / Korespondencja / Załączniki do umowy" required>
          <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
        </form>
      </div>
      <?php endif; ?>

      <?php
      // Opcje grup do wyboru w modalu przenoszenia pliku (jedna wspólna lista, bez zaznaczenia — ustawiane przez JS)
      $grupaOptsPlain = '<option value="0">— bez grupy —</option>';
      foreach ($grupy as $g) {
          $grupaOptsPlain .= '<option value="'.$g['id'].'">'.h($g['nazwa']).'</option>';
      }
      // Funkcja renderująca wiersz pliku (z przenoszeniem między grupami)
      $renderZal = function(array $z) use ($can_act, $grupy) {
          // Wykrycie podpisu elektronicznego (tylko dla istotnych rozszerzeń)
          $sig  = ['signed'=>false];
          $zext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
          if (in_array($zext, EZD_SIG_EXTS, true)) {
              $sig = ezd_signature_info(UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'], $z['original_name']);
          }
          ?>
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.8rem">
            <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>"
                 class="text-decoration-none fw-semibold text-truncate d-block<?= $zext === 'pdf' ? ' ezd-pdf-btn' : '' ?>"
                 <?php if ($zext === 'pdf'): ?>data-url="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>" title="Podgląd PDF"<?php endif; ?>><?= h($z['original_name']) ?>
                <?php if(!empty($sig['signed'])): ?><span class="badge bg-success bg-opacity-15 text-success border border-success ms-1" style="font-size:.6rem"><i class="bi bi-patch-check-fill me-1"></i>Podpis el.</span><?php endif; ?>
              </a>
              <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader'] ?? '—') ?> · <?= date('d.m.Y H:i', strtotime($z['uploaded_at'])) ?>
                <?php if($z['wersja']>1): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">v<?= $z['wersja'] ?></span><?php endif; ?>
                <?php $_zacc = ezd_zal_access_list((int)$z['id']); if($_zacc): ?>
                <span class="badge bg-info bg-opacity-15 text-info border border-info ms-1" style="font-size:.6rem"
                      title="Przekazano: <?= h(implode(', ', array_column($_zacc,'user_name'))) ?>">
                  <i class="bi bi-send-check me-1"></i><?= count($_zacc) ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
            <?php if(!empty($sig['signed'])): ?>
            <button type="button" class="btn btn-xs btn-outline-success btn-sm ezd-sig-btn flex-shrink-0" title="Dane podpisu elektronicznego"
              data-zal="<?= (int)$z['id'] ?>"
              data-file="<?= h($z['original_name']) ?>" data-type="<?= h((string)$sig['type']) ?>"
              data-signer="<?= h((string)($sig['signer'] ?? '')) ?>" data-date="<?= h((string)($sig['signed_at'] ?? '')) ?>"
              data-reason="<?= h((string)($sig['reason'] ?? '')) ?>" data-location="<?= h((string)($sig['location'] ?? '')) ?>"
              data-note="<?= h((string)($sig['note'] ?? '')) ?>"><i class="bi bi-patch-check"></i></button>
            <?php endif; ?>
            <?php if($can_act && $grupy): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm ezd-move-grupa-btn flex-shrink-0" title="Przenieś do grupy"
                    data-bs-toggle="modal" data-bs-target="#zalMoveGroupModal"
                    data-zal="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>" data-grupa="<?= (int)($z['grupa_id'] ?? 0) ?>">
              <i class="bi bi-folder-symlink"></i>
            </button>
            <?php endif; ?>
            <?php if (in_array($zext, EZD_OFFICE_ONLINE_EXT, true)): ?>
            <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $z['id'] ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-primary btn-sm" title="Otwórz w Office Online"><i class="bi bi-microsoft"></i></a>
            <?php if (!empty($z['sp_web_url'])):
              $_office_scheme = in_array($zext, ['xls','xlsx'], true) ? 'ms-excel' : 'ms-word';
            ?>
            <a href="<?= h($_office_scheme) ?>:ofe|u|<?= rawurlencode($z['sp_web_url']) ?>" class="btn btn-xs btn-outline-primary btn-sm" title="Otwórz w aplikacji <?= $_office_scheme==='ms-excel'?'Excel':'Word' ?> (desktop) — zmiany zapiszą się automatycznie w SharePoincie"><i class="bi bi-window-desktop"></i></a>
            <?php endif; ?>
            <?php if (!empty($z['sp_web_url'])): ?>
            <button type="button" class="btn btn-xs btn-outline-success btn-sm ezd-oop-btn" title="Zapisz zmiany z Office Online"
                    data-bs-toggle="modal" data-bs-target="#officeOnlinePullModal"
                    data-zal="<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"><i class="bi bi-cloud-arrow-down"></i></button>
            <?php endif; ?>
            <?php elseif (!empty($z['sp_web_url'])): ?>
            <a href="<?= h($z['sp_web_url']) ?>" target="_blank" rel="noopener" class="btn btn-xs btn-outline-secondary btn-sm" title="Otwórz na SharePoint"><i class="bi bi-cloud-check"></i></a>
            <?php endif; ?>
            <?php if ($can_act && in_array($zext, EZD_PDF_CONVERTIBLE_EXT, true)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Przekonwertować plik na PDF? Powstanie osobny plik PDF obok oryginału.');">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="convert_pdf">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-sm" title="Konwertuj na PDF"><i class="bi bi-filetype-pdf"></i></button>
            </form>
            <?php endif; ?>
            <?php if ($zext === 'pdf'): ?>
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm ezd-pdf-btn"
               data-url="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>" title="Podgląd PDF"><i class="bi bi-eye"></i></a>
            <?php endif; ?>
            <?php if (in_array($zext, ['eml', 'msg'], true)): ?>
            <button type="button" class="btn btn-xs btn-outline-primary btn-sm ezd-email-btn flex-shrink-0"
                    data-id="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>"
                    title="Podgląd wiadomości e-mail"><i class="bi bi-envelope-open"></i></button>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>&dl=1" class="btn btn-xs btn-outline-secondary btn-sm"><i class="bi bi-download"></i></a>
            <?php if(module_enabled('obiegi_enabled')): ?>
            <a href="<?= APP_URL ?>/obiegi/new.php?ezd_sprawa_id=<?= $id ?>&ezd_zalacznik_id=<?= $z['id'] ?>"
               class="btn btn-xs btn-outline-primary btn-sm" title="Uruchom obieg z tym plikiem"><i class="bi bi-diagram-2"></i></a>
            <?php endif; ?>
            <?php if($can_act): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="del_file">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php
      };
      ?>

      <div class="card shadow-sm">
        <div class="card-body p-0">
          <?php if(!$zalaczniki && !$grupy): ?>
          <div class="text-center text-muted py-3" style="font-size:.8rem">Brak plików w repozytorium koszulki</div>
          <?php endif; ?>

          <?php foreach ($grupy as $g): ?>
          <div class="px-3 py-2 bg-light d-flex align-items-center gap-2" style="font-size:.76rem;border-bottom:1px solid #f1f5f9">
            <i class="bi bi-folder-fill text-warning"></i>
            <span class="fw-bold"><?= h($g['nazwa']) ?></span>
            <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= (int)$g['plik_count'] ?></span>
            <?php if($can_act): ?>
            <div class="ms-auto d-flex gap-1">
              <button class="btn btn-xs btn-link p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#grupa-edit-<?= $g['id'] ?>" title="Edytuj nazwę grupy"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć grupę? Pliki pozostaną w repozytorium (bez grupy).')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="grupa_del">
                <input type="hidden" name="grupa_id" value="<?= $g['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-muted" title="Usuń grupę"><i class="bi bi-x-circle"></i></button>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php if($can_act): ?>
          <div class="collapse px-3 py-2 bg-light border-bottom" id="grupa-edit-<?= $g['id'] ?>">
            <form method="post" class="d-flex gap-2">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="grupa_rename">
              <input type="hidden" name="grupa_id" value="<?= $g['id'] ?>">
              <input type="text" name="nazwa" class="form-control form-control-sm" value="<?= h($g['nazwa']) ?>" required>
              <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
            </form>
          </div>
          <?php endif; ?>
          <?php if($grupy_map[(int)$g['id']]): foreach($grupy_map[(int)$g['id']] as $z) $renderZal($z); else: ?>
          <div class="text-muted px-4 py-2" style="font-size:.74rem">Grupa pusta — przenieś tu pliki z listy poniżej.</div>
          <?php endif; ?>
          <?php endforeach; ?>

          <?php if($grupy && $bez_grupy): ?>
          <div class="px-3 py-2 bg-light" style="font-size:.76rem;border-bottom:1px solid #f1f5f9"><i class="bi bi-folder2 me-1 text-muted"></i><span class="fw-bold text-muted">Bez grupy</span></div>
          <?php endif; ?>
          <?php foreach ($bez_grupy as $z) $renderZal($z); ?>

          <?php if($can_act): ?>
          <form method="post" enctype="multipart/form-data" class="p-3 border-top" id="ezd-upload-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="upload">
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <input type="file" id="ezd-upload-file" name="file" class="form-control form-control-sm" style="max-width:260px"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg" required>
              <input type="text" name="custom_name" class="form-control form-control-sm" style="max-width:240px"
                     placeholder="Własna nazwa (opcjonalnie)" maxlength="200"
                     title="Pozostaw puste, aby użyć oryginalnej nazwy pliku. Rozszerzenie zostanie zachowane.">
              <?php if($grupy): ?>
              <select name="grupa_id" class="form-select form-select-sm" style="max-width:200px">
                <option value="0">— bez grupy —</option>
                <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
              </select>
              <?php endif; ?>
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Dodaj do koszulki</button>
              <small class="text-muted">Maks. 25 MB</small>
            </div>
            <div class="form-check mt-2 d-none" id="ezd-upload-convert-wrap">
              <input type="checkbox" class="form-check-input" name="convert_pdf" value="1" id="ezd-upload-convert">
              <label class="form-check-label" for="ezd-upload-convert" style="font-size:.78rem">
                Przekonwertować też ten plik na PDF? (utworzy dodatkową kopię PDF obok oryginału)
              </label>
            </div>
          </form>
          <script>
          (function(){
            var input = document.getElementById('ezd-upload-file');
            var wrap  = document.getElementById('ezd-upload-convert-wrap');
            var check = document.getElementById('ezd-upload-convert');
            if (!input || !wrap || !check) return;
            var CONVERTIBLE = ['doc','docx','xls','xlsx'];
            input.addEventListener('change', function(){
              var name = input.value || '';
              var ext  = name.split('.').pop().toLowerCase();
              if (CONVERTIBLE.indexOf(ext) !== -1) {
                wrap.classList.remove('d-none');
              } else {
                wrap.classList.add('d-none');
                check.checked = false;
              }
            });
          })();
          </script>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php include dirname(dirname(__DIR__)) . '/includes/ezd_sig_modal.php'; ?>
    <?php include dirname(dirname(__DIR__)) . '/includes/ezd_email_modal.php'; ?>

    <!-- Modal: przenieś plik do innej grupy -->
    <div class="modal fade" id="zalMoveGroupModal" tabindex="-1" aria-labelledby="zalMoveGroupModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="zal_move">
            <input type="hidden" name="zal_id" id="zmg-zal-id" value="">
            <div class="modal-header py-2">
              <h2 class="modal-title h6 mb-0" id="zalMoveGroupModalLabel"><i class="bi bi-folder-symlink text-primary me-2" aria-hidden="true"></i>Przenieś do grupy</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
              <p class="mb-2" style="font-size:.85rem">Plik <strong id="zmg-name" class="font-monospace"></strong></p>
              <label class="form-label fw-semibold" for="zmg-grupa-id">Grupa plików</label>
              <select name="grupa_id" id="zmg-grupa-id" class="form-select"><?= $grupaOptsPlain ?></select>
            </div>
            <div class="modal-footer py-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Przenieś</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var idInput   = document.getElementById('zmg-zal-id');
      var nameLabel = document.getElementById('zmg-name');
      var grupaSel  = document.getElementById('zmg-grupa-id');
      document.addEventListener('click', function(e){
        var btn = e.target.closest('.ezd-move-grupa-btn');
        if (!btn) return;
        idInput.value = btn.dataset.zal || '';
        nameLabel.textContent = btn.dataset.name || '';
        grupaSel.value = btn.dataset.grupa || '0';
      });
    })();
    </script>

    <!-- Audit log -->
    <div class="mt-4">
      <h6 class="text-muted text-uppercase fw-bold mb-2" style="font-size:.7rem;letter-spacing:.1em">
        <i class="bi bi-shield-check me-1"></i>Historia operacji
      </h6>
      <div class="card shadow-sm">
        <div class="card-body p-0" style="max-height:280px;overflow-y:auto">
          <table class="table table-sm mb-0" style="font-size:.76rem">
            <thead class="table-light sticky-top"><tr><th>Czas</th><th>Użytkownik</th><th>Operacja</th><th>Szczegóły</th></tr></thead>
            <tbody>
            <?php foreach ($log_entries as $l): ?>
            <tr>
              <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
              <td><?= h($l['user_name'] ?? '—') ?></td>
              <td><code style="font-size:.68rem"><?= h($l['action']) ?></code></td>
              <td><?= h(mb_substr($l['details'],0,80)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$log_entries): ?><tr><td colspan="4" class="text-center text-muted py-2">Brak wpisów</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- ══ PRAWA: Bento ════════════════════════════════════════════════════════ -->
  <div class="col-lg-4">

    <!-- Metadane sprawy -->
    <div class="bc">
      <div class="bc-h"><i class="bi bi-info-circle"></i>Informacje o koszulce</div>
      <div class="bc-b">
        <dl class="row mb-0" style="font-size:.82rem;row-gap:.3rem">
          <dt class="col-5 text-muted fw-normal">Segregator</dt>
          <dd class="col-7 mb-0"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="text-decoration-none fw-semibold"><?= h($sprawa['teczka_symbol']) ?></a></dd>
          <dt class="col-5 text-muted fw-normal">Właściciel</dt>
          <dd class="col-7 mb-0"><?= $sprawa['owner_name'] ? h($sprawa['owner_name']) : '<span class="text-muted">—</span>' ?></dd>
          <dt class="col-5 text-muted fw-normal">Priorytet</dt>
          <dd class="col-7 mb-0"><?= ezd_priority_badge($sprawa['priority']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Status</dt>
          <dd class="col-7 mb-0"><?= ezd_status_badge_sprawa($sprawa['status']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Etap obiegu</dt>
          <dd class="col-7 mb-0"><?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?></dd>
          <dt class="col-5 text-muted fw-normal">Termin</dt>
          <dd class="col-7 mb-0 <?= !empty($sprawa['ciagla'])?'text-info':($sprawa['deadline']&&$sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'') ?>">
            <?= !empty($sprawa['ciagla']) ? '<i class="bi bi-infinity me-1"></i>stale otwarta' : ($sprawa['deadline'] ? date_pl($sprawa['deadline']) : '—') ?>
          </dd>
          <dt class="col-5 text-muted fw-normal">Otwarto</dt>
          <dd class="col-7 mb-0"><?= date_pl($sprawa['created_at']) ?></dd>
          <?php if($sprawa['closed_at']): ?>
          <dt class="col-5 text-muted fw-normal">Zamknięto</dt>
          <dd class="col-7 mb-0"><?= date_pl($sprawa['closed_at']) ?></dd>
          <?php endif; ?>
          <dt class="col-5 text-muted fw-normal">Pisma</dt>
          <dd class="col-7 mb-0"><?= count(array_filter($timeline, fn($t)=>$t['_typ']==='pismo')) ?></dd>
        </dl>
      </div>
    </div>

    <!-- Szybkie akcje -->
    <?php if($can_edit_case): ?>
    <div class="bc">
      <div class="bc-h"><i class="bi bi-lightning-charge"></i>Akcje</div>
      <div class="bc-b d-flex flex-column gap-2">
        <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary w-100 text-start">
          <i class="bi bi-pencil me-2"></i>Edytuj metadane koszulki
        </a>
        <?php if(!$sprawa['parent_id']): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/add.php?parent_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary w-100 text-start">
          <i class="bi bi-diagram-3 me-2"></i>Dodaj podkoszulkę
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Przekaż osobie -->
    <div class="bc" id="dekretacje">
      <div class="bc-h">
        <i class="bi bi-person-lines-fill"></i>Przekaż osobie
        <?php $pend_d = count(array_filter($dekretacje,fn($d)=>$d['status']==='oczekuje')); ?>
        <?php if($pend_d): ?><span class="ms-auto badge bg-warning text-dark" style="font-size:.63rem"><?= $pend_d ?> oczekuje</span><?php endif; ?>
      </div>
      <div class="bc-b">
        <?php foreach($dekretacje as $d): ?>
        <div class="dekr-row">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-1 flex-wrap">
              <span class="badge bg-<?= $d['status']==='oczekuje'?'warning text-dark':'success' ?>" style="font-size:.62rem"><?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?></span>
              <span class="fw-semibold"><?= h($d['wykonawca_name']??'—') ?></span>
            </div>
            <?php if($d['tresc']): ?><div class="text-muted" style="font-size:.72rem"><?= h(mb_substr($d['tresc'],0,60)) ?></div><?php endif; ?>
            <div class="text-muted" style="font-size:.7rem">
              od: <?= h($d['zlecajacy_name']??'—') ?>
              <?php if($d['deadline']): ?> · <span class="<?= $d['deadline']<date('Y-m-d')&&$d['status']==='oczekuje'?'text-danger fw-bold':'' ?>"><?= date_pl($d['deadline']) ?></span><?php endif; ?>
            </div>
          </div>
          <?php if($d['status']==='oczekuje' && $can_act): ?>
          <form method="post" class="d-inline flex-shrink-0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="dekr_done">
            <input type="hidden" name="dekr_id" value="<?= $d['id'] ?>">
            <button class="btn btn-xs btn-outline-success btn-sm" title="Wykonane"><i class="bi bi-check-lg"></i></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$dekretacje): ?><div class="text-muted text-center" style="font-size:.78rem">Brak przekazań</div><?php endif; ?>
      </div>
    </div>

  </div><!-- /col-lg-4 -->
</div>

<!-- ── Modal: nowy plik Word/Excel ────────────────────────────────────────── -->
<?php if($can_act): ?>
<div class="modal fade" id="newOfficeFileModal" tabindex="-1" aria-labelledby="newOfficeFileModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="new_office_file">
        <input type="hidden" name="filetype" id="nof-filetype" value="docx">
        <div class="modal-header">
          <h2 class="modal-title h5" id="newOfficeFileModalLabel"><i class="bi bi-file-earmark-plus text-success me-2" aria-hidden="true"></i>Nowy plik <span id="nof-type-label">Word</span></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="nof-name">Nazwa pliku <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="name" id="nof-name" class="form-control" placeholder="np. Notatka służbowa" maxlength="200" required>
              <span class="input-group-text" id="nof-ext">.docx</span>
            </div>
          </div>
          <?php if($grupy): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="nof-grupa">Grupa plików</label>
            <select name="grupa_id" id="nof-grupa" class="form-select">
              <option value="0">— bez grupy —</option>
              <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="with_znak" value="1" id="nof-znak" checked>
            <label class="form-check-label" for="nof-znak" style="font-size:.85rem">
              Dodaj znak sprawy w nagłówku (prawy górny róg) — <span class="font-monospace"><?= h($sprawa['znak_sprawy']) ?></span>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var typeInput = document.getElementById('nof-filetype');
  var typeLabel = document.getElementById('nof-type-label');
  var extLabel  = document.getElementById('nof-ext');
  var META = { docx: {label: 'Word', ext: '.docx'}, xlsx: {label: 'Excel', ext: '.xlsx'} };
  document.querySelectorAll('.ezd-new-office-file').forEach(function(a){
    a.addEventListener('click', function(){
      var t = a.getAttribute('data-type');
      var m = META[t] || META.docx;
      typeInput.value = t;
      typeLabel.textContent = m.label;
      extLabel.textContent = m.ext;
    });
  });
})();
</script>
<?php endif; ?>

<!-- ── Modal: przekaż dokumenty ───────────────────────────────────────────── -->
<?php if($can_act && $zalaczniki): ?>
<div class="modal fade" id="przekazDokModal" tabindex="-1" aria-labelledby="przekazDokModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="zal_przekaz">
        <div class="modal-header">
          <h2 class="modal-title h5" id="przekazDokModalLabel"><i class="bi bi-send-check text-warning me-2" aria-hidden="true"></i>Przekaż dokumenty</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Wybierz dokumenty</label>
            <div class="border rounded-3 p-2" style="max-height:260px;overflow-y:auto">
              <?php foreach($zalaczniki as $z): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="zal_ids[]" value="<?= (int)$z['id'] ?>" id="pz-zal-<?= (int)$z['id'] ?>">
                <label class="form-check-label d-flex align-items-center gap-2" for="pz-zal-<?= (int)$z['id'] ?>" style="font-size:.85rem">
                  <i class="bi <?= ezd_file_icon($z['original_name']) ?>"></i><?= h($z['original_name']) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="pz-user">Komu przekazujesz <span class="text-danger">*</span></label>
            <select name="przekaz_user_id" id="pz-user" class="form-select" required>
              <option value="">— wybierz osobę —</option>
              <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Osoba otrzyma dostęp do wybranych plików, niezależnie od dostępu do całej koszulki.</div>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold" for="pz-note">Wiadomość <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <input type="text" name="przekaz_note" id="pz-note" class="form-control" placeholder="np. proszę o sprawdzenie faktury">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-send-check me-1" aria-hidden="true"></i>Przekaż</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: notatki ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="notatkiModal" tabindex="-1" aria-labelledby="notatkiModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="notatkiModalLabel"><i class="bi bi-sticky text-secondary me-2" aria-hidden="true"></i>Notatki (<?= count($notatki) ?>)</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php if($can_act): ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="note_add">
          <div class="d-flex gap-2 align-items-start">
            <textarea name="tresc" class="form-control form-control-sm" rows="2" placeholder="Dodaj notatkę do koszulki…" required></textarea>
            <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-plus-lg me-1"></i>Dodaj</button>
          </div>
        </form>
        <?php endif; ?>
        <?php foreach($notatki as $n): $own = ($n['created_by']==$user_id || is_admin()); ?>
        <div class="border rounded-3 p-2 mb-2 <?= $n['pinned'] ? 'border-warning bg-warning bg-opacity-10' : '' ?>" style="font-size:.83rem">
          <div class="d-flex align-items-center gap-2 mb-1 text-muted" style="font-size:.7rem">
            <?php if($n['pinned']): ?><i class="bi bi-pin-angle-fill text-warning"></i><?php endif; ?>
            <span class="fw-semibold text-dark"><?= h($n['author'] ?? '—') ?></span>
            <span><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
            <?php if($n['updated_at'] && $n['updated_at'] !== $n['created_at']): ?><span class="fst-italic">(edytowano)</span><?php endif; ?>
            <?php if($can_act): ?>
            <div class="ms-auto d-flex gap-1">
              <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_pin"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-muted" title="<?= $n['pinned']?'Odepnij':'Przypnij' ?>"><i class="bi bi-pin-angle<?= $n['pinned']?'-fill text-warning':'' ?>"></i></button>
              </form>
              <?php if($own): ?>
              <button class="btn btn-xs btn-link p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#note-edit-<?= $n['id'] ?>" title="Edytuj"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć notatkę?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_del"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-danger" title="Usuń"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="white-space:pre-wrap"><?= h($n['tresc']) ?></div>
          <?php if($own && $can_act): ?>
          <div class="collapse mt-2" id="note-edit-<?= $n['id'] ?>">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_edit"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
              <textarea name="tresc" class="form-control form-control-sm mb-2" rows="2" required><?= h($n['tresc']) ?></textarea>
              <button class="btn btn-xs btn-primary btn-sm">Zapisz</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$notatki): ?>
        <div class="text-center text-muted py-2" style="font-size:.8rem">Brak notatek</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.location.hash === '#notatki') {
    var m = document.getElementById('notatkiModal');
    if (m && window.bootstrap) {
      new bootstrap.Modal(m).show();
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  }
});
</script>

<!-- ── Modal: nowe pismo ──────────────────────────────────────────────────── -->
<?php if($can_act): ?>
<div class="modal fade" id="pismoModal" tabindex="-1" aria-labelledby="pismoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="pismo_add">
        <div class="modal-header">
          <h2 class="modal-title h5" id="pismoModalLabel"><i class="bi bi-envelope-plus text-info me-2" aria-hidden="true"></i>Nowe pismo</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Kierunek <span class="text-danger">*</span></label>
            <div class="d-flex gap-2 flex-wrap">
              <?php foreach(EZD_KIERUNKI as $kv=>$kl): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="kierunek" id="pm_k_<?= $kv ?>" value="<?= $kv ?>" <?= $kv==='przychodzace'?'checked':'' ?>>
                <label class="form-check-label" for="pm_k_<?= $kv ?>"><i class="bi <?= $kl['icon'] ?> me-1"></i><?= h($kl['label']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="pm-title">Tytuł / przedmiot pisma <span class="text-danger">*</span></label>
            <input type="text" name="title" id="pm-title" class="form-control" placeholder="np. Oferta cenowa nr 123/2026" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-medium">Rodzaj medium</label>
              <select name="rodzaj_medium" id="pm-medium" class="form-select">
                <?php foreach(EZD_MEDIA as $mv=>$ml): ?>
                <option value="<?= $mv ?>" <?= $mv==='papier'?'selected':'' ?>><?= h($ml['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-owner">Referent</label>
              <select name="owner_id" id="pm-owner" class="form-select">
                <option value="">— brak —</option>
                <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-nadawca">Nadawca</label>
              <input type="text" name="nadawca" id="pm-nadawca" class="form-control" placeholder="Nazwa / firma nadawcy">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="pm-odbiorca">Odbiorca</label>
              <input type="text" name="odbiorca" id="pm-odbiorca" class="form-control" placeholder="Nazwa / firma odbiorcy">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-4">
              <label class="form-label fw-semibold" for="pm-data-pisma">Data pisma</label>
              <input type="date" name="data_pisma" id="pm-data-pisma" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold" for="pm-data-wplywu">Data wpływu</label>
              <input type="date" name="data_wplywu" id="pm-data-wplywu" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold" for="pm-data-wysylki">Data wysyłki</label>
              <input type="date" name="data_wysylki" id="pm-data-wysylki" class="form-control">
            </div>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold" for="pm-tresc">Treść / notatka <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <textarea name="tresc" id="pm-tresc" class="form-control" rows="3" placeholder="Streszczenie, dyspozycje, dodatkowe informacje…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-info"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Dodaj pismo</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: nowa dekretacja ─────────────────────────────────────────────── -->
<?php if($can_act):
  $org_units_list = [];
  if (module_enabled('org_enabled') && function_exists('org_units_all')) {
      $org_units_list = org_units_all('active');
  }
?>
<div class="modal fade" id="dekrModal" tabindex="-1" aria-labelledby="dekrModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="dekr-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="dekretacja">
        <div class="modal-header">
          <h2 class="modal-title h5" id="dekrModalLabel"><i class="bi bi-person-lines-fill text-warning me-2" aria-hidden="true"></i>Przekaż osobie</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <?php if ($org_units_list): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dekr-unit">Jednostka organizacyjna</label>
            <select name="unit_id" class="form-select" id="dekr-unit" onchange="dekrUnitChange(this)">
              <option value="">— lub wybierz jednostkę —</option>
              <?php foreach($org_units_list as $ou): ?>
              <option value="<?= $ou['id'] ?>"><?= h($ou['name']) ?> (<?= h($ou['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Wybór jednostki podpowie jej kierownika jako wykonawcę.</div>
          </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dekr-user">Wykonawca (osoba) <span class="text-danger">*</span></label>
            <select name="wykonawca_id" class="form-select" id="dekr-user">
              <option value="">— wybierz osobę —</option>
              <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dekr-dysp">Dyspozycja</label>
            <select name="dyspozycja" id="dekr-dysp" class="form-select">
              <?php foreach(EZD_DYSPOZYCJE as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dekr-tresc">Treść dyspozycji <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <input type="text" name="tresc" id="dekr-tresc" class="form-control" placeholder="np. proszę o przygotowanie odpowiedzi">
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold" for="dekr-deadline">Termin</label>
            <input type="date" name="deadline" id="dekr-deadline" class="form-control" min="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-send me-1" aria-hidden="true"></i>Przekaż</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($org_units_list): ?>
<script>
// Wczytaj kierownika jednostki przy wyborze z listy
var dekrHeads = <?= json_encode(array_reduce($org_units_list, function($carry, $u) {
    $head = function_exists('org_unit_head') ? org_unit_head((int)$u['id']) : null;
    if ($head) $carry[(string)$u['id']] = (int)$head['user_id'];
    return $carry;
}, [])) ?>;
function dekrUnitChange(sel) {
    var uid = sel.value;
    var userSel = document.getElementById('dekr-user');
    if (uid && dekrHeads[uid]) userSel.value = dekrHeads[uid];
}
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ── Modal: współdzielenie koszulki ─────────────────────────────────────── -->
<?php if($can_manage_share || $shares): ?>
<div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="shareModalLabel"><i class="bi bi-people text-primary me-2" aria-hidden="true"></i>Współdzielenie koszulki</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Osoby, którym udostępniono tę koszulkę poza właścicielem i rolami z dostępem do modułu.</p>

        <?php if($shares): ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach($shares as $sh): ?>
          <li class="list-group-item d-flex align-items-center gap-2 px-0">
            <i class="bi bi-person-circle text-muted"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold" style="font-size:.85rem"><?= h($sh['user_name']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= h(EZD_SPRAWA_UPRAWNIENIA[$sh['uprawnienie']] ?? $sh['uprawnienie']) ?></div>
            </div>
            <?php if($can_manage_share): ?>
            <form method="post" onsubmit="return confirm('Odebrać dostęp?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="share_del">
              <input type="hidden" name="share_user_id" value="<?= (int)$sh['user_id'] ?>">
              <button class="btn btn-sm btn-link p-0 text-danger" title="Odbierz dostęp"><i class="bi bi-x-circle"></i></button>
            </form>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="text-muted text-center py-3" style="font-size:.82rem">Koszulka nie jest współdzielona z dodatkowymi osobami.</div>
        <?php endif; ?>

        <?php if($can_manage_share): ?>
        <form method="post" class="d-flex flex-column gap-2 pt-3 border-top">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="share_add">
          <label class="form-label fw-semibold mb-0" style="font-size:.8rem">Udostępnij nowej osobie</label>
          <select name="share_user_id" class="form-select form-select-sm" required>
            <option value="">— wybierz osobę —</option>
            <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
          </select>
          <select name="share_uprawnienie" class="form-select form-select-sm">
            <?php foreach(EZD_SPRAWA_UPRAWNIENIA as $uv=>$ul): ?><option value="<?= $uv ?>"><?= h($ul) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Udostępnij</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  // Po dodaniu/odebraniu współdzielenia (redirect ?share=1) otwórz modal ponownie.
  if (new URLSearchParams(location.search).get('share') === '1') {
    var el = document.getElementById('shareModal');
    if (el && window.bootstrap) { try { new bootstrap.Modal(el).show(); } catch(e){} }
  }
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
