<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
if (file_exists(dirname(__DIR__) . '/includes/notifications.php')) {
    require_once dirname(__DIR__) . '/includes/notifications.php';
}

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$PAGE_TITLE = 'Wirtualne biurko';
$stats      = ezd_stats();
$rpw_stats  = ezd_rpw_stats();
$arch_stats = ezd_arch_stats();
$user_id    = (int)current_user()['id'];
$user       = current_user();

// Ostatnie koszulki
$recent_sprawy = ezd_sprawy_all(['status' => ''], $user_id);
$recent_sprawy = array_slice(array_filter($recent_sprawy, fn($s) => $s['status'] !== 'closed'), 0, 8);

// Moje przekazania (oczekujące)
$my_dekr = db_all(
    "SELECT d.*, s.znak_sprawy, s.title AS sprawa_title, z.name AS zlec_name
     FROM ezd_dekretacje d
     JOIN ezd_sprawy s ON s.id=d.sprawa_id
     LEFT JOIN users z ON z.id=d.zlecajacy_id
     WHERE d.wykonawca_id=? AND d.status='oczekuje'
     ORDER BY d.deadline ASC, d.created_at DESC LIMIT 10",
    [$user_id]
);

// Moje koszulki
$my_sprawy = db_all(
    "SELECT s.*, t.symbol AS teczka_symbol,
            (s.owner_id!=? AND s.created_by!=?) AS is_shared
     FROM ezd_sprawy s
     JOIN ezd_teczki t ON t.id=s.teczka_id
     WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
           OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))
     ORDER BY s.deadline ASC LIMIT 8",
    [$user_id, $user_id, $user_id, $user_id, $user_id]
);

// Moje pisma
$my_pisma = db_all(
    "SELECT p.*, s.znak_sprawy FROM ezd_pisma p
     JOIN ezd_sprawy s ON s.id=p.sprawa_id
     WHERE p.owner_id=? ORDER BY p.updated_at DESC LIMIT 8",
    [$user_id]
);

// Nowe pisma w jednostce (wszystkie przychodzące ≤ 14 dni LUB status=nowe)
$nowe_pisma_jedn = [];
try {
    $nowe_pisma_jedn = db_all(
        "SELECT p.*, s.znak_sprawy, s.title AS sprawa_title
         FROM ezd_pisma p
         JOIN ezd_sprawy s ON s.id=p.sprawa_id
         WHERE p.kierunek='przychodzace'
           AND (p.status='nowe' OR p.created_at >= date('now','-14 days'))
         ORDER BY p.created_at DESC LIMIT 12"
    );
} catch (\Throwable $e) { $nowe_pisma_jedn = []; }
$nowe_pisma_jedn_cnt = count($nowe_pisma_jedn);

$my_sprawy_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
     OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_pisma_cnt = (int)(db_one("SELECT COUNT(*) c FROM ezd_pisma WHERE owner_id=?", [$user_id])['c'] ?? 0);

// Ostatnia aktywność
$activity = db_all(
    "SELECT l.*, u.name AS user_name, s.znak_sprawy
     FROM ezd_log l
     LEFT JOIN users u ON u.id=l.user_id
     LEFT JOIN ezd_sprawy s ON s.id=l.sprawa_id
     ORDER BY l.created_at DESC LIMIT 10"
);

// Komu przekazana
$dekr_map = [];
try {
    $open_dekr = db_all(
        "SELECT d.sprawa_id, d.wykonawca_id, u.name AS wykonawca_name
         FROM ezd_dekretacje d JOIN users u ON u.id=d.wykonawca_id
         WHERE d.status='oczekuje' AND d.sprawa_id IS NOT NULL
         ORDER BY d.created_at ASC"
    );
    $workload = [];
    foreach ($open_dekr as $d) $workload[(int)$d['wykonawca_id']][(int)$d['sprawa_id']] = true;
    foreach ($open_dekr as $d) {
        $dekr_map[(int)$d['sprawa_id']] = [
            'name'  => $d['wykonawca_name'],
            'count' => count($workload[(int)$d['wykonawca_id']]),
        ];
    }
} catch (\Throwable $e) {}

// Donut: DO OBSŁUGI breakdown
$dekr_total   = count($my_dekr);
$dekr_po_term = count(array_filter($my_dekr, fn($d) => !empty($d['deadline']) && $d['deadline'] < date('Y-m-d')));
$dekr_dzis    = count(array_filter($my_dekr, fn($d) => !empty($d['deadline']) && $d['deadline'] === date('Y-m-d')));
$dekr_inne    = max(0, $dekr_total - $dekr_po_term - $dekr_dzis);

// Donut: KOSZULKI breakdown
$my_sprawy_po_term_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND s.deadline IS NOT NULL AND s.deadline < date('now') AND COALESCE(s.ciagla,0)=0 AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_sprawy_ciagle_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND COALESCE(s.ciagla,0)=1 AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_sprawy_w_toku_cnt = max(0, $my_sprawy_cnt - $my_sprawy_po_term_cnt - $my_sprawy_ciagle_cnt);

