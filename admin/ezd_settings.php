<?php
/**
 * Ustawienia modułu Wirtualne biurko.
 * Włącznik modułu, powiadomienia o terminach, przegląd i skróty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia modułu Wirtualne biurko';

$settings_keys = [
    'ezd_enabled',
    'ezd_mini',
    'ezd_reminders_enabled',
    'ezd_peln_jrwa',
    'ezd_cert_jrwa',
    'ezd_kdok_jrwa',
    'corr_ezd_auto',
    'corr_ezd_jrwa',
    'ezd_rpwy_auto',
    'ezd_rsign_port',
    'ezd_rsign_api_base',
    'ezd_rsign_data_field',
];

$_logo_dir = dirname(__DIR__) . '/assets/logo';
if (!is_dir($_logo_dir)) @mkdir($_logo_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Upload logo EZD
    if (!empty($_FILES['ezd_logo']['tmp_name']) && $_FILES['ezd_logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['ezd_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','svg','gif','webp'])) {
            $old = org_setting('ezd_logo') ?: '';
            if ($old && file_exists($_logo_dir . '/' . $old)) @unlink($_logo_dir . '/' . $old);
            $fname = 'ezd_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['ezd_logo']['tmp_name'], $_logo_dir . '/' . $fname)) {
                db()->prepare("INSERT INTO settings (key_,value) VALUES ('ezd_logo',?)
                    ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$fname]);
            }
        }
    }
    if (isset($_POST['remove_ezd_logo'])) {
        $old = org_setting('ezd_logo') ?: '';
        if ($old && file_exists($_logo_dir . '/' . $old)) @unlink($_logo_dir . '/' . $old);
        db()->prepare("INSERT INTO settings (key_,value) VALUES ('ezd_logo','')
            ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([]);
    }

    $values = [
        'ezd_enabled'           => isset($_POST['ezd_enabled'])           ? '1' : '0',
        'ezd_mini'              => isset($_POST['ezd_mini'])              ? '1' : '0',
        'ezd_reminders_enabled' => isset($_POST['ezd_reminders_enabled']) ? '1' : '0',
        'ezd_peln_jrwa'         => trim($_POST['ezd_peln_jrwa'] ?? '') ?: '013',
        'ezd_cert_jrwa'         => trim($_POST['ezd_cert_jrwa'] ?? '') ?: '53',
        'ezd_kdok_jrwa'         => trim($_POST['ezd_kdok_jrwa'] ?? '') ?: 'KSG',
        'corr_ezd_auto'         => isset($_POST['corr_ezd_auto']) ? '1' : '0',
        'corr_ezd_jrwa'         => trim($_POST['corr_ezd_jrwa'] ?? '') ?: 'KOR',
        'ezd_rpwy_auto'         => isset($_POST['ezd_rpwy_auto']) ? '1' : '0',
        'ezd_rsign_port'        => max(1, min(65535, (int)($_POST['ezd_rsign_port'] ?? 7778))) ?: '7778',
        'ezd_rsign_api_base'    => trim($_POST['ezd_rsign_api_base'] ?? '') ?: '/api/sign',
        'ezd_rsign_data_field'  => preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['ezd_rsign_data_field'] ?? '')) ?: 'signedData',
    ];
    foreach ($values as $key => $val) {
        try {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?)
                ON CONFLICT(key_) DO UPDATE SET value=excluded.value")->execute([$key, $val]);
        } catch (\Throwable $e) {
            $exists = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
            if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
            else         db_insert('settings', ['key_' => $key, 'value' => $val]);
        }
    }
    flash_set('success', 'Ustawienia modułu EZD zostały zapisane.');
    header('Location: ezd_settings.php'); exit;
}

$cfg = [];
foreach ($settings_keys as $k) $cfg[$k] = org_setting($k);
if ($cfg['ezd_reminders_enabled'] === '') $cfg['ezd_reminders_enabled'] = '1';
if ($cfg['ezd_rpwy_auto'] === '')         $cfg['ezd_rpwy_auto'] = '1';

// Przegląd modułu
$stat = ezd_stats();
$rpw  = ezd_rpw_stats();
$counts = [
    'jrwa'      => (int)(db_one("SELECT COUNT(*) c FROM ezd_jrwa")['c'] ?? 0),
    'dokumenty' => (int)(db_one("SELECT COUNT(*) c FROM ezd_dokumenty")['c'] ?? 0),
    'notatki'   => (int)(db_one("SELECT COUNT(*) c FROM ezd_notatki")['c'] ?? 0),
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Ustawienia modułu Wirtualne biurko</h4>
  <a href="<?= APP_URL ?>/ezd/index.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Przejdź do EZD</a>
</div>

<?= flash_html() ?>

<div class="row g-4" style="max-width:920px">
  <div class="col-12">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-gear me-2 text-primary"></i>Ogólne</div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_enabled" name="ezd_enabled" <?= module_enabled('ezd_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_enabled">Moduł włączony</label>
            <div class="form-text">Udostępnia sekcję „Wirtualne biurko" w menu (Koszulki, Segregatory, Pisma, Dziennik podawczy, JRWA). To samo ustawienie znajdziesz w <a href="<?= APP_URL ?>/admin/modules_settings.php">Modułach</a>.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_mini" name="ezd_mini" <?= $cfg['ezd_mini'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_mini">Tryb „mini" (uproszczony)</label>
            <div class="form-text">Uproszczony rejestr spraw i dokumentów — ukrywa formalne elementy postępowania w widoku sprawy: <strong>metrykę</strong> oraz <strong>obieg/workflow (BPM)</strong>. Pozostają: dane sprawy, pisma, dokumenty wewnętrzne, notatki, repozytorium plików i dekretacja.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_reminders_enabled" name="ezd_reminders_enabled" <?= $cfg['ezd_reminders_enabled'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_reminders_enabled">Powiadomienia e-mail o terminach</label>
            <div class="form-text">Codzienny cron wysyła przypomnienia o terminach dekretacji (do wykonawcy) i spraw (do właściciela): jutro / dziś / po terminie.</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="corr_ezd_auto" name="corr_ezd_auto" <?= $cfg['corr_ezd_auto'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="corr_ezd_auto">Automatyczna rejestracja korespondencji w EZD Wirtualne biurko</label>
            <div class="form-text">Każda nowa korespondencja przychodząca/wychodząca trafia automatycznie do sprawy ciągłej „Korespondencja przychodząca/wychodząca {rok}" jako pismo (z przeniesieniem załącznika). Po wyłączeniu pozostaje ręczne „Zarejestruj w EZD".</div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="ezd_rpwy_auto" name="ezd_rpwy_auto" <?= $cfg['ezd_rpwy_auto'] !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ezd_rpwy_auto">Automatyczny wpis pism wychodzących do książki nadawczej</label>
            <div class="form-text">Każde nowe pismo wychodzące dostaje kolejny numer RPW-W w <a href="<?= APP_URL ?>/ezd/rpwy/index.php">książce nadawczej</a> ze stanem „Przygotowana" (albo „Nadana", jeśli pismo ma już datę wysyłki). Po wyłączeniu wpis dodaje się ręcznie z karty pisma.</div>
          </div>
          <div class="row g-3">
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_peln_jrwa">Symbol JRWA pełnomocnictw</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_peln_jrwa" name="ezd_peln_jrwa" value="<?= h($cfg['ezd_peln_jrwa'] ?: '013') ?>" placeholder="013">
              <div class="form-text"><a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php">Rejestr pełnomocnictw</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_cert_jrwa">Symbol JRWA zaświadczeń</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_cert_jrwa" name="ezd_cert_jrwa" value="<?= h($cfg['ezd_cert_jrwa'] ?: '53') ?>" placeholder="53">
              <div class="form-text"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Rejestr zaświadczeń</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="corr_ezd_jrwa">Symbol JRWA korespondencji</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="corr_ezd_jrwa" name="corr_ezd_jrwa" value="<?= h($cfg['corr_ezd_jrwa'] ?: 'KOR') ?>" placeholder="KOR">
              <div class="form-text"><a href="<?= APP_URL ?>/correspondence/index.php">Korespondencja</a></div>
            </div>
            <div class="col-sm-4" style="max-width:200px">
              <label class="form-label fw-semibold mb-1" for="ezd_kdok_jrwa">Symbol JRWA dok. księgowych</label>
              <input type="text" class="form-control form-control-sm font-monospace" id="ezd_kdok_jrwa" name="ezd_kdok_jrwa" value="<?= h($cfg['ezd_kdok_jrwa'] ?: 'KSG') ?>" placeholder="KSG">
              <div class="form-text">JRWA „Dokumenty księgowe - obieg od zapłaty". Dokumenty zatwierdzone do wypłaty w <a href="<?= APP_URL ?>/ksiegowosc/index.php">EOD Dok. Księgowych</a> trafiają tu automatycznie.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Podpis kwalifikowany (lokalna aplikacja) -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-pen-fill me-2 text-primary"></i>Podpis kwalifikowany — lokalna aplikacja</div>
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label fw-semibold mb-1">Preset aplikacji</label>
            <div class="d-flex flex-wrap gap-2" id="rsign-presets">
              <button type="button" class="btn btn-sm btn-outline-secondary rsign-preset-btn"
                      data-port="7778" data-base="/api/sign" data-field="signedData"
                      title="SIGILLUM PEM-HEART — macOS/Windows/Linux, port 7778">
                <i class="bi bi-pen-fill me-1" style="color:#6d28d9"></i>PEM-HEART
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary rsign-preset-btn"
                      data-port="52117" data-base="/api/v1" data-field="data"
                      title="CenCert rSign Desktop — Windows, port 52117">
                <i class="bi bi-pen-fill me-1" style="color:#1d4ed8"></i>rSign (CenCert)
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary rsign-preset-btn"
                      data-port="7778" data-base="" data-field="data"
                      title="KIR mSzafir — Windows, port 7778">
                <i class="bi bi-pen-fill me-1" style="color:#0f6e56"></i>mSzafir (KIR)
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary rsign-preset-btn"
                      data-port="52117" data-base="/api/v1" data-field="data"
                      title="Certum proCertum SmartSign — Windows, port 52117">
                <i class="bi bi-pen-fill me-1" style="color:#b45309"></i>proCertum (Certum)
              </button>
            </div>
            <div class="form-text">Kliknij preset, aby wstępnie uzupełnić pola poniżej — dostosuj ręcznie jeśli port w Twojej aplikacji jest inny.</div>
          </div>

          <div class="row g-3">
            <div class="col-sm-3" style="max-width:160px">
              <label class="form-label fw-semibold mb-1" for="ezd_rsign_port">Port lokalny</label>
              <input type="number" class="form-control form-control-sm font-monospace"
                     id="ezd_rsign_port" name="ezd_rsign_port"
                     value="<?= h($cfg['ezd_rsign_port'] ?: '7778') ?>"
                     min="1" max="65535" placeholder="7778">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold mb-1" for="ezd_rsign_api_base">Ścieżka bazowa API</label>
              <input type="text" class="form-control form-control-sm font-monospace"
                     id="ezd_rsign_api_base" name="ezd_rsign_api_base"
                     value="<?= h($cfg['ezd_rsign_api_base'] ?: '/api/sign') ?>"
                     placeholder="/api/sign" maxlength="80">
              <div class="form-text">
                Endpoint podpisu: <code>POST localhost:{port}{ścieżka}</code>
              </div>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold mb-1" for="ezd_rsign_data_field">Pole odpowiedzi z podpisem</label>
              <input type="text" class="form-control form-control-sm font-monospace"
                     id="ezd_rsign_data_field" name="ezd_rsign_data_field"
                     value="<?= h($cfg['ezd_rsign_data_field'] ?: 'signedData') ?>"
                     placeholder="signedData" maxlength="40">
              <div class="form-text">Nazwa pola JSON w odpowiedzi aplikacji, np. <code>signedData</code>, <code>data</code>.</div>
            </div>
          </div>

          <div class="alert alert-light border mt-3 mb-0 py-2" style="font-size:.8rem">
            <i class="bi bi-info-circle me-1"></i>
            Aplikacja musi być uruchomiona lokalnie, wystawiać REST API na <code>localhost:{port}</code>
            i mieć włączone CORS dla domeny <code><?= h(parse_url(APP_URL, PHP_URL_HOST) ?: APP_URL) ?></code>.
            <strong>PEM-HEART na macOS</strong>: uruchom aplikację, podłącz token/kartę, sprawdź że działa lokalny serwer.
          </div>
        </div>
      </div>
      <script>
      document.querySelectorAll('.rsign-preset-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          document.getElementById('ezd_rsign_port').value     = this.dataset.port;
          document.getElementById('ezd_rsign_api_base').value = this.dataset.base;
          document.getElementById('ezd_rsign_data_field').value = this.dataset.field;
          document.querySelectorAll('.rsign-preset-btn').forEach(function(b){ b.classList.remove('btn-secondary','text-white'); b.classList.add('btn-outline-secondary'); });
          this.classList.remove('btn-outline-secondary'); this.classList.add('btn-secondary','text-white');
        });
      });
      </script>

      <!-- EU DSS -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
          <i class="bi bi-shield-check text-primary"></i>Walidacja podpisów EU DSS (eIDAS)
          <?php if (org_setting('dss_enabled') === '1'): ?>
          <span class="badge bg-success ms-auto" style="font-weight:500">Włączone</span>
          <?php else: ?>
          <span class="badge bg-secondary ms-auto" style="font-weight:500">Wyłączone</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <p class="text-muted mb-3" style="font-size:.83rem">
            EU DSS sprawdza podpisy PAdES, XAdES, CAdES i ASiC przez europejskie listy zaufanych dostawców (LOTL).
            Wymaga własnego serwera DSS (Docker — <code>docker-compose.dss.yml</code>).
          </p>
          <?php if (org_setting('dss_url')): ?>
          <div class="mb-2" style="font-size:.83rem">
            URL: <code><?= h(org_setting('dss_url')) ?></code>
          </div>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/admin/dss_settings.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-gear me-1"></i>Konfiguruj EU DSS
          </a>
        </div>
      </div>

      <!-- Logo EZD -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-image me-2 text-primary"></i>Logo dla dokumentów EZD</div>
        <div class="card-body">
          <p class="text-muted mb-3" style="font-size:.83rem">Logo używane w nagłówku PDF zaświadczeń i dokumentów EZD. Jeśli nie ustawione — używane jest logo ogólne organizacji.</p>
          <?php
            $_ezd_logo = org_setting('ezd_logo') ?: '';
            $_org_logo  = org_setting('org_logo') ?: '';
          ?>
          <?php if ($_ezd_logo && file_exists($_logo_dir . '/' . $_ezd_logo)): ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="border rounded p-2 bg-white" style="min-width:100px;text-align:center">
              <img src="<?= APP_URL ?>/assets/logo/<?= h($_ezd_logo) ?>" alt="Logo EZD" style="max-height:60px;max-width:180px">
            </div>
            <div>
              <div class="text-muted mb-1" style="font-size:.78rem"><?= h($_ezd_logo) ?></div>
              <button type="submit" name="remove_ezd_logo" value="1" class="btn btn-outline-danger btn-sm"
                      onclick="return confirm('Usunąć logo EZD?')">
                <i class="bi bi-trash3 me-1"></i>Usuń logo EZD
              </button>
            </div>
          </div>
          <?php elseif ($_org_logo && file_exists($_logo_dir . '/' . $_org_logo)): ?>
          <div class="d-flex align-items-center gap-2 mb-3 text-muted" style="font-size:.82rem">
            <img src="<?= APP_URL ?>/assets/logo/<?= h($_org_logo) ?>" alt="Logo org" style="max-height:36px;max-width:120px;opacity:.5">
            <span>Używane logo ogólne organizacji (brak dedykowanego logo EZD)</span>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label fw-semibold mb-1" style="font-size:.83rem" for="ezd_logo_input">
              <?= $_ezd_logo ? 'Zastąp logo EZD' : 'Wgraj logo EZD' ?>
            </label>
            <input type="file" name="ezd_logo" id="ezd_logo_input" class="form-control form-control-sm"
                   accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp" style="max-width:360px">
            <div class="form-text">PNG, SVG, JPG — zalecany PNG z przezroczystym tłem, min. 200×60 px.</div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz ustawienia</button>
    </form>
  </div>

  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-graph-up me-2 text-primary"></i>Przegląd modułu</div>
      <div class="card-body">
        <div class="row g-3 text-center">
          <?php foreach ([
            ['Segregatory otwarte', $stat['teczki_open'], '/ezd/teczki/index.php'],
            ['Koszulki aktywne', $stat['sprawy_open'], '/ezd/sprawy/index.php'],
            ['W koszulce (RPW)', $rpw['koszulka'], '/ezd/rpw/index.php?status=nowa'],
            ['Hasła JRWA', $counts['jrwa'], '/ezd/jrwa/index.php'],
            ['Dokumenty wewn.', $counts['dokumenty'], null],
            ['Notatki', $counts['notatki'], null],
          ] as [$lbl,$val,$url]): ?>
          <div class="col-6 col-md-4 col-lg-2">
            <?php if($url): ?><a href="<?= APP_URL.$url ?>" class="text-decoration-none text-reset d-block"><?php endif; ?>
            <div class="border rounded-3 py-3">
              <div style="font-size:1.6rem;font-weight:700;line-height:1"><?= (int)$val ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= h($lbl) ?></div>
            </div>
            <?php if($url): ?></a><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
