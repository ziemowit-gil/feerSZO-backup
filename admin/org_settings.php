<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/krs.php';

require_role('admin');
$PAGE_TITLE = 'Dane organizacji';

function _org_reps_migrate(): void {
    static $done = false;
    if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS org_representatives (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        name      TEXT NOT NULL,
        title     TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}
_org_reps_migrate();

$branding_keys = ['org_name','org_krs','org_miejscowosc','org_nip','org_regon','org_adres','sidebar_color','volunteer_color','org_logo',
                  'notify_from_name','notify_from_email',
                  'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_encryption',
                  'm365_send_from_email'];
$saved = [];
foreach ($branding_keys as $k) {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    $saved[$k] = $r['value'] ?? '';
}
if (!$saved['sidebar_color']) $saved['sidebar_color'] = '#1e293b';
if (!$saved['volunteer_color']) $saved['volunteer_color'] = '#2563eb';

// Ensure logo directory exists
$_logo_dir = dirname(__DIR__) . '/assets/logo';
if (!is_dir($_logo_dir)) mkdir($_logo_dir, 0755, true);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['fetch_krs'])) {
        // Zaciągnij dane z KRS
        $krs_nr = preg_replace('/\D/', '', $_POST['org_krs'] ?? '');
        if (!$krs_nr) {
            $error = 'Podaj numer KRS organizacji.';
        } else {
            $data = krs_lookup($krs_nr);
            if (isset($data['error'])) {
                $error = $data['error'];
            } else {
                // Wyciągnij miejscowość z adresu
                $adres = $data['adres'] ?? '';
                $miejscowosc = '';
                // Adres format: "ul. Przykładowa 1, 00-001 Warszawa" — bierzemy miasto po kodzie pocztowym
                if (preg_match('/\d{2}-\d{3}\s+(.+)$/', $adres, $m)) {
                    $miejscowosc = trim($m[1]);
                } elseif (preg_match('/,\s*([^,]+)$/', $adres, $m)) {
                    $miejscowosc = trim($m[1]);
                }

                $update = [
                    'org_krs'         => $data['krs'],
                    'org_miejscowosc' => $miejscowosc,
                    'org_nip'         => $data['nip'] ?? '',
                    'org_regon'       => $data['regon'] ?? '',
                    'org_adres'       => $adres,
                ];
                foreach ($update as $k => $v) {
                    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
                    if ($exists) {
                        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
                    } else {
                        db_insert('settings', ['key_' => $k, 'value' => $v]);
                    }
                    $saved[$k] = $v;
                }
                flash_set('success', 'Dane organizacji zaciągnięte z KRS: ' . ($data['nazwa'] ?? ''));
                header('Location: ' . APP_URL . '/admin/org_settings.php');
                exit;
            }
        }
    } elseif (isset($_POST['save_manual'])) {
        // Ręczny zapis danych org
        $fields = ['org_name','org_krs','org_miejscowosc','org_nip','org_regon','org_adres'];
        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        foreach ($fields as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $v]);
            $saved[$k] = $v;
        }
        flash_set('success', 'Dane organizacji zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/org_settings.php');
        exit;

    } elseif (isset($_POST['save_representative'])) {
        // Dodaj osobę do reprezentacji
        _org_reps_migrate();
        $name  = trim($_POST['rep_name']  ?? '');
        $title = trim($_POST['rep_title'] ?? '');
        if ($name) {
            db()->prepare("INSERT INTO org_representatives (name, title) VALUES (?,?)")->execute([$name, $title]);
            flash_set('success', 'Dodano: ' . $name);
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php#representatives'); exit;

    } elseif (isset($_POST['delete_representative'])) {
        _org_reps_migrate();
        $rid = (int)($_POST['rep_id'] ?? 0);
        if ($rid) db()->prepare("DELETE FROM org_representatives WHERE id=?")->execute([$rid]);
        flash_set('success', 'Usunięto.');
        header('Location: ' . APP_URL . '/admin/org_settings.php#representatives'); exit;

    } elseif (isset($_POST['save_branding'])) {
        // Kolor sidebara
        $color = trim($_POST['sidebar_color'] ?? '#1e293b');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) $color = '#1e293b';

        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        $stmt->execute(['sidebar_color', $color]);
        $saved['sidebar_color'] = $color;

        // Kolor panelu wolontariusza
        $vol_color = trim($_POST['volunteer_color'] ?? '#2563eb');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $vol_color)) $vol_color = '#2563eb';
        $stmt->execute(['volunteer_color', $vol_color]);
        $saved['volunteer_color'] = $vol_color;

        // Upload logo
        if (!empty($_FILES['org_logo']['tmp_name']) && $_FILES['org_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['org_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','gif','svg','webp'])) {
                // Usuń stare logo
                if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])) {
                    @unlink($_logo_dir . '/' . $saved['org_logo']);
                }
                $fname = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['org_logo']['tmp_name'], $_logo_dir . '/' . $fname)) {
                    $stmt->execute(['org_logo', $fname]);
                    $saved['org_logo'] = $fname;
                }
            } else {
                $error = 'Dozwolone formaty logo: PNG, JPG, GIF, SVG, WebP.';
            }
        }

        // Usuń logo
        if (isset($_POST['remove_logo']) && $saved['org_logo']) {
            @unlink($_logo_dir . '/' . $saved['org_logo']);
            $stmt->execute(['org_logo', '']);
            $saved['org_logo'] = '';
        }

        if (!$error) {
            flash_set('success', 'Ustawienia brandingu zapisane.');
            header('Location: ' . APP_URL . '/admin/org_settings.php#branding');
            exit;
        }
    } elseif (isset($_POST['save_mail'])) {
        $mail_keys = ['notify_from_name','notify_from_email',
                      'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_encryption',
                      'm365_send_from_email'];
        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        foreach ($mail_keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $v]);
        }
        flash_set('success', 'Ustawienia poczty zapisane.');
        header('Location: ' . APP_URL . '/admin/org_settings.php#mail');
        exit;
    } elseif (isset($_POST['test_mail'])) {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        $to = trim($_POST['test_to'] ?? current_user()['email'] ?? '');
        if ($to) {
            $mid = mail_queue_add($to, '', 'Test wysyłki — ' . (defined('ORG_NAME') ? ORG_NAME : 'System'),
                '<p>To jest testowa wiadomość wysłana z systemu zarządzania umowami.</p>',
                'To jest testowa wiadomość wysłana z systemu zarządzania umowami.');
            $res = mail_queue_process(1);
            if ($res['sent']) flash_set('success', "Testowy e-mail wysłany na {$to}.");
            else flash_set('error', 'Wysyłka nie powiodła się — sprawdź logi serwera.');
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php#mail');
        exit;
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-building text-primary"></i> Dane organizacji i branding</h4>
<?= flash_html() ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
<?php endif; ?>

<div class="row">
<div class="col-lg-5">

<!-- ── Branding ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="branding">
<div class="card-header fw-semibold"><i class="bi bi-palette2 text-primary me-1"></i> Wygląd i branding</div>
<div class="card-body">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <!-- Kolor sidebara -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Kolor bocznego paska</label>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <input type="color" name="sidebar_color" id="sb_color_input"
               value="<?= h($saved['sidebar_color']) ?>"
               style="width:48px;height:38px;padding:2px;border-radius:8px;border:1px solid #dee2e6;cursor:pointer">
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ([
            '#1e293b' => 'Granatowy (domyślny)',
            '#0f172a' => 'Czarny',
            '#1a3a5c' => 'Granatowy',
            '#1e3a2f' => 'Ciemnozielony',
            '#2d1b4e' => 'Fioletowy',
            '#7c2d12' => 'Bordowy',
            '#374151' => 'Szary',
          ] as $hex => $label): ?>
          <button type="button" class="btn btn-sm p-0 border sb-preset"
                  data-color="<?= $hex ?>" title="<?= $label ?>"
                  style="width:28px;height:28px;background:<?= $hex ?>;border-radius:6px !important">
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-text">Wybierz gotowy kolor lub użyj pickera. Zalecane ciemne odcienie.</div>
    </div>

    <!-- Kolor panelu wolontariusza -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Kolor panelu wolontariusza</label>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <input type="color" name="volunteer_color" id="vol_color_input"
               value="<?= h($saved['volunteer_color']) ?>"
               style="width:48px;height:38px;padding:2px;border-radius:8px;border:1px solid #dee2e6;cursor:pointer">
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ([
            '#2563eb' => 'Niebieski (domyślny)',
            '#0f766e' => 'Turkusowy',
            '#7C3AED' => 'Fioletowy',
            '#dc2626' => 'Czerwony',
            '#16a34a' => 'Zielony',
            '#d97706' => 'Pomarańczowy',
            '#374151' => 'Szary',
          ] as $hex => $label): ?>
          <button type="button" class="btn btn-sm p-0 border vol-preset"
                  data-color="<?= $hex ?>" title="<?= $label ?>"
                  style="width:28px;height:28px;background:<?= $hex ?>;border-radius:6px !important">
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-text">Kolor akcentu w panelu wolontariusza (topbar, aktywne linki).</div>
    </div>

    <!-- Logo -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Logo organizacji</label>
      <?php if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])): ?>
      <div class="d-flex align-items-center gap-3 mb-2">
        <div style="background:<?= h($saved['sidebar_color']) ?>;padding:10px 14px;border-radius:10px;display:inline-block">
          <img src="<?= APP_URL ?>/assets/logo/<?= h($saved['org_logo']) ?>" alt="Logo"
               style="height:40px;width:auto;max-width:120px;object-fit:contain" id="logo-preview-current">
        </div>
        <div>
          <div class="small text-muted mb-1"><?= h($saved['org_logo']) ?></div>
          <button type="submit" name="remove_logo" value="1"
                  class="btn btn-sm btn-outline-danger"
                  onclick="return confirm('Usunąć logo?')">
            <i class="bi bi-trash3"></i> Usuń logo
          </button>
        </div>
      </div>
      <?php endif; ?>
      <input type="file" name="org_logo" class="form-control form-control-sm" id="logo_file_input"
             accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
      <div class="form-text">PNG, SVG, JPG · maks. 2 MB · Zalecany format: przezroczysty PNG lub SVG</div>
      <!-- Podgląd nowego logo przed uplodem -->
      <div id="logo-new-preview" class="mt-2" style="display:none">
        <div style="background:<?= h($saved['sidebar_color']) ?>;padding:10px 14px;border-radius:10px;display:inline-block" id="logo-preview-bg">
          <img id="logo-preview-img" src="" alt="Podgląd"
               style="height:40px;width:auto;max-width:120px;object-fit:contain">
        </div>
        <div class="small text-muted mt-1">Podgląd przed zapisem</div>
      </div>
    </div>

    <!-- Podgląd sidebara -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Podgląd paska</label>
      <?php
        $pv_dark = _sb_luminance($saved['sidebar_color']) < 0.35;
        $pv_text  = $pv_dark ? '#fff'     : '#111827';
        $pv_muted = $pv_dark ? '#94a3b8'  : '#374151';
        $pv_icon  = $pv_dark ? 'rgba(255,220,0,.9)' : '#2563eb';
        $pv_border= $pv_dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
      ?>
      <div id="sb-preview" style="background:<?= h($saved['sidebar_color']) ?>;border-radius:10px;padding:12px 14px;width:210px;transition:background .3s">
        <div data-sb-border style="color:<?= $pv_text ?>;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px;border-bottom:1px solid <?= $pv_border ?>;padding-bottom:8px;margin-bottom:8px" data-sb-text="text">
          <?php if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])): ?>
          <img src="<?= APP_URL ?>/assets/logo/<?= h($saved['org_logo']) ?>" style="height:22px;width:auto;border-radius:3px">
          <?php else: ?>
          <i class="bi bi-file-earmark-text-fill" style="color:<?= $pv_icon ?>;font-size:1.1rem" data-sb-text="icon"></i>
          <?php endif; ?>
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem"><?= h(ORG_NAME) ?></span>
        </div>
        <div style="color:<?= $pv_muted ?>;font-size:.7rem;padding:.3rem .4rem;border-radius:5px" data-sb-text="muted">
          <i class="bi bi-person-circle me-1"></i>Mój panel
        </div>
        <div style="color:<?= $pv_muted ?>;font-size:.7rem;padding:.3rem .4rem;border-radius:5px" data-sb-text="muted">
          <i class="bi bi-microsoft me-1" style="color:#00a4ef"></i>Microsoft 365
        </div>
        <div style="background:#2563eb;color:#fff;font-size:.7rem;padding:.3rem .6rem;border-radius:5px;margin-top:2px">
          <i class="bi bi-building me-1"></i>Aktywna strona
        </div>
      </div>
    </div>

    <button type="submit" name="save_branding" class="btn btn-primary">
      <i class="bi bi-floppy me-1"></i>Zapisz branding
    </button>
  </form>
