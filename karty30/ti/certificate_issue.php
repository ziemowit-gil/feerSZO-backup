<?php
/**
 * karty30/ti/certificate_issue.php — Wystawianie/odwoływanie certyfikatów X.509
 * dla kursantów kursów TI (rodzaj zajęć z requires_certificate=1).
 * Dostęp: tylko administrator. Backend: EJBCA przez SOAP WS (includes/ejbca.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ejbca.php';

k30_require_access();
karty30_migrate();

if (!is_admin()) { http_response_code(403); die('Tylko administrator.'); }

$PAGE_TITLE = 'Certyfikaty X.509 — TI';

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op        = $_POST['_op'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);

    // Wystaw certyfikat
    if ($op === 'issue' && $course_id && $client_id) {
        if (!ejbca_enabled()) {
            flash_set('danger', 'EJBCA nie jest skonfigurowane lub wyłączone.');
            header('Location: certificate_issue.php?course_id=' . $course_id); exit;
        }

        $existing = db_one("SELECT id FROM k30_ti_certs WHERE course_id=? AND client_id=? AND revoked_at IS NULL", [$course_id, $client_id]);
        if ($existing) {
            flash_set('warning', 'Kursant ma już aktywny certyfikat dla tego kursu.');
            header('Location: certificate_issue.php?course_id=' . $course_id); exit;
        }

        $course = db_one("SELECT c.*, cl.name AS client_name, st.name AS subject_name FROM k30_ti_courses c JOIN k30_clients cl ON cl.id=? LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id WHERE c.id=?", [$client_id, $course_id]);
        $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
        if (!$course || !$client) { flash_set('danger', 'Nie znaleziono kursu lub kursanta.'); header('Location: certificate_issue.php?course_id=' . $course_id); exit; }

        $ejbca_username = 'ti-' . $course_id . '-' . $client_id . '-' . time();
        $p12_password   = bin2hex(random_bytes(8));
        $cn             = preg_replace('/[^a-zA-Z0-9 \-]/', '', $client['name'] ?? 'Kursant');
        $org            = preg_replace('/[^a-zA-Z0-9 \-]/', '', $course['subject_name'] ?? $course['name'] ?? 'TI');

        try {
            $cert = ejbca_issue_login_cert($ejbca_username, $cn, $p12_password, $org);
        } catch (\Throwable $e) {
            flash_set('danger', 'Błąd EJBCA: ' . $e->getMessage());
            header('Location: certificate_issue.php?course_id=' . $course_id); exit;
        }

        db_insert('k30_ti_certs', [
            'course_id'      => $course_id,
            'client_id'      => $client_id,
            'ejbca_username' => $ejbca_username,
            'fingerprint'    => $cert['fingerprint'],
            'serial_hex'     => $cert['serial_hex'],
            'valid_from'     => $cert['valid_from'],
            'valid_to'       => $cert['valid_to'],
            'issued_by'      => current_user()['id'] ?? null,
            'issued_at'      => date('Y-m-d H:i:s'),
        ]);

        // Pobierz PKCS#12 — przekaż do przeglądarki z hasłem w nagłówku
        $safe_name = preg_replace('/[^a-z0-9_-]/i', '_', $cn);
        session_write_close();
        header('Content-Type: application/x-pkcs12');
        header('Content-Disposition: attachment; filename="cert_ti_' . $safe_name . '.p12"');
        header('X-Cert-Password: ' . $p12_password);
        echo $cert['p12_data'];
        exit;
    }

    // Odwołaj certyfikat
    if ($op === 'revoke') {
        $cert_id = (int)($_POST['cert_id'] ?? 0);
        $row = $cert_id ? db_one("SELECT * FROM k30_ti_certs WHERE id=?", [$cert_id]) : null;
        if ($row && !$row['revoked_at']) {
            try {
                ejbca_revoke_user($row['ejbca_username']);
            } catch (\Throwable $e) {
                flash_set('warning', 'Błąd odwołania w EJBCA: ' . $e->getMessage() . ' — oznaczono jako odwołany lokalnie.');
            }
            db()->prepare("UPDATE k30_ti_certs SET revoked_at=datetime('now'), revoked_by=? WHERE id=?")
               ->execute([current_user()['id'] ?? null, $cert_id]);
            flash_set('success', 'Certyfikat odwołany.');
        }
        header('Location: certificate_issue.php?course_id=' . ($row['course_id'] ?? '')); exit;
    }

    header('Location: certificate_issue.php'); exit;
}

// ── Widok ─────────────────────────────────────────────────────────────────────
$course_id = (int)($_GET['course_id'] ?? 0);

if ($course_id) {
    $course = db_one(
        "SELECT c.*, st.name AS subject_name, st.abbreviation AS subject_abbr, st.requires_certificate,
                u.name AS instructor_name
         FROM k30_ti_courses c
         LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE c.id=?", [$course_id]
    );
    if (!$course) { http_response_code(404); die('Kurs nie istnieje.'); }

    // Kursanci aktywni w kursie
    $students = db_all(
        "SELECT e.client_id, cl.name AS client_name, cl.email,
                cert.id AS cert_id, cert.fingerprint, cert.serial_hex, cert.valid_from, cert.valid_to,
                cert.issued_at, cert.revoked_at,
                u.name AS issued_by_name
         FROM k30_ti_enrollments e
         JOIN k30_clients cl ON cl.id=e.client_id
         LEFT JOIN k30_ti_certs cert ON cert.course_id=e.course_id AND cert.client_id=e.client_id AND cert.revoked_at IS NULL
         LEFT JOIN users u ON u.id=cert.issued_by
         WHERE e.course_id=? AND e.status='active'
         ORDER BY cl.name", [$course_id]
    );

    // Historia odwołanych certów
    $revoked = db_all(
        "SELECT cert.*, cl.name AS client_name, u.name AS issued_by_name, rv.name AS revoked_by_name
         FROM k30_ti_certs cert
         JOIN k30_clients cl ON cl.id=cert.client_id
         LEFT JOIN users u ON u.id=cert.issued_by
         LEFT JOIN users rv ON rv.id=cert.revoked_by
         WHERE cert.course_id=? AND cert.revoked_at IS NOT NULL
         ORDER BY cert.revoked_at DESC", [$course_id]
    );
}

// Lista kursów z requires_certificate=1
$cert_courses = db_all(
    "SELECT c.id, c.name, st.abbreviation AS subject_abbr, st.name AS subject_name,
            (SELECT COUNT(*) FROM k30_ti_certs cert WHERE cert.course_id=c.id AND cert.revoked_at IS NULL) AS active_certs
     FROM k30_ti_courses c
     JOIN k30_ti_subject_types st ON st.id=c.subject_type_id AND st.requires_certificate=1
     WHERE c.status != 'cancelled'
     ORDER BY c.name"
);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Certyfikaty X.509</li>
</ol></nav>

<div class="d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-patch-check-fill fs-4 text-success"></i>
  <h4 class="mb-0 fw-bold">Certyfikaty X.509 — Zajęcia TI</h4>
  <?php if (!ejbca_enabled()): ?>
  <span class="badge bg-warning text-dark ms-2"><i class="bi bi-exclamation-triangle me-1"></i>EJBCA nieaktywne</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$course_id): ?>
<!-- Lista kursów z certyfikatami -->
<?php if (!$cert_courses): ?>
<div class="alert alert-info">
  Brak kursów z włączoną flagą „Wymagany certyfikat". Ustaw ją w
  <a href="subject_types.php">Rodzajach zajęć</a>.
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-list-ul me-2"></i>Kursy z certyfikatem X.509</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Kurs</th>
          <th>Rodzaj zajęć</th>
          <th class="text-center">Aktywne certy</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cert_courses as $cc): ?>
        <tr>
          <td class="fw-semibold"><?= h($cc['name']) ?></td>
          <td><span class="badge text-bg-primary font-monospace"><?= h($cc['subject_abbr']) ?></span> <?= h($cc['subject_name']) ?></td>
          <td class="text-center">
            <?php if ($cc['active_certs'] > 0): ?>
            <span class="badge bg-success"><?= (int)$cc['active_certs'] ?></span>
            <?php else: ?>
            <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="?course_id=<?= (int)$cc['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-patch-check me-1"></i>Zarządzaj
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Widok kursu -->
<div class="mb-3">
  <a href="certificate_issue.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Wszystkie kursy
  </a>
  <a href="course.php?id=<?= $course_id ?>" class="btn btn-outline-secondary btn-sm ms-1">
    <i class="bi bi-easel me-1"></i>Karta kursu
  </a>
</div>

<div class="card border-0 shadow-sm mb-2 p-3">
  <div class="fw-bold fs-5"><?= h($course['name']) ?></div>
  <div class="text-muted small">
    <?php if ($course['subject_name']): ?>
    <span class="badge text-bg-primary font-monospace me-1"><?= h($course['subject_abbr']) ?></span><?= h($course['subject_name']) ?>
    <?php endif; ?>
    <?php if ($course['instructor_name']): ?>
    &nbsp;·&nbsp;<i class="bi bi-person me-1"></i><?= h($course['instructor_name']) ?>
    <?php endif; ?>
  </div>
</div>

<!-- Kursanci -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-people me-2"></i>Kursanci — certyfikaty</div>
  <?php if (!$students): ?>
  <div class="card-body text-muted small">Brak aktywnych kursantów w tym kursie.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Kursant</th>
          <th>Status certyfikatu</th>
          <th>Ważny do</th>
          <th>Wystawiony przez</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($s['client_name']) ?></div>
            <div class="text-muted small"><?= h($s['email'] ?? '') ?></div>
          </td>
          <td>
            <?php if ($s['cert_id']): ?>
            <span class="badge bg-success"><i class="bi bi-patch-check me-1"></i>Aktywny</span>
            <div class="text-muted font-monospace" style="font-size:.7rem"><?= h(substr($s['fingerprint'], 0, 20)) ?>…</div>
            <?php else: ?>
            <span class="badge bg-secondary">Brak</span>
            <?php endif; ?>
          </td>
          <td class="text-muted small">
            <?= $s['valid_to'] ? date('d.m.Y', strtotime($s['valid_to'])) : '—' ?>
          </td>
          <td class="text-muted small">
            <?= h($s['issued_by_name'] ?? '—') ?>
            <?php if ($s['issued_at']): ?>
            <div><?= date('d.m.Y', strtotime($s['issued_at'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($s['cert_id']): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Odwołać certyfikat kursanta <?= h(addslashes($s['client_name'])) ?>?')">
              <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"      value="revoke">
              <input type="hidden" name="cert_id"  value="<?= (int)$s['cert_id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                <i class="bi bi-x-circle me-1"></i>Odwołaj
              </button>
            </form>
            <?php elseif (ejbca_enabled()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Wystawić certyfikat X.509 dla <?= h(addslashes($s['client_name'])) ?>?\nPlik .p12 zostanie pobrany — zachowaj hasło z nagłówka X-Cert-Password.')">
              <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"        value="issue">
              <input type="hidden" name="course_id"  value="<?= $course_id ?>">
              <input type="hidden" name="client_id"  value="<?= (int)$s['client_id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2">
                <i class="bi bi-patch-check me-1"></i>Wystaw cert
              </button>
            </form>
            <?php else: ?>
            <span class="text-muted small">EJBCA off</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Historia odwołanych -->
<?php if ($revoked): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold text-muted"><i class="bi bi-archive me-2"></i>Odwołane certyfikaty</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 opacity-75">
      <thead class="table-light">
        <tr><th>Kursant</th><th>S/N</th><th>Wystawiony</th><th>Odwołany</th><th>Przez</th></tr>
      </thead>
      <tbody>
        <?php foreach ($revoked as $rv): ?>
        <tr>
          <td><?= h($rv['client_name']) ?></td>
          <td class="font-monospace small"><?= h(substr($rv['serial_hex'], 0, 16)) ?>…</td>
          <td class="small"><?= date('d.m.Y', strtotime($rv['issued_at'])) ?></td>
          <td class="small"><?= date('d.m.Y', strtotime($rv['revoked_at'])) ?></td>
          <td class="small text-muted"><?= h($rv['revoked_by_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