// Komunikaty (ważne)
$komunikaty = [];
if (function_exists('ann_list_for_user')) {
    try { $komunikaty = array_slice(ann_list_for_user($user_id, $user['role'] ?? '', ''), 0, 5); } catch(\Throwable $e) {}
}

// Zastępstwa
$cur_name = $user['name'] ?? '';
$zastepuje = []; $zastepuje_mnie = [];
try {
    if ($cur_name) {
        $zastepuje      = db_all("SELECT * FROM ezd_pelnomocnictwa WHERE mocodawca=? AND is_active=1 ORDER BY created_at DESC LIMIT 5", [$cur_name]);
        $zastepuje_mnie = db_all("SELECT * FROM ezd_pelnomocnictwa WHERE pelnomocnik=? AND is_active=1 ORDER BY created_at DESC LIMIT 5", [$cur_name]);
    }
} catch(\Throwable $e) {}

// Przydatne linki
$przydatne_linki = json_decode(org_setting('ezd_przydatne_linki') ?: '[]', true) ?: [];

// eDoręczenia
$ede_ade   = org_setting('ezd_ede_ade')   ?: 'AE:PL-70366-85524-UJBAB-23';
$ede_email = org_setting('ezd_ede_email') ?: 'fundacja@feer.org.pl';
$ede_phone = org_setting('ezd_ede_phone') ?: '601 350 487';

/**
 * Renderuje SVG donut chart. Segmenty: [{val, color}, ...], $total = suma.
 * Kąt startowy: góra (SVG obrócony -90°).
 * dashoffset ujemny przesuwa dash do przodu wzdłuż ścieżki.
 */
function ezd_donut_svg(array $segments, int $total): string {
    $r    = 38;
    $circ = 2 * M_PI * $r;
    $svg  = '<svg viewBox="0 0 100 100" style="transform:rotate(-90deg);display:block;width:100%;height:100%">';
    $svg .= '<circle cx="50" cy="50" r="'.$r.'" fill="none" stroke="#e9ecef" stroke-width="9"/>';
    $cum  = 0;
    foreach ($segments as $seg) {
        if ($seg['val'] <= 0 || !$total) { continue; }
        $arc  = ($seg['val'] / $total) * $circ;
        $svg .= '<circle cx="50" cy="50" r="'.$r.'" fill="none" stroke="'.h($seg['color']).'" stroke-width="9"'
              . ' stroke-dasharray="'.round($arc, 2).' '.round($circ, 2).'"'
              . ' stroke-dashoffset="'.round(-$cum, 2).'"/>';
        $cum += $arc;
    }
    return $svg . '</svg>';
}

include dirname(__DIR__) . '/includes/header.php';
?>

<!-- Modal: eDoręczenia -->
<div class="modal fade" id="ezdEDoreczeniaModal" tabindex="-1" aria-labelledby="ezdEDoreczeniaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="ezdEDoreczeniaModalLabel"><i class="bi bi-envelope-paper text-primary me-2" aria-hidden="true"></i>eDoręczenia</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="list-group mb-3">
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Adres ADE</div>
              <div class="fw-semibold font-monospace"><?= h($ede_ade) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_ade) ?>" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
          </div>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">E-mail</div>
              <div class="fw-semibold"><?= h($ede_email) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_email) ?>" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
          </div>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Telefon</div>
              <div class="fw-semibold"><?= h($ede_phone) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_phone) ?>" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary btn-sm" id="edeHintBtn"
                  data-ede-all="Adres do doręczeń (ADE): <?= h($ede_ade) ?>&#10;E-mail: <?= h($ede_email) ?>&#10;Telefon: <?= h($ede_phone) ?>">
            <i class="bi bi-magic me-1"></i>Podpowiedz dane
          </button>
          <a href="https://app.edopost.pl/login" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i>Zaloguj do eDoPost
          </a>
          <span id="edeCopyStatus" class="align-self-center text-success small d-none"><i class="bi bi-check-circle me-1"></i>Skopiowano</span>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
/* ── Dashboard cards ─────────────────────────────────────── */
.ezdd-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.ezdd-label{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:.75rem}