</div>
</div>

</div><!-- /col-5 -->
<div class="col-lg-7">

<!-- Zaciągnij z KRS -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-cloud-download"></i> Zaciągnij dane z KRS</div>
<div class="card-body">
  <p class="text-muted small mb-3">
    Podaj numer KRS organizacji, aby automatycznie pobrać dane (nazwa, NIP, REGON, adres siedziby, miejscowość).
    Dane będą używane m.in. w nagłówku pism jako <em>„Miejscowość, dnia …"</em>.
  </p>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="row g-2 align-items-end">
      <div class="col-sm-7">
        <label class="form-label fw-semibold">Numer KRS organizacji</label>
        <input type="text" name="org_krs" class="form-control font-monospace"
               value="<?= h($saved['org_krs']) ?>" placeholder="np. 0000123456" maxlength="10">
      </div>
      <div class="col-sm-5">
        <button type="submit" name="fetch_krs" class="btn btn-primary w-100">
          <i class="bi bi-cloud-download"></i> Pobierz z KRS
        </button>
      </div>
    </div>
  </form>

  <?php if ($saved['org_krs']): ?>
  <div class="alert alert-success mt-3 mb-0 py-2 small">
    <i class="bi bi-check-circle"></i>
    Dane pobrane — KRS <strong><?= h($saved['org_krs']) ?></strong>
    <?php if ($saved['org_miejscowosc']): ?>
    · Miejscowość: <strong><?= h($saved['org_miejscowosc']) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Dane ręczne -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-pencil"></i> Dane szczegółowe (edycja ręczna)</div>
