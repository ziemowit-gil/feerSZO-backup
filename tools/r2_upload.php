<?php
/**
 * tools/r2_upload.php — Upload plików do Cloudflare R2 (S3 API)
 *
 * Narzędzie dostępne po zalogowaniu. Każdy zalogowany użytkownik może wysyłać
 * pliki; konfigurację poświadczeń (klucze, bucket, endpoint) może zmieniać
 * wyłącznie administrator.
 *
 * Wykorzystuje generyczny klient z includes/s3_client.php (AWS Signature V4),
 * który działa z R2 oraz dowolnym magazynem zgodnym z S3.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/s3_client.php';

require_login();

$user     = current_user();
$is_admin = is_admin();
$is_ajax  = (($_GET['_ajax'] ?? '') === '1')
    || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

/* ── Konfiguracja z tabeli settings ──────────────────────────────────────── */
function r2tool_cfg(): array {
    $account  = org_setting('tool_r2_account_id');
    $endpoint = org_setting('tool_r2_endpoint');
    if ($endpoint === '' && $account !== '') {
        $endpoint = "https://{$account}.r2.cloudflarestorage.com";
    }
    return [
        'account_id'  => $account,
        'endpoint'    => $endpoint,
        'region'      => org_setting('tool_r2_region') ?: 'auto',
        'bucket'      => org_setting('tool_r2_bucket'),
        'access_key'  => org_setting('tool_r2_access_key'),
        'secret_key'  => org_setting('tool_r2_secret_key'),
        'prefix'      => org_setting('tool_r2_prefix'),
        'public_base' => org_setting('tool_r2_public_base'),
    ];
}

/** Bezpieczna nazwa pliku/segmentu ścieżki. */
function r2tool_safe_name(string $name): string {
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9_.\-]/u', '_', $name);
    $name = trim($name, '._') ?: 'plik';
    return substr($name, 0, 180);
}

$cfg        = r2tool_cfg();
$cfg_ready  = s3_config_ready($cfg);
$flash      = null;   // ['type'=>'success|danger|warning|info', 'msg'=>string]
$upload_res = [];     // wyniki wysyłki [['ok'=>,'name'=>,'msg'=>,'url'=>], ...]

