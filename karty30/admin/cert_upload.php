<?php
/**
 * karty30/admin/cert_upload.php — Zarządzanie certyfikatami x509 doradców.
 *
 * Administrator wgrywa lub usuwa certyfikat PEM dla konkretnego doradcy.
 * Certyfikat jest wymagany do zatwierdzania kart konsultacji.
 *
 * Dostępność WCAG 2.1 AA — pełna obsługa czytnikiem ekranu.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zarządzać certyfikatami.');
    header('Location: ' . APP_URL . '/karty30/index.php');
    exit;
}

$PAGE_TITLE = 'Certyfikaty x509 doradców — Karty 30';

// ── POST ─────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['_action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // Usuń certyfikat
    if ($action === 'delete') {
        $user = db_one("SELECT id, name FROM users WHERE id=?", [$user_id]);
        if ($user) {
            db()->prepare("DELETE FROM k30_consultant_certs WHERE user_id=?")->execute([$user_id]);
            flash_set('success', 'Certyfikat usunięty dla: ' . ($user['name'] ?? ''));
        }
        header('Location: cert_upload.php'); exit;
    }

    // Dezaktywuj / aktywuj
    if ($action === 'toggle') {
        $cert = db_one("SELECT * FROM k30_consultant_certs WHERE user_id=?", [$user_id]);
        if ($cert) {
            $new_val = $cert['is_active'] ? 0 : 1;
            db()->prepare("UPDATE k30_consultant_certs SET is_active=? WHERE user_id=?")
                ->execute([$new_val, $user_id]);
            flash_set('success', $new_val ? 'Certyfikat aktywowany.' : 'Certyfikat dezaktywowany.');
        }
        header('Location: cert_upload.php'); exit;
    }

    // Wgraj nowy certyfikat
    if ($action === 'upload') {
        if (!$user_id) {
            $errors[] = 'Wybierz doradcę.';
        }

        // Certyfikat z pliku lub wklejony
        $pem = '';
        if (!empty($_FILES['cert_file']['tmp_name']) && $_FILES['cert_file']['error'] === UPLOAD_ERR_OK) {
            $pem = file_get_contents($_FILES['cert_file']['tmp_name']);
        } elseif (!empty($_POST['cert_pem'])) {
            $pem = trim($_POST['cert_pem']);
        }

        if (!$pem) {
            $errors[] = 'Podaj certyfikat — wgraj plik PEM lub wklej jego treść.';
        }

        if (!$errors) {
            // Sprawdź format — wymagamy PEM
            if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
                // Spróbuj DER → PEM
                $res = @openssl_x509_read($pem);
                if (!$res) {
                    $errors[] = 'Nieprawidłowy format certyfikatu. Oczekiwany PEM (-----BEGIN CERTIFICATE-----)';
                }
            }
        }

        if (!$errors) {
            $parsed = k30_parse_cert($pem);
            if (!$parsed) {
                $errors[] = 'Nie można odczytać certyfikatu. Upewnij się, że jest to prawidłowy certyfikat x509.';
            } else {
                // Sprawdź datę ważności
                if ($parsed['valid_to'] < time()) {
                    $errors[] = 'Certyfikat wygasł ' . date('d.m.Y', $parsed['valid_to']) . '. Wgraj aktualny certyfikat.';
                }
                if ($parsed['valid_from'] > time() + 86400) {
                    $errors[] = 'Certyfikat nie jest jeszcze ważny (od: ' . date('d.m.Y', $parsed['valid_from']) . ').';
                }
            }
        }

        if (!$errors) {
            // Zapisz lub zaktualizuj
            $existing = db_one("SELECT id FROM k30_consultant_certs WHERE user_id=?", [$user_id]);
            $uid_admin = (int)(current_user()['id'] ?? 0);
            $data = [
                'cert_pem'        => $pem,
                'cert_subject'    => $parsed['subject'],
                'cert_fingerprint'=> $parsed['fingerprint'],
                'cert_serial'     => $parsed['serial'],
                'cert_valid_from' => $parsed['valid_from'],
                'cert_valid_to'   => $parsed['valid_to'],
                'is_active'       => 1,
                'uploaded_by'     => $uid_admin,
                'uploaded_at'     => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                db_update('k30_consultant_certs', $data, $existing['id']);
                flash_set('success', 'Certyfikat zaktualizowany. Ważny do: ' . date('d.m.Y', $parsed['valid_to']));
            } else {
                $data['user_id'] = $user_id;
                db_insert('k30_consultant_certs', $data);
                flash_set('success', 'Certyfikat wgrany. Ważny do: ' . date('d.m.Y', $parsed['valid_to']));
            }
            header('Location: cert_upload.php'); exit;
        }
    }
}

// ── Pobierz dane ─────────────────────────────────────────────────────────────
$consultants = db_all(
    "SELECT u.id,
            CASE WHEN u.first_name != '' AND u.last_name != ''
                 THEN u.first_name || ' ' || u.last_name ELSE u.name END AS display_name,
            u.email, u.role,
            c.cert_subject, c.cert_fingerprint, c.cert_valid_from, c.cert_valid_to,
            c.is_active AS cert_active, c.uploaded_at,
            uc.name AS uploaded_by_name
     FROM users u
     LEFT JOIN k30_consultant_certs c ON c.user_id=u.id
     LEFT JOIN users uc ON uc.id=c.uploaded_by
     WHERE u.is_active=1 AND u.k30_consultant=1
     ORDER BY display_name"
);

$all_consultants_for_select = $consultants; // dla selecta w formularzu

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka</a></li>
    <li class="breadcrumb-item"><a href="consultants.php">Doradcy</a></li>
    <li class="breadcrumb-item active" aria-current="page">Certyfikaty x509</li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Certyfikaty x509 doradców</h1>
    <p class="text-body-secondary mb-0">
      Każdy doradca musi mieć wgrany ważny certyfikat x509 (format PEM),
      aby móc zatwierdzać karty konsultacji. Certyfikat jest weryfikowany
      razem z kodem IKA przy każdym zatwierdzeniu.
    </p>
  </div>
</div>

<!-- Wyjaśnienie procesu weryfikacji -->
<div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="note" aria-label="Informacja o procesie zatwierdzania">
  <i class="bi bi-shield-lock-fill" aria-hidden="true" style="font-size:1.3rem;flex-shrink:0;margin-top:.1rem;color:#1D4ED8"></i>
  <div>
    <strong>Proces zatwierdzania konsultacji wymaga dwóch czynników:</strong>
    <ol class="mb-0 mt-1">
      <li><strong>Kod IKA</strong> — 6-cyfrowy Indywidualny Kod Autoryzacyjny przypisany do konta</li>
      <li><strong>Certyfikat x509</strong> — ważny certyfikat cyfrowy wgrany przez administratora</li>
    </ol>
    Fingerprint certyfikatu jest dołączany do podpisanych danych konsultacji (SHA1).
  </div>
</div>

<!-- Błędy -->
<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-4" role="alert" aria-label="Błędy formularza">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0"></i>
  <div>
    <strong>Nie można wgrać certyfikatu:</strong>
    <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
</div>
<?php endif; ?>

<!-- ═══ LISTA CERTYFIKATÓW ═════════════════════════════════════════════════ -->
<section aria-labelledby="cert-list-heading" class="mb-5">
  <h2 id="cert-list-heading" style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;border-bottom:2px solid #E5E7EB;padding-bottom:.5rem">
    <i class="bi bi-patch-check-fill me-2" aria-hidden="true" style="color:#5B21B6"></i>
    Stan certyfikatów doradców
  </h2>

  <?php if ($consultants): ?>
  <div class="card shadow-sm">
    <table class="table table-hover align-middle" aria-label="Certyfikaty x509 doradców">
      <thead>
        <tr>
          <th scope="col">Doradca</th>
          <th scope="col">Status certyfikatu</th>
          <th scope="col">Podmiot (Subject)</th>
          <th scope="col">Ważny do</th>
          <th scope="col">Fingerprint SHA1</th>
          <th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($consultants as $c):
          $has_cert   = !empty($c['cert_fingerprint']);
          $is_expired = $has_cert && $c['cert_valid_to'] < time();
          $is_active  = $has_cert && $c['cert_active'];
          $days_left  = $has_cert ? (int)(($c['cert_valid_to'] - time()) / 86400) : 0;
        ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($c['display_name']) ?></div>
            <div style="font-size:.8rem;color:#6B7280"><?= h($c['email']) ?></div>
          </td>
          <td>
            <?php if (!$has_cert): ?>
              <span class="badge text-bg-danger d-inline-flex align-items-center gap-1">
                <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                Brak certyfikatu
              </span>
            <?php elseif ($is_expired): ?>
              <span class="badge text-bg-warning d-inline-flex align-items-center gap-1">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                Wygasł
              </span>
            <?php elseif (!$is_active): ?>
              <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1">
                <i class="bi bi-pause-circle-fill" aria-hidden="true"></i>
                Dezaktywowany
              </span>
            <?php elseif ($days_left < 30): ?>
              <span class="badge text-bg-warning d-inline-flex align-items-center gap-1">
                <i class="bi bi-clock-fill" aria-hidden="true"></i>
                Wygasa za <?= $days_left ?> dni
              </span>
            <?php else: ?>
              <span class="badge text-bg-success d-inline-flex align-items-center gap-1">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                Ważny (<?= $days_left ?> dni)
              </span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($c['cert_subject'] ?: '—') ?>
          </td>
          <td style="font-size:.82rem">
            <?= $has_cert ? date('d.m.Y', (int)$c['cert_valid_to']) : '—' ?>
          </td>
          <td>
            <?php if ($c['cert_fingerprint']): ?>
            <code style="font-size:.72rem;word-break:break-all">
              <?= h(substr($c['cert_fingerprint'], 0, 29)) ?>…
            </code>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end" style="white-space:nowrap">
            <div class="d-flex gap-1 justify-content-end">
              <?php if ($has_cert): ?>
              <!-- Aktywuj/dezaktywuj -->
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="toggle">
                <input type="hidden" name="user_id"  value="<?= (int)$c['id'] ?>">
                <button type="submit"
                        class="btn btn-sm <?= $is_active ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        aria-label="<?= $is_active ? 'Dezaktywuj' : 'Aktywuj' ?> certyfikat doradcy <?= h($c['display_name']) ?>">
                  <?= $is_active ? 'Dezaktywuj' : 'Aktywuj' ?>
                </button>
              </form>
              <!-- Usuń -->
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć certyfikat doradcy <?= h(addslashes($c['display_name'])) ?>? Doradca nie będzie mógł zatwierdzać konsultacji.')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>">
                <button type="submit"
                        class="btn btn-sm btn-outline-danger"
                        aria-label="Usuń certyfikat doradcy <?= h($c['display_name']) ?>">
                  Usuń
                </button>
              </form>
              <?php endif; ?>
              <!-- Wgraj nowy (link do sekcji uploadu z prefill) -->
              <a href="#upload-section"
                 class="btn btn-sm btn-outline-primary"
                 aria-label="Wgraj lub zastąp certyfikat doradcy <?= h($c['display_name']) ?>"
                 onclick="document.getElementById('upload_user_id').value='<?= (int)$c['id'] ?>';document.getElementById('upload_user_id').dispatchEvent(new Event('change'))">
                <?= $has_cert ? 'Zastąp' : 'Wgraj' ?>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body text-center py-5">
      <i class="bi bi-people" aria-hidden="true" style="font-size:2rem;color:#D1D5DB;display:block;margin-bottom:.75rem"></i>
      <p class="text-muted mb-0">
        Brak doradców. Najpierw przyznaj uprawnienie doradcy w
        <a href="consultants.php">panelu doradców</a>.
      </p>
    </div>
  </div>
  <?php endif; ?>
</section>

<!-- ═══ WGRAJ CERTYFIKAT ════════════════════════════════════════════════════ -->
<section aria-labelledby="upload-heading" id="upload-section">
  <h2 id="upload-heading" style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;border-bottom:2px solid #E5E7EB;padding-bottom:.5rem">
    <i class="bi bi-upload me-2" aria-hidden="true" style="color:#5B21B6"></i>
    Wgraj certyfikat x509
  </h2>

  <div class="card shadow-sm">
    <div class="card-body" style="max-width:620px">

      <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="note">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="font-size:1.1rem;flex-shrink:0"></i>
        <div style="font-size:.88rem">
          <strong>Wymagany format:</strong> PEM (<code>-----BEGIN CERTIFICATE-----</code>).<br>
          Certyfikat musi być ważny, nieodwołany i zgodny z tożsamością doradcy.
          Nie przesyłaj klucza prywatnego — tylko certyfikat publiczny.
        </div>
      </div>

      <form method="post" enctype="multipart/form-data"
            aria-label="Formularz wgrywania certyfikatu x509"
            novalidate>
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="upload">

        <!-- Wybór doradcy -->
        <div class="mb-4">
          <label class="form-label" for="upload_user_id">
            Doradca <span aria-hidden="true" style="color:#DC2626">*</span>
            <span class="visually-hidden">(wymagany)</span>
          </label>
          <select name="user_id" id="upload_user_id"
                  class="form-select"
                  required aria-required="true"
                  aria-describedby="hint_user">
            <option value="">— Wybierz doradcę —</option>
            <?php foreach ($all_consultants_for_select as $u): ?>
            <option value="<?= (int)$u['id'] ?>">
              <?= h($u['display_name']) ?>
              <?php if ($u['cert_fingerprint']): ?>
              — ma certyfikat (ważny do <?= date('d.m.Y', (int)$u['cert_valid_to']) ?>)
              <?php else: ?>
              — brak certyfikatu
              <?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="hint_user" class="form-text">
            Wybierz doradcę, dla którego wgrywasz certyfikat. Jeśli ma już certyfikat, zostanie zastąpiony.
          </div>
        </div>

        <!-- Plik PEM -->
        <fieldset class="mb-4">
          <legend class="fw-bold mb-2" style="font-size:.95rem">
            Certyfikat PEM — plik lub tekst (wymagane jedno z nich)
          </legend>

          <div class="mb-3">
            <label class="form-label" for="cert_file">
              <i class="bi bi-file-earmark-lock2" aria-hidden="true"></i>
              Plik certyfikatu (.pem, .crt, .cer)
            </label>
            <input type="file" name="cert_file" id="cert_file"
                   class="form-control"
                   accept=".pem,.crt,.cer,.der"
                   aria-describedby="hint_file">
            <div id="hint_file" class="form-text">
              Format PEM (text) lub DER (binary). Rozmiar maks. 64 KB.
            </div>
          </div>

          <div style="text-align:center;font-weight:600;color:#6B7280;margin-bottom:.75rem">
            lub wklej poniżej
          </div>

          <div>
            <label class="form-label" for="cert_pem">
              Treść certyfikatu PEM
            </label>
            <textarea name="cert_pem" id="cert_pem"
                      class="form-control"
                      rows="8"
                      style="font-family:monospace;font-size:.8rem"
                      placeholder="-----BEGIN CERTIFICATE-----&#10;MIIDuTCCAqGg...&#10;-----END CERTIFICATE-----"
                      aria-describedby="hint_pem"
                      spellcheck="false"
                      autocomplete="off"></textarea>
            <div id="hint_pem" class="form-text">
              Wklej pełną treść certyfikatu w formacie PEM, wraz z nagłówkami BEGIN/END CERTIFICATE.
            </div>
          </div>
        </fieldset>

        <!-- Przyciski -->
        <div class="d-flex gap-3 flex-wrap">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload me-2" aria-hidden="true"></i>Wgraj i zapisz certyfikat
          </button>
          <a href="consultants.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Wróć do listy doradców
          </a>
        </div>
      </form>
    </div>
  </div>
</section>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