<div class="card-body">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Pełna nazwa organizacji <span class="text-muted small">(wyświetlana w systemie, mailach, dokumentach)</span></label>
        <input type="text" name="org_name" class="form-control"
               value="<?= h($saved['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : '')) ?>"
               placeholder="np. Fundacja Edukacji Empatii Rozwoju FEER">
        <div class="form-text">Jeśli puste — używana wartość z config.php: <code><?= h(defined('ORG_NAME') ? ORG_NAME : '—') ?></code></div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Numer KRS</label>
        <input type="text" name="org_krs" class="form-control font-monospace"
               value="<?= h($saved['org_krs']) ?>" placeholder="0000000000">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Miejscowość siedziby <span class="text-muted small">(używana w nagłówku pism)</span></label>
        <input type="text" name="org_miejscowosc" class="form-control"
               value="<?= h($saved['org_miejscowosc']) ?>" placeholder="np. Warszawa">
      </div>
      <div class="col-md-6">
        <label class="form-label">NIP</label>
        <input type="text" name="org_nip" class="form-control font-monospace"
               value="<?= h($saved['org_nip']) ?>" placeholder="0000000000">
      </div>
      <div class="col-md-6">
        <label class="form-label">REGON</label>
        <input type="text" name="org_regon" class="form-control font-monospace"
               value="<?= h($saved['org_regon']) ?>" placeholder="">
      </div>
      <div class="col-12">
        <label class="form-label">Adres siedziby</label>
        <input type="text" name="org_adres" class="form-control"
               value="<?= h($saved['org_adres']) ?>" placeholder="ul. Przykładowa 1, 00-001 Warszawa">
      </div>
    </div>
    <div class="mt-3">
      <button type="submit" name="save_manual" class="btn btn-outline-primary">
        <i class="bi bi-floppy"></i> Zapisz ręcznie
      </button>
    </div>
  </form>