/* ── POST ────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Przekroczenie post_max_size: PHP czyści $_POST/$_FILES, więc csrf_check()
    // dałby mylący „błąd CSRF". Wykryj to i zwróć czytelny komunikat.
    $content_len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_len > 0 && empty($_POST) && empty($_FILES)) {
        $limit = ini_get('post_max_size') ?: '?';
        $msg   = "Plik(i) przekraczają dopuszczalny limit wysyłki (post_max_size = {$limit}). "
               . "Zmniejsz rozmiar lub poproś administratora o podniesienie limitu.";
        if ($is_ajax) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['flash' => ['type' => 'danger', 'msg' => $msg], 'results' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $flash  = ['type' => 'danger', 'msg' => $msg];
        $action = '';
    } else {
        csrf_check();
        $action = $_POST['action'] ?? '';
    }

    if ($action === 'save_settings') {
        if (!$is_admin) {
            http_response_code(403);
            $flash = ['type' => 'danger', 'msg' => 'Tylko administrator może zmieniać konfigurację.'];
        } else {
            foreach ([
                'tool_r2_account_id', 'tool_r2_endpoint', 'tool_r2_region',
                'tool_r2_bucket', 'tool_r2_access_key', 'tool_r2_prefix',
                'tool_r2_public_base',
            ] as $key) {
                org_setting_set($key, trim((string)($_POST[$key] ?? '')));
            }
            // Klucz tajny — nadpisz tylko, gdy podano nową wartość (puste = zachowaj).
            $sk = trim((string)($_POST['tool_r2_secret_key'] ?? ''));
            if ($sk !== '') {
                org_setting_set('tool_r2_secret_key', $sk);
            }
            $cfg       = r2tool_cfg();
            $cfg_ready = s3_config_ready($cfg);
            $flash     = ['type' => 'success', 'msg' => 'Zapisano konfigurację.'];
        }
    } elseif ($action === 'test') {
        if (!$is_admin) {
            http_response_code(403);
            $flash = ['type' => 'danger', 'msg' => 'Brak uprawnień.'];
        } else {
            $t     = s3_test_connection($cfg);
            $flash = ['type' => $t['ok'] ? 'success' : 'danger', 'msg' => $t['msg']];
        }
    } elseif ($action === 'upload') {
        if (!$cfg_ready) {
            $flash = ['type' => 'danger', 'msg' => 'Magazyn R2 nie jest skonfigurowany. Skontaktuj się z administratorem.'];
        } elseif (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
            $flash = ['type' => 'warning', 'msg' => 'Nie wybrano żadnych plików.'];
        } else {
            $subfolder = r2tool_norm_folder($_POST['subfolder'] ?? '');
            $add_ts    = !empty($_POST['add_timestamp']);

            // Złóż prefiks bazowy: ustawienie prefix + podfolder z formularza.
            $base = trim((string)$cfg['prefix'], '/');
            if ($subfolder !== '') {
                $base = $base === '' ? $subfolder : ($base . '/' . $subfolder);
            }

            $files = $_FILES['files'];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $orig = (string)$files['name'][$i];
                if ($orig === '') continue;

                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $upload_res[] = ['ok' => false, 'name' => $orig, 'msg' => 'Błąd uploadu na serwer (kod ' . $files['error'][$i] . ').', 'url' => ''];
                    continue;
                }

                $tmp  = $files['tmp_name'][$i];
                $body = @file_get_contents($tmp);
                if ($body === false) {
                    $upload_res[] = ['ok' => false, 'name' => $orig, 'msg' => 'Nie można odczytać pliku tymczasowego.', 'url' => ''];
                    continue;
                }

                $safe = r2tool_safe_name($orig);
                if ($add_ts) {
                    $dot  = strrpos($safe, '.');
                    $ts   = date('Ymd-His');
                    $safe = $dot !== false
                        ? substr($safe, 0, $dot) . '-' . $ts . substr($safe, $dot)
                        : $safe . '-' . $ts;
                }
                $key = ($base !== '' ? $base . '/' : '') . $safe;

                $ctype = $files['type'][$i] ?: 'application/octet-stream';
                $res   = s3_put_object($cfg, $key, $body, $ctype);

                $url = '';
                if ($res['ok'] && $cfg['public_base'] !== '') {
                    $url = rtrim($cfg['public_base'], '/') . '/' . $key;
                }
                $upload_res[] = [
                    'ok'   => $res['ok'],
                    'name' => $orig,
                    'msg'  => $res['ok'] ? ('Wysłano → ' . $cfg['bucket'] . '/' . $key) : $res['msg'],
                    'url'  => $url,
                ];
            }

            $ok_n   = count(array_filter($upload_res, fn($r) => $r['ok']));
            $fail_n = count($upload_res) - $ok_n;
            if ($fail_n === 0) {
                $flash = ['type' => 'success', 'msg' => "Wysłano wszystkie pliki ({$ok_n})."];
            } elseif ($ok_n === 0) {
                $flash = ['type' => 'danger', 'msg' => "Nie udało się wysłać żadnego pliku ({$fail_n})."];
            } else {
                $flash = ['type' => 'warning', 'msg' => "Wysłano {$ok_n}, nie udało się {$fail_n}."];
            }
        }
    }
}

/* ── Odpowiedź AJAX (XHR z paskiem postępu) ──────────────────────────────── */
if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['flash' => $flash, 'results' => $upload_res], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Normalizuje podfolder podany przez użytkownika. */
function r2tool_norm_folder(string $f): string {
    $f = str_replace('\\', '/', trim($f));
    $parts = array_filter(array_map('trim', explode('/', $f)), fn($p) => $p !== '' && $p !== '.' && $p !== '..');
    $parts = array_map(fn($p) => preg_replace('/[^A-Za-z0-9_.\-]/u', '_', $p), $parts);
    return implode('/', $parts);
}

/* ── Lista ostatnich obiektów (tylko gdy skonfigurowane) ─────────────────── */
$recent = [];
if ($cfg_ready) {
    $l = s3_list_objects($cfg, trim((string)$cfg['prefix'], '/'), 50);
    if ($l['ok']) {
        $recent = $l['keys'];
        // najnowsze trudno ustalić bez daty — pokaż ostatnie wg klucza
        usort($recent, fn($a, $b) => strcmp($b['key'], $a['key']));
        $recent = array_slice($recent, 0, 25);
    }
}

function r2tool_human_size(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$b;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? $b : number_format($n, 1)) . ' ' . $u[$i];
}