/* ── Donut widget ────────────────────────────────────────── */
.ezdd-donut-wrap{position:relative;width:140px;height:140px;flex-shrink:0}
.ezdd-donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.ezdd-donut-num{font-size:2rem;font-weight:800;line-height:1;color:#0f172a}
.ezdd-donut-sub{font-size:.62rem;color:#94a3b8;text-align:center;line-height:1.2}
.ezdd-leg{display:flex;flex-direction:column;gap:.3rem;flex:1;min-width:0}
.ezdd-leg-row{display:flex;align-items:center;gap:.45rem;font-size:.75rem}
.ezdd-leg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.ezdd-leg-num{font-weight:700;min-width:1.4rem;text-align:right}
.ezdd-leg-lbl{color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── Quick action buttons ────────────────────────────────── */
.ezdd-qa{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.35rem;padding:.8rem .4rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;text-decoration:none;color:#374151;font-size:.72rem;font-weight:600;text-align:center;transition:background .15s,border-color .15s}
.ezdd-qa:hover{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.ezdd-qa i{font-size:1.3rem;color:#2563eb}

/* ── Calendar ────────────────────────────────────────────── */
.ezdd-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
.ezdd-cal-nav button{background:none;border:1px solid #e2e8f0;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:.85rem}
.ezdd-cal-nav button:hover{background:#f1f5f9}
.ezdd-cal-month{font-weight:700;font-size:.85rem;color:#1e293b}
.ezdd-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.ezdd-cal-dow{text-align:center;font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;padding:.2rem 0}
.ezdd-cal-day{text-align:center;font-size:.75rem;padding:.3rem .1rem;border-radius:6px;color:#374151;cursor:default}
.ezdd-cal-today{background:#2563eb;color:#fff!important;font-weight:700;border-radius:50%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;margin:auto}

/* ── Right column ────────────────────────────────────────── */
.ezdd-ann{border-left:3px solid #2563eb;padding:.55rem .8rem;background:#f8fafc;border-radius:0 8px 8px 0;font-size:.8rem;margin-bottom:.5rem}
.ezdd-ann.urgent{border-color:#dc2626;background:#fff5f5}
.ezdd-ann-title{font-weight:600;color:#1e293b;margin-bottom:.1rem}
.ezdd-ann-meta{font-size:.67rem;color:#94a3b8}
.ezdd-link-row{display:flex;align-items:center;gap:.5rem;padding:.4rem 0;font-size:.8rem;border-bottom:1px solid #f1f5f9}
.ezdd-link-row:last-child{border-bottom:none}
.ezdd-link-row a{color:#2563eb;text-decoration:none;font-weight:500}
.ezdd-link-row a:hover{text-decoration:underline}

/* ── Content rows (koszulki, aktywność) ──────────────────── */
.ezdd-row-link{cursor:pointer}
.ezdd-row-link:hover{background:#f8fafc}
</style>

<?= flash_html() ?>

<!-- Wyszukiwarka -->
<form method="get" action="<?= APP_URL ?>/ezd/szukaj.php" class="mb-4" role="search">
  <div class="input-group">
    <span class="input-group-text bg-white"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
    <input type="search" name="q" class="form-control" required minlength="2" autocomplete="off"
           placeholder="Szukaj po znaku koszulki, numerze dokumentu lub tytule…">
    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Szukaj</button>
  </div>
</form>

<!-- ═══ 3-kolumnowy grid ═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

  <!-- ──── Lewa: wykresy ──────────────────────────────────────────────────── -->
  <div class="col-md-5 col-lg-4 col-xl-3">

    <!-- DO OBSŁUGI -->
    <div class="ezdd-card p-3 mb-3">
      <div class="ezdd-label">DO OBSŁUGI</div>
      <div class="d-flex align-items-center gap-3">
        <div class="ezdd-donut-wrap">
          <?= ezd_donut_svg([
            ['val' => $dekr_po_term, 'color' => '#dc2626'],
            ['val' => $dekr_dzis,    'color' => '#f59e0b'],
            ['val' => $dekr_inne,    'color' => '#2563eb'],
          ], max($dekr_total, 1)) ?>
          <div class="ezdd-donut-center">
            <div class="ezdd-donut-num"><?= $dekr_total ?></div>
            <div class="ezdd-donut-sub">zadań</div>
          </div>
        </div>
        <div class="ezdd-leg">
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#dc2626"></span>
            <span class="ezdd-leg-num text-danger"><?= $dekr_po_term ?></span>
            <span class="ezdd-leg-lbl">po terminie</span>
          </div>
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#f59e0b"></span>
            <span class="ezdd-leg-num"><?= $dekr_dzis ?></span>
            <span class="ezdd-leg-lbl">na dziś</span>
          </div>
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#2563eb"></span>
            <span class="ezdd-leg-num"><?= $dekr_inne ?></span>
            <span class="ezdd-leg-lbl">pozostałe</span>
          </div>
          <?php if ($rpw_stats['koszulka']): ?>
          <div class="ezdd-leg-row mt-1 pt-1" style="border-top:1px solid #f1f5f9">
            <span class="ezdd-leg-dot" style="background:#06b6d4"></span>
            <span class="ezdd-leg-num"><?= (int)$rpw_stats['koszulka'] ?></span>
            <span class="ezdd-leg-lbl">RPW</span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="mt-2 pt-2" style="border-top:1px solid #f1f5f9">
        <a href="<?= APP_URL ?>/ezd/dekretacja/index.php" class="text-decoration-none text-primary" style="font-size:.74rem;font-weight:600">
          Pokaż wszystkie zadania →
        </a>
      </div>
    </div>

    <!-- MOJE KOSZULKI -->
    <div class="ezdd-card p-3">
      <div class="ezdd-label">MOJE KOSZULKI</div>
      <div class="d-flex align-items-center gap-3">
        <div class="ezdd-donut-wrap">
          <?= ezd_donut_svg([
            ['val' => $my_sprawy_po_term_cnt, 'color' => '#dc2626'],
            ['val' => $my_sprawy_ciagle_cnt,  'color' => '#06b6d4'],
            ['val' => $my_sprawy_w_toku_cnt,  'color' => '#2563eb'],
          ], max($my_sprawy_cnt, 1)) ?>
          <div class="ezdd-donut-center">
            <div class="ezdd-donut-num"><?= $my_sprawy_cnt ?></div>
            <div class="ezdd-donut-sub">koszulek</div>
          </div>
        </div>
        <div class="ezdd-leg">
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#dc2626"></span>
            <span class="ezdd-leg-num text-danger"><?= $my_sprawy_po_term_cnt ?></span>
            <span class="ezdd-leg-lbl">po terminie</span>
          </div>
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#06b6d4"></span>
            <span class="ezdd-leg-num"><?= $my_sprawy_ciagle_cnt ?></span>
            <span class="ezdd-leg-lbl">ciągłe</span>
          </div>
          <div class="ezdd-leg-row">
            <span class="ezdd-leg-dot" style="background:#2563eb"></span>
            <span class="ezdd-leg-num"><?= $my_sprawy_w_toku_cnt ?></span>
            <span class="ezdd-leg-lbl">w toku</span>
          </div>
          <div class="ezdd-leg-row mt-1 pt-1" style="border-top:1px solid #f1f5f9">
            <span class="ezdd-leg-dot" style="background:#94a3b8"></span>
            <span class="ezdd-leg-num"><?= $my_pisma_cnt ?></span>
            <span class="ezdd-leg-lbl">pisma</span>
          </div>
        </div>
      </div>
      <div class="mt-2 pt-2" style="border-top:1px solid #f1f5f9">
        <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="text-decoration-none text-primary" style="font-size:.74rem;font-weight:600">
          Pokaż wszystkie koszulki →
        </a>
      </div>
    </div>

  </div><!-- /lewa -->

  <!-- ──── Środkowa: akcje + kalendarz ────────────────────────────────────── -->
  <div class="col-md-7 col-lg-5 col-xl-5">

    <!-- Szybkie akcje -->
    <div class="ezdd-card p-3 mb-3">
      <div class="ezdd-label">SZYBKIE AKCJE</div>
      <div class="row g-2">
        <div class="col-6">
          <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="ezdd-qa">
            <i class="bi bi-folder-plus"></i>Załóż koszulkę
          </a>
        </div>
        <div class="col-6">
          <a href="<?= APP_URL ?>/ezd/pisma/add.php" class="ezdd-qa">
            <i class="bi bi-envelope-plus"></i>Nowe pismo
          </a>
        </div>
        <div class="col-6">
          <a href="<?= APP_URL ?>/ezd/rpw/index.php" class="ezdd-qa">
            <i class="bi bi-inbox-fill"></i>RPW <span class="badge bg-danger ms-1" style="font-size:.6rem"><?= (int)$rpw_stats['koszulka'] ?></span>
          </a>
        </div>
        <div class="col-6">
          <button type="button" class="ezdd-qa w-100" data-bs-toggle="modal" data-bs-target="#ezdEDoreczeniaModal">
            <i class="bi bi-envelope-paper"></i>eDoręczenia
          </button>
        </div>
      </div>
    </div>

    <!-- Statystyki skrócone -->
    <div class="row g-2 mb-3">
      <?php $stat_items = [
        ['v'=>$stats['teczki_open'],  'l'=>'Segregatory', 'i'=>'bi-archive-fill',       'c'=>'primary'],
        ['v'=>$stats['pisma_month'],  'l'=>'Pisma/mies.', 'i'=>'bi-envelope-arrow-down', 'c'=>'info'],
        ['v'=>$arch_stats['spisy']??0,'l'=>'Spisy zd.',   'i'=>'bi-box-seam',            'c'=>'secondary'],
        ['v'=>$rpw_stats['rpw_dzis'],'l'=>'RPW dziś',    'i'=>'bi-inbox',               'c'=>'warning'],
      ]; ?>
      <?php foreach($stat_items as $it): ?>
      <div class="col-6">
        <div class="ezdd-card px-3 py-2 d-flex align-items-center gap-2">
          <i class="bi <?= $it['i'] ?> text-<?= $it['c'] ?>" style="font-size:1.2rem"></i>
          <div>
            <div style="font-size:1.2rem;font-weight:800;line-height:1;color:#0f172a"><?= $it['v'] ?></div>
            <div style="font-size:.65rem;color:#94a3b8"><?= $it['l'] ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Kalendarz -->
    <div class="ezdd-card p-3">
      <div class="ezdd-label">KALENDARZ</div>
      <div id="ezd-cal"></div>
    </div>

  </div><!-- /środkowa -->

  <!-- ──── Prawa: komunikaty + zastępstwa + linki ──────────────────────────── -->
  <div class="col-lg-3 col-xl-4">

    <!-- Ważne komunikaty -->
    <div class="ezdd-card p-3 mb-3">
      <div class="ezdd-label">WAŻNE KOMUNIKATY</div>
      <?php if ($komunikaty): ?>
        <?php foreach($komunikaty as $ann):
          $pilny = !empty($ann['is_pinned']); ?>
        <div class="ezdd-ann <?= $pilny ? 'urgent' : '' ?>">
          <div class="ezdd-ann-title"><?= h(mb_substr($ann['title'], 0, 60)) ?><?= mb_strlen($ann['title'])>60?'…':'' ?></div>
          <div class="ezdd-ann-meta">
            <?php if($pilny): ?><i class="bi bi-pin-angle-fill text-danger me-1"></i><?php endif; ?>
            <?= date('d.m.Y', strtotime($ann['created_at'])) ?>
            <?php if(!empty($ann['expires_at'])): ?> · do <?= date('d.m', strtotime($ann['expires_at'])) ?><?php endif; ?>
            <?php if(!$ann['is_read_by_me']): ?><span class="badge bg-danger ms-1" style="font-size:.55rem">NOWY</span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="text-muted text-center py-2" style="font-size:.78rem">Brak aktywnych komunikatów</div>
      <?php endif; ?>
    </div>

    <!-- Zastępstwa -->
    <?php if ($zastepuje || $zastepuje_mnie): ?>
    <div class="ezdd-card p-3 mb-3">
      <?php if ($zastepuje): ?>
      <div class="ezdd-label">TY ZASTĘPUJESZ</div>
      <?php foreach($zastepuje as $z): ?>
      <div style="font-size:.8rem;padding:.3rem 0;border-bottom:1px solid #f1f5f9">
        <i class="bi bi-person-fill-check text-success me-1"></i>
        <span class="fw-semibold"><?= h($z['mocodawca']) ?></span>
        <?php if(!empty($z['zakres'])): ?><div class="text-muted" style="font-size:.7rem"><?= h(mb_substr($z['zakres'],0,50)) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($zastepuje_mnie): ?>
      <div class="ezdd-label <?= $zastepuje ? 'mt-3' : '' ?>">CIEBIE ZASTĘPUJĄ</div>
      <?php foreach($zastepuje_mnie as $z): ?>
      <div style="font-size:.8rem;padding:.3rem 0;border-bottom:1px solid #f1f5f9">
        <i class="bi bi-person-fill text-info me-1"></i>
        <span class="fw-semibold"><?= h($z['pelnomocnik']) ?></span>
        <?php if(!empty($z['zakres'])): ?><div class="text-muted" style="font-size:.7rem"><?= h(mb_substr($z['zakres'],0,50)) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Przydatne linki -->
    <?php if ($przydatne_linki): ?>
    <div class="ezdd-card p-3 mb-3">
      <div class="ezdd-label">PRZYDATNE LINKI</div>
      <?php foreach($przydatne_linki as $lnk): ?>
      <div class="ezdd-link-row">
        <i class="bi bi-link-45deg text-muted flex-shrink-0"></i>
        <a href="<?= h($lnk['url'] ?? '#') ?>" target="_blank" rel="noopener"><?= h($lnk['label'] ?? $lnk['url'] ?? '—') ?></a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- eDoręczenia mini-karta -->
    <div class="ezdd-card p-3">
      <div class="ezdd-label">eDORECZENIA</div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-envelope-paper text-primary" style="font-size:1.1rem"></i>
        <code class="flex-grow-1 text-truncate" style="font-size:.74rem;color:#1e293b"><?= h($ede_ade) ?></code>
        <button type="button" class="btn btn-xs btn-outline-secondary btn-sm ede-copy-btn" data-ede-value="<?= h($ede_ade) ?>" title="Kopiuj ADE"><i class="bi bi-clipboard"></i></button>
      </div>
      <div class="text-muted" style="font-size:.72rem"><?= h($ede_email) ?> · <?= h($ede_phone) ?></div>
      <div class="mt-2">
        <button type="button" class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#ezdEDoreczeniaModal" style="font-size:.75rem">
          <i class="bi bi-info-circle me-1"></i>Szczegóły eDoręczeń
        </button>
      </div>
    </div>

  </div><!-- /prawa -->

</div><!-- /3-col grid -->

<!-- ═══ Treść: koszulki + aktywność ════════════════════════════════════════════ -->
<div class="row g-4">

  <!-- Koszulki + moje dokumenty -->
  <div class="col-lg-7">

    <!-- Tabs: Moje koszulki / Moje dokumenty -->
    <div class="ezdd-card mb-3">
      <ul class="nav nav-tabs px-2 pt-2" style="font-size:.8rem;border-bottom:1px solid #f1f5f9">
        <li class="nav-item">
          <a class="nav-link active py-1" data-bs-toggle="tab" href="#tab-moje-sprawy">
            <i class="bi bi-folder2-open me-1"></i>Moje koszulki
            <span class="badge bg-primary rounded-pill ms-1"><?= $my_sprawy_cnt ?></span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-1" data-bs-toggle="tab" href="#tab-moje-pisma">
            <i class="bi bi-envelope me-1"></i>Moje dokumenty
            <span class="badge bg-secondary rounded-pill ms-1"><?= $my_pisma_cnt ?></span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-1" data-bs-toggle="tab" href="#tab-nowe-pisma">
            <i class="bi bi-envelope-arrow-down me-1 text-info"></i>Nowe pisma
            <?php if ($nowe_pisma_jedn_cnt): ?>
            <span class="badge bg-info rounded-pill ms-1"><?= $nowe_pisma_jedn_cnt ?></span>
            <?php endif; ?>
          </a>
        </li>
      </ul>
      <div class="tab-content p-0">
        <div class="tab-pane fade show active" id="tab-moje-sprawy">
          <?php if ($my_sprawy): foreach ($my_sprawy as $s): ?>
          <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom ezdd-row-link" style="font-size:.8rem"
               onclick="location='<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>'">
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold text-truncate">
                <?= h($s['title']) ?>
                <?php if(!empty($s['is_shared'])): ?>
                <span class="badge bg-info bg-opacity-15 text-info border border-info ms-1" style="font-size:.58rem"><i class="bi bi-people me-1"></i>Wsp.</span>
                <?php endif; ?>
              </div>
              <div class="font-monospace text-muted" style="font-size:.68rem"><?= h($s['znak_sprawy']) ?></div>
            </div>
            <div><?= ezd_etap_badge($s['etap'] ?? 'wszczeta') ?></div>
            <div class="text-nowrap flex-shrink-0" style="font-size:.7rem;min-width:5rem;text-align:right">
              <?php if(!empty($s['ciagla'])): ?>
              <span class="text-info"><i class="bi bi-infinity"></i></span>
              <?php elseif($s['deadline']): ?>
              <span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'text-muted' ?>"><?= date_pl($s['deadline']) ?></span>
              <?php else: ?><span class="text-muted">—</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; else: ?>
          <div class="text-muted text-center py-3" style="font-size:.8rem">Nie masz przypisanych aktywnych koszulek</div>
          <?php endif; ?>
          <div class="px-3 py-2" style="font-size:.75rem">
            <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="text-primary text-decoration-none fw-semibold">Wszystkie koszulki →</a>
          </div>
        </div>
        <div class="tab-pane fade" id="tab-moje-pisma">
          <?php if ($my_pisma): foreach ($my_pisma as $p):
            $k = EZD_KIERUNKI[$p['kierunek']] ?? ['icon'=>'bi-envelope','class'=>'secondary','label'=>$p['kierunek']]; ?>
          <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom ezdd-row-link" style="font-size:.8rem"
               onclick="location='<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $p['id'] ?>'">
            <i class="bi <?= $k['icon'] ?> text-<?= $k['class'] ?> flex-shrink-0"></i>
            <div class="font-monospace text-muted flex-shrink-0" style="font-size:.68rem"><?= h($p['sygnatura']) ?></div>
            <div class="flex-grow-1 overflow-hidden text-truncate"><?= h($p['title']) ?></div>
            <span class="badge bg-light text-dark border flex-shrink-0" style="font-size:.6rem"><?= h($p['status']) ?></span>
          </div>
          <?php endforeach; else: ?>
          <div class="text-muted text-center py-3" style="font-size:.8rem">Nie jesteś referentem żadnego pisma</div>
          <?php endif; ?>
        </div>

        <!-- Nowe pisma w jednostce -->
        <div class="tab-pane fade" id="tab-nowe-pisma">
          <?php if ($nowe_pisma_jedn): foreach ($nowe_pisma_jedn as $p):
            $k = EZD_KIERUNKI[$p['kierunek']] ?? ['icon'=>'bi-envelope','class'=>'secondary'];
            $med = ['papier'=>'','email'=>'E-mail','epuap'=>'eDoręczenia','faks'=>'Faks','inne'=>''][$p['rodzaj_medium']??''] ?? '';
          ?>
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom ezdd-row-link"
               style="font-size:.8rem;cursor:pointer"
               onclick="location='<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$p['id'] ?>'">
            <i class="bi bi-envelope-arrow-down text-info flex-shrink-0" aria-hidden="true"></i>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold text-truncate"><?= h($p['title']) ?></div>
              <div class="text-muted" style="font-size:.68rem">
                <span class="font-monospace"><?= h($p['sygnatura'] ?: '—') ?></span>
                · <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$p['sprawa_id'] ?>"
                     class="text-decoration-none text-muted" onclick="event.stopPropagation()">
                    <?= h(mb_substr($p['sprawa_title'] ?? '', 0, 35)) ?>
                  </a>
              </div>
            </div>
            <?php if ($med): ?>
            <span class="badge bg-light text-dark border flex-shrink-0" style="font-size:.58rem"><?= $med ?></span>
            <?php endif; ?>
            <span class="badge bg-<?= $p['status']==='nowe'?'info':'light text-dark border' ?> flex-shrink-0" style="font-size:.58rem"><?= h($p['status']) ?></span>
            <div class="text-muted flex-shrink-0" style="font-size:.68rem"><?= date('d.m', strtotime($p['created_at'])) ?></div>
          </div>
          <?php endforeach; else: ?>
          <div class="text-muted text-center py-4" style="font-size:.8rem">
            <i class="bi bi-envelope-check text-info" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.4"></i>
            Brak nowych pism przychodzących z ostatnich 14 dni
          </div>
          <?php endif; ?>
          <div class="px-3 py-2" style="font-size:.75rem">
            <a href="<?= APP_URL ?>/ezd/pisma/add.php" class="text-primary text-decoration-none fw-semibold">Zarejestruj pismo →</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Aktywne koszulki — tabela -->
    <div class="ezdd-card">
      <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2">
        <span style="font-size:.8rem;font-weight:700;color:#374151"><i class="bi bi-table me-1"></i>Aktywne koszulki</span>
        <a href="<?= APP_URL ?>/ezd/sprawy/index.php" style="font-size:.73rem;color:#2563eb;font-weight:600;text-decoration:none">Wszystkie →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead class="table-light">
            <tr><th>Tytuł</th><th>Znak</th><th>Deadline</th><th>Przekazana do</th></tr>
          </thead>
          <tbody>
          <?php foreach ($recent_sprawy as $s): $dk = $dekr_map[(int)$s['id']] ?? null; ?>
          <tr style="cursor:pointer" onclick="location='<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>'">
            <td class="fw-semibold"><?= h(mb_substr($s['title'], 0, 40)) ?><?= mb_strlen($s['title'])>40?'…':'' ?></td>
            <td class="font-monospace text-muted" style="font-size:.68rem"><?= h($s['znak_sprawy']) ?></td>
            <td class="<?= $s['deadline'] && $s['deadline'] < date('Y-m-d') ? 'text-danger fw-bold' : '' ?>">
              <?php if(!empty($s['ciagla'])): ?><i class="bi bi-infinity text-info"></i>
              <?php else: ?><?= $s['deadline'] ? date_pl($s['deadline']) : '—' ?><?php endif; ?>
            </td>
            <td>
              <?php if ($dk): ?>
              <span><?= h(mb_substr($dk['name'],0,20)) ?></span>
              <span class="badge bg-warning text-dark ms-1" style="font-size:.58rem" title="Liczba koszulek u tej osoby"><?= (int)$dk['count'] ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recent_sprawy): ?>
          <tr><td colspan="4" class="text-center text-muted py-2">Brak aktywnych koszulek</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /lewa treść -->

  <!-- Przekazania + aktywność -->
  <div class="col-lg-5">

    <!-- Moje zadania do wykonania -->
    <div class="ezdd-card mb-3">
      <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2">
        <span style="font-size:.8rem;font-weight:700;color:#374151">
          <i class="bi bi-person-lines-fill text-warning me-1"></i>Zadania do wykonania
        </span>
        <?php if($dekr_total): ?>
        <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $dekr_total ?></span>
        <?php endif; ?>
      </div>
      <?php if ($my_dekr): foreach ($my_dekr as $d): ?>
      <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.78rem">
        <span class="badge bg-<?= !empty($d['deadline'])&&$d['deadline']<date('Y-m-d')?'danger':'warning text-dark' ?>" style="font-size:.6rem;white-space:nowrap">
          <?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?>
        </span>
        <div class="flex-grow-1 overflow-hidden">
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $d['sprawa_id'] ?>" class="text-decoration-none fw-semibold d-block text-truncate"><?= h($d['sprawa_title']) ?></a>
          <div class="text-muted" style="font-size:.68rem"><?= h($d['znak_sprawy']) ?> · od: <?= h($d['zlec_name'] ?? '—') ?></div>
        </div>
        <?php if ($d['deadline']): ?>
        <span class="<?= $d['deadline'] < date('Y-m-d') ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:.7rem;white-space:nowrap"><?= date('d.m', strtotime($d['deadline'])) ?></span>
        <?php endif; ?>
        <form method="post" action="<?= APP_URL ?>/ezd/dekretacja/complete.php">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button class="btn btn-xs btn-outline-success btn-sm" title="Wykonane"><i class="bi bi-check-lg"></i></button>
        </form>
      </div>
      <?php endforeach; else: ?>
      <div class="text-muted text-center py-3" style="font-size:.8rem">Brak oczekujących zadań</div>
      <?php endif; ?>
    </div>

    <!-- Ostatnia aktywność -->
    <div class="ezdd-card">
      <div class="px-3 pt-3 pb-2" style="font-size:.8rem;font-weight:700;color:#374151">
        <i class="bi bi-activity me-1"></i>Ostatnia aktywność
      </div>
      <div style="padding:.5rem 1rem">
        <?php $dot_colors = ['sprawa_create'=>'#22c55e','sprawa_update'=>'#3b82f6','pismo_create'=>'#06b6d4','upload'=>'#94a3b8','dekretacja_create'=>'#f59e0b']; ?>
        <?php foreach ($activity as $a): ?>
        <div class="d-flex align-items-start gap-2 mb-2" style="font-size:.75rem">
          <span class="flex-shrink-0 mt-1" style="width:8px;height:8px;border-radius:50%;background:<?= $dot_colors[$a['action']] ?? '#94a3b8' ?>;display:inline-block"></span>
          <div class="flex-grow-1 overflow-hidden">
            <?php if ($a['znak_sprawy']): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $a['sprawa_id'] ?>" class="font-monospace text-decoration-none fw-semibold text-muted" style="font-size:.68rem"><?= h($a['znak_sprawy']) ?></a>
            <?php endif; ?>
            <div class="text-truncate" style="color:#374151"><?= h($a['details'] ?: $a['action']) ?></div>
            <div style="color:#94a3b8;font-size:.68rem"><?= h($a['user_name'] ?? '—') ?> · <?= date('d.m H:i', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$activity): ?>
        <div class="text-muted text-center py-2" style="font-size:.78rem">Brak aktywności</div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /prawa treść -->

</div><!-- /row treść -->

<script>
(function(){
  // ── eDoręczenia copy ────────────────────────────────────────────
  var statusEl = document.getElementById('edeCopyStatus');
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(flashOk, fallback);
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); flashOk(); } catch(e){}
      document.body.removeChild(ta);
    }
    function flashOk() {
      if (!statusEl) return;
      statusEl.classList.remove('d-none');
      clearTimeout(flashOk._t);
      flashOk._t = setTimeout(function(){ statusEl.classList.add('d-none'); }, 2000);
    }
  }
  document.querySelectorAll('.ede-copy-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ copyText(btn.getAttribute('data-ede-value') || ''); });
  });
  var hintBtn = document.getElementById('edeHintBtn');
  if (hintBtn) hintBtn.addEventListener('click', function(){
    copyText((hintBtn.getAttribute('data-ede-all') || '').replace(/&#10;/g, '\n'));
  });

  // ── Kalendarz ────────────────────────────────────────────────────
  var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
  var days   = ['Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
  var now    = new Date();
  var year   = now.getFullYear(), month = now.getMonth();

  function renderCal() {
    var cal = document.getElementById('ezd-cal');
    if (!cal) return;
    var first   = new Date(year, month, 1);
    var last    = new Date(year, month + 1, 0);
    var startDow = (first.getDay() + 6) % 7; // 0=Mon … 6=Sun
    var todayStr = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();

    var h  = '<div class="ezdd-cal-nav">';
    h += '<button id="cal-prev"><i class="bi bi-chevron-left"></i></button>';
    h += '<span class="ezdd-cal-month">' + months[month] + ' ' + year + '</span>';
    h += '<button id="cal-next"><i class="bi bi-chevron-right"></i></button>';
    h += '</div><div class="ezdd-cal-grid">';
    days.forEach(function(d){ h += '<div class="ezdd-cal-dow">' + d + '</div>'; });
    for (var i = 0; i < startDow; i++) h += '<div></div>';
    for (var d = 1; d <= last.getDate(); d++) {
      var dateStr = year + '-' + (month+1) + '-' + d;
      var isToday = (d === now.getDate() && month === now.getMonth() && year === now.getFullYear());
      h += '<div class="ezdd-cal-day' + (isToday ? ' ezdd-cal-today' : '') + '">' + d + '</div>';
    }
    h += '</div>';
    cal.innerHTML = h;

    document.getElementById('cal-prev').onclick = function(){
      month--; if (month < 0) { month = 11; year--; } renderCal();
    };
    document.getElementById('cal-next').onclick = function(){
      month++; if (month > 11) { month = 0; year++; } renderCal();
    };
  }
  renderCal();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