</div>
</div>

<!-- ── Osoby do reprezentacji ──────────────────────────────────────────── -->
<?php $reps = db_all("SELECT * FROM org_representatives WHERE is_active=1 ORDER BY sort_order, name"); ?>
<div class="card shadow-sm mb-3" id="representatives">
<div class="card-header fw-semibold d-flex align-items-center justify-content-between">
  <span><i class="bi bi-person-badge me-1"></i>Osoby do reprezentacji organizacji</span>
  <span class="text-muted small">Używane jako podpisujący na umowach i dokumentach</span>
</div>
<div class="card-body">

  <!-- Dodaj nową osobę -->
  <form method="post" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="col-sm-5">
      <label class="form-label small fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
      <input type="text" name="rep_name" class="form-control form-control-sm"
             placeholder="np. Jan Kowalski" required maxlength="120">
    </div>
    <div class="col-sm-5">
      <label class="form-label small fw-semibold">Stanowisko / funkcja</label>
      <input type="text" name="rep_title" class="form-control form-control-sm"
             placeholder="np. Prezes Zarządu" maxlength="120">
    </div>
    <div class="col-sm-2">
      <button type="submit" name="save_representative" class="btn btn-primary btn-sm w-100">
        <i class="bi bi-plus-lg me-1"></i>Dodaj
      </button>
    </div>
  </form>

  <!-- Lista -->
  <?php if ($reps): ?>
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th>Imię i nazwisko</th>
        <th>Stanowisko</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($reps as $r): ?>
      <tr>
        <td class="fw-semibold small"><?= h($r['name']) ?></td>
        <td class="text-muted small"><?= h($r['title']) ?></td>
        <td class="text-end">
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć?')">
            <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
            <input type="hidden" name="rep_id"  value="<?= $r['id'] ?>">
            <button type="submit" name="delete_representative"
                    class="btn btn-outline-danger btn-sm py-0 px-2">
              <i class="bi bi-trash3"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="text-muted small mb-0">Brak zdefiniowanych przedstawicieli. Dodaj pierwszego powyżej.</p>
  <?php endif; ?>