$PAGE_TITLE = 'Upload do R2 (S3)';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-up me-2"></i>Upload plików do Cloudflare R2</h1>
  </div>
  <p class="text-muted">Wysyłanie plików do magazynu obiektowego R2 przez API zgodne z S3 (AWS Signature V4).</p>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($upload_res): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-list-check me-1"></i>Wynik wysyłki</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($upload_res as $r): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="me-2">
              <i class="bi <?= $r['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> me-1"></i>
              <strong><?= h($r['name']) ?></strong>
              <div class="small text-muted"><?= h($r['msg']) ?></div>
            </div>
            <?php if ($r['url'] !== ''): ?>
              <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right"></i> Link
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div id="r2-flash"></div>
  <div id="r2-result"></div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><i class="bi bi-upload me-1"></i>Wyślij pliki</div>
        <div class="card-body">
          <?php if (!$cfg_ready): ?>
            <div class="alert alert-warning mb-0">
              Magazyn R2 nie jest jeszcze skonfigurowany.
              <?php if ($is_admin): ?>Uzupełnij dane w panelu konfiguracji po prawej.<?php else: ?>Skontaktuj się z administratorem.<?php endif; ?>
            </div>
          <?php else: ?>
            <form method="post" enctype="multipart/form-data" id="r2-upload-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload">

              <div class="mb-3">
                <label class="form-label fw-semibold">Pliki</label>
                <input type="file" name="files[]" class="form-control" multiple required>
                <div class="form-text">Można wybrać wiele plików naraz.</div>
              </div>

              <div class="mb-3">
                <label class="form-label">Podfolder (opcjonalnie)</label>
                <input type="text" name="subfolder" class="form-control" placeholder="np. faktury/2026">
                <div class="form-text">
                  Klucz docelowy: <code><?= h(trim((string)$cfg['prefix'], '/') ?: '(brak prefiksu)') ?>/<i>podfolder</i>/<i>nazwa-pliku</i></code>
                </div>
              </div>

              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="add_timestamp" id="add_timestamp" value="1" checked>
                <label class="form-check-label" for="add_timestamp">
                  Dodaj znacznik czasu do nazwy (zapobiega nadpisaniu)
                </label>
              </div>

              <div class="progress mb-3 d-none" id="r2-progress-wrap" style="height:1.4rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated"
                     id="r2-progress-bar" role="progressbar" style="width:0%;"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
              </div>

              <button type="submit" class="btn btn-primary" id="r2-submit-btn">
                <i class="bi bi-cloud-arrow-up me-1"></i>Wyślij do R2
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($cfg_ready): ?>
      <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-archive me-1"></i>Ostatnie obiekty w buckecie</span>
          <span class="badge bg-secondary"><?= count($recent) ?></span>
        </div>
        <?php if ($recent): ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($recent as $obj): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-break me-2"><i class="bi bi-file-earmark me-1 text-muted"></i><?= h($obj['key']) ?></span>
                <span class="badge bg-light text-dark"><?= h(r2tool_human_size($obj['size'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="card-body text-muted small">Brak obiektów lub bucket pusty.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><i class="bi bi-gear me-1"></i>Konfiguracja R2 / S3</div>
        <div class="card-body">
          <?php if (!$is_admin): ?>
            <p class="text-muted mb-2">Konfigurację może zmieniać tylko administrator.</p>
            <ul class="list-unstyled small mb-0">
              <li><strong>Endpoint:</strong> <?= $cfg['endpoint'] !== '' ? h($cfg['endpoint']) : '<span class="text-muted">—</span>' ?></li>
              <li><strong>Bucket:</strong> <?= $cfg['bucket'] !== '' ? h($cfg['bucket']) : '<span class="text-muted">—</span>' ?></li>
              <li><strong>Status:</strong> <?= $cfg_ready ? '<span class="text-success">skonfigurowany</span>' : '<span class="text-danger">brak konfiguracji</span>' ?></li>
            </ul>
          <?php else: ?>
            <form method="post" class="mb-3">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_settings">

              <div class="mb-2">
                <label class="form-label">Cloudflare Account ID</label>
                <input type="text" name="tool_r2_account_id" class="form-control form-control-sm" value="<?= h($cfg['account_id']) ?>" placeholder="np. a1b2c3...">
                <div class="form-text">Na jego podstawie zbudowany zostanie endpoint R2, jeśli pole poniżej zostawisz puste.</div>
              </div>

              <div class="mb-2">
                <label class="form-label">Endpoint (opcjonalnie — nadpisuje)</label>
                <input type="text" name="tool_r2_endpoint" class="form-control form-control-sm" value="<?= h(org_setting('tool_r2_endpoint')) ?>" placeholder="https://&lt;acc&gt;.r2.cloudflarestorage.com">
              </div>

              <div class="row g-2">
                <div class="col-7 mb-2">
                  <label class="form-label">Bucket</label>
                  <input type="text" name="tool_r2_bucket" class="form-control form-control-sm" value="<?= h($cfg['bucket']) ?>" required>
                </div>
                <div class="col-5 mb-2">
                  <label class="form-label">Region</label>
                  <input type="text" name="tool_r2_region" class="form-control form-control-sm" value="<?= h($cfg['region']) ?>" placeholder="auto">
                </div>
              </div>

              <div class="mb-2">
                <label class="form-label">Access Key ID</label>
                <input type="text" name="tool_r2_access_key" class="form-control form-control-sm" value="<?= h($cfg['access_key']) ?>" autocomplete="off">
              </div>

              <div class="mb-2">
                <label class="form-label">Secret Access Key</label>
                <input type="password" name="tool_r2_secret_key" class="form-control form-control-sm" value="" autocomplete="off" placeholder="<?= $cfg['secret_key'] !== '' ? '•••••• (zostaw puste, by nie zmieniać)' : '' ?>">
              </div>

              <div class="mb-2">
                <label class="form-label">Prefiks bazowy (opcjonalnie)</label>
                <input type="text" name="tool_r2_prefix" class="form-control form-control-sm" value="<?= h($cfg['prefix']) ?>" placeholder="np. uploads">
              </div>

              <div class="mb-3">
                <label class="form-label">Publiczny adres bazowy (opcjonalnie)</label>
                <input type="text" name="tool_r2_public_base" class="form-control form-control-sm" value="<?= h($cfg['public_base']) ?>" placeholder="https://pub-xxxx.r2.dev lub domena">
                <div class="form-text">Do generowania klikalnych linków po wysłaniu.</div>
              </div>

              <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
            </form>

            <form method="post" class="border-top pt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="test">
              <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $cfg_ready ? '' : 'disabled' ?>>
                <i class="bi bi-plug me-1"></i>Testuj połączenie
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('r2-upload-form');
  if (!form || !window.FormData || !window.XMLHttpRequest) return; // fallback: zwykły POST

  var wrap   = document.getElementById('r2-progress-wrap');
  var bar    = document.getElementById('r2-progress-bar');
  var btn    = document.getElementById('r2-submit-btn');
  var flashE = document.getElementById('r2-flash');
  var resE   = document.getElementById('r2-result');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function setProgress(pct) {
    pct = Math.max(0, Math.min(100, Math.round(pct)));
    bar.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', pct);
    bar.textContent = pct + '%';
  }

  function renderResults(data) {
    flashE.innerHTML = '';
    resE.innerHTML = '';
    if (data.flash) {
      flashE.innerHTML = '<div class="alert alert-' + esc(data.flash.type) + '">' +
        esc(data.flash.msg) + '</div>';
    }
    var rows = data.results || [];
    if (!rows.length) return;
    var html = '<div class="card mb-4"><div class="card-header">' +
      '<i class="bi bi-list-check me-1"></i>Wynik wysyłki</div>' +
      '<ul class="list-group list-group-flush">';
    rows.forEach(function (r) {
      var icon = r.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger';
      var link = r.url ? '<a href="' + esc(r.url) + '" target="_blank" rel="noopener" ' +
        'class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Link</a>' : '';
      html += '<li class="list-group-item d-flex justify-content-between align-items-start">' +
        '<div class="me-2"><i class="bi ' + icon + ' me-1"></i><strong>' + esc(r.name) + '</strong>' +
        '<div class="small text-muted">' + esc(r.msg) + '</div></div>' + link + '</li>';
    });
    html += '</ul></div>';
    resE.innerHTML = html;
  }

  form.addEventListener('submit', function (e) {
    var fileInput = form.querySelector('input[type=file]');
    if (!fileInput || !fileInput.files.length) return; // brak plików — pozwól na natywną walidację
    e.preventDefault();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || (window.location.pathname + '?_ajax=1'));
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    btn.disabled = true;
    wrap.classList.remove('d-none');
    bar.classList.add('progress-bar-animated');
    setProgress(0);
    flashE.innerHTML = '';
    resE.innerHTML = '';

    xhr.upload.addEventListener('progress', function (ev) {
      if (ev.lengthComputable) setProgress(ev.loaded / ev.total * 100);
    });

    xhr.addEventListener('load', function () {
      setProgress(100);
      bar.classList.remove('progress-bar-animated');
      btn.disabled = false;
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (_) {}
      if (xhr.status >= 200 && xhr.status < 300 && data) {
        renderResults(data);
        form.reset();
      } else {
        flashE.innerHTML = '<div class="alert alert-danger">Błąd wysyłki (HTTP ' +
          xhr.status + '). Spróbuj ponownie.</div>';
      }
      setTimeout(function () { wrap.classList.add('d-none'); setProgress(0); }, 1200);
    });

    xhr.addEventListener('error', function () {
      btn.disabled = false;
      bar.classList.remove('progress-bar-animated');
      flashE.innerHTML = '<div class="alert alert-danger">Błąd sieci podczas wysyłki.</div>';
    });

    xhr.send(new FormData(form));
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