</div>
</div>

<!-- Podgląd użycia -->
<?php if ($saved['org_miejscowosc']): ?>
<div class="card shadow-sm border-info mb-3">
<div class="card-header fw-semibold text-info-emphasis"><i class="bi bi-eye"></i> Podgląd nagłówka pisma</div>
<div class="card-body" style="font-family:'Times New Roman',serif;font-size:12pt">
  <div style="text-align:right">
    <?= h($saved['org_miejscowosc']) ?>, dnia <?= date_pl(date('Y-m-d')) ?>
  </div>
</div>
</div>
<?php endif; ?>

</div><!-- /col-7 -->
</div><!-- /row -->

<script>
// ── Oblicz luminancję hex ────────────────────────────────────────────────────
function hexLuminance(hex) {
    hex = hex.replace('#','');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    const lin = c => { c /= 255; return c <= .03928 ? c/12.92 : Math.pow((c+.055)/1.055, 2.4); };
    return .2126*lin(parseInt(hex.slice(0,2),16)) +
           .7152*lin(parseInt(hex.slice(2,4),16)) +
           .0722*lin(parseInt(hex.slice(4,6),16));
}

// ── Live kolor sidebara ──────────────────────────────────────────────────────
const colorInput = document.getElementById('sb_color_input');
const sbPreview  = document.getElementById('sb-preview');
const previewBg  = document.getElementById('logo-preview-bg');
const previewBgCurrent = document.querySelector('[id^="logo-preview-current"]')?.closest('[style*="background"]');

function applyColor(hex) {
    colorInput.value = hex;
    const dark = hexLuminance(hex) < 0.35;

    // Tokeny
    const tokens = dark ? {
        text: '#fff', muted: '#94a3b8', label: '#475569', icon: 'rgba(255,220,0,.9)'
    } : {
        text: '#111827', muted: '#374151', label: '#6b7280', icon: '#2563eb'
    };

    // Aktualizuj podgląd paska
    if (sbPreview) {
        sbPreview.style.background = hex;
        sbPreview.querySelectorAll('[data-sb-text]').forEach(el => {
            el.style.color = tokens[el.dataset.sbText] || tokens.text;
        });
        const border = sbPreview.querySelector('[data-sb-border]');
        if (border) border.style.borderBottomColor = dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
    }

    // Tło podglądu logo
    if (previewBg) previewBg.style.background = hex;
    if (previewBgCurrent) previewBgCurrent.style.background = hex;
}

colorInput?.addEventListener('input', () => applyColor(colorInput.value));
document.querySelectorAll('.sb-preset').forEach(btn =>
    btn.addEventListener('click', () => applyColor(btn.dataset.color))
);

// Volunteer color presets
const volColorInput = document.getElementById('vol_color_input');
document.querySelectorAll('.vol-preset').forEach(btn =>
    btn.addEventListener('click', () => { if(volColorInput) volColorInput.value = btn.dataset.color; })
);

// ── Podgląd nowego logo ──────────────────────────────────────────────────────
document.getElementById('logo_file_input')?.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('logo-preview-img').src = e.target.result;
        document.getElementById('logo-new-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
});
</script>

<!-- ── Ustawienia poczty ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4" id="mail">
<div class="card-header fw-semibold"><i class="bi bi-envelope-at text-primary me-1"></i> Wysyłka poczty e-mail</div>
<div class="card-body">

  <p class="text-muted small mb-3">
    Priorytet wysyłki: <strong>M365 Graph API</strong> → <strong>SMTP</strong> → PHP <code>mail()</code>.
    Jeśli M365 jest skonfigurowane globalnie (w ustawieniach Microsoft 365) i podasz adres nadawcy poniżej — system użyje Graph API.
  </p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <!-- Nadawca domyślny -->
    <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Domyślny nadawca</h6>
    <div class="row g-3 mb-4">
      <div class="col-md-5">
        <label class="form-label small fw-semibold">Nazwa nadawcy</label>
        <input type="text" name="notify_from_name" class="form-control form-control-sm"
               value="<?= h($saved['notify_from_name'] ?? '') ?>" placeholder="np. Fundacja XYZ">
      </div>
      <div class="col-md-7">
        <label class="form-label small fw-semibold">Adres e-mail nadawcy</label>
        <input type="email" name="notify_from_email" class="form-control form-control-sm"
               value="<?= h($saved['notify_from_email'] ?? '') ?>" placeholder="no-reply@fundacja.pl">
        <div class="form-text">Używany gdy nie ma M365 ani SMTP lub jako Reply-To.</div>
      </div>
    </div>

    <!-- M365 -->
    <h6 class="fw-semibold mb-2 text-muted d-flex align-items-center gap-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
      <i class="bi bi-microsoft text-primary"></i> Microsoft 365 Graph API
    </h6>
    <div class="row g-3 mb-4">
      <div class="col-md-8">
        <label class="form-label small fw-semibold">Adres skrzynki nadawczej (send_from_email)</label>
        <input type="email" name="m365_send_from_email" class="form-control form-control-sm"
               value="<?= h($saved['m365_send_from_email'] ?? '') ?>"
               placeholder="np. noreply@fundacja.onmicrosoft.com">
        <div class="form-text">
          Skrzynka musi mieć uprawnienie <code>Mail.Send</code> w aplikacji Azure AD.
          Tenant ID / Client ID / Secret konfiguruj w
          <a href="<?= APP_URL ?>/admin/m365_settings.php">Ustawieniach Microsoft 365</a>.
        </div>
      </div>
      <?php
        $m365_ok = !empty($saved['m365_send_from_email'])
            && !empty(db_one("SELECT value FROM settings WHERE key_='m365_graph_client_id'")['value'] ?? '');
      ?>
      <div class="col-md-4 d-flex align-items-end">
        <?php if ($m365_ok): ?>
        <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-3 py-2">
          <i class="bi bi-check-circle me-1"></i>Gotowe
        </span>
        <?php else: ?>
        <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 px-3 py-2">
          <i class="bi bi-exclamation-circle me-1"></i>Nie skonfigurowane
        </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- SMTP -->
    <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
      <i class="bi bi-hdd-network me-1"></i>SMTP (fallback)
    </h6>
    <div class="row g-3 mb-4">
      <div class="col-md-5">
        <label class="form-label small fw-semibold">Serwer SMTP</label>
        <input type="text" name="smtp_host" class="form-control form-control-sm"
               value="<?= h($saved['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Port</label>
        <input type="number" name="smtp_port" class="form-control form-control-sm"
               value="<?= h($saved['smtp_port'] ?? '587') ?>" placeholder="587">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Szyfrowanie</label>
        <select name="smtp_encryption" class="form-select form-select-sm">
          <?php foreach (['tls'=>'STARTTLS (TLS)','ssl'=>'SSL','none'=>'Brak'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($saved['smtp_encryption']??'tls')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold">Login SMTP</label>
        <input type="text" name="smtp_user" class="form-control form-control-sm" autocomplete="off"
               value="<?= h($saved['smtp_user'] ?? '') ?>" placeholder="user@gmail.com">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Hasło SMTP</label>
        <input type="password" name="smtp_pass" class="form-control form-control-sm" autocomplete="new-password"
               value="<?= h($saved['smtp_pass'] ?? '') ?>" placeholder="••••••••">
      </div>
      <div class="col-md-7">
        <label class="form-label small fw-semibold">Adres e-mail nadawcy SMTP</label>
        <input type="email" name="smtp_from_email" class="form-control form-control-sm"
               value="<?= h($saved['smtp_from_email'] ?? '') ?>" placeholder="no-reply@fundacja.pl">
        <div class="form-text">Pozostaw puste, aby użyć domyślnego nadawcy powyżej.</div>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <button type="submit" name="save_mail" class="btn btn-primary btn-sm">
        <i class="bi bi-check-lg me-1"></i>Zapisz ustawienia poczty
      </button>
    </div>
  </form>

  <!-- Test -->
  <hr class="my-3">
  <form method="post" class="d-flex align-items-end gap-2 flex-wrap">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label small fw-semibold mb-1">Testowy e-mail — wyślij na adres:</label>
      <input type="email" name="test_to" class="form-control form-control-sm" style="min-width:220px"
             value="<?= h(current_user()['email'] ?? '') ?>" required>
    </div>
    <button type="submit" name="test_mail" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-send me-1"></i>Wyślij test
    </button>
  </form>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
