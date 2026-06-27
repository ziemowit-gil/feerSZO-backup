<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/api_auth.php';

require_role('admin');
api_auth_migrate();

$PAGE_TITLE = 'Klucze API';

// All available permissions
const API_PERMISSIONS = [
    'volunteers:read' => 'Wolontariusze — odczyt',
    'contracts:read'  => 'Umowy — odczyt',
    'tasks:read'      => 'Zadania — odczyt',
    'users:read'      => 'Użytkownicy — odczyt',
    'crm:read'        => 'CRM — odczyt kontaktów',
    'crm:write'       => 'CRM — zapis kontaktów (twórz / edytuj / usuń)',
    'karty30:read'    => 'Karty 30 — odczyt (beneficjenci, wizyty, konsultacje, TI)',
    'karty30:write'   => 'Karty 30 — zapis (twórz / edytuj / usuń)',
];

$errors    = [];
$new_plain = null; // shown once after creation

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Create new key ────────────────────────────────────────────────────
    if ($action === 'create') {
        $name = trim($_POST['key_name'] ?? '');
        if ($name === '') {
            $errors[] = 'Podaj nazwę klucza.';
        }

        $perms = [];
        foreach (array_keys(API_PERMISSIONS) as $p) {
            if (!empty($_POST['perm_' . str_replace(':', '_', $p)])) {
                $perms[] = $p;
            }
        }
        if (empty($perms)) {
            $errors[] = 'Wybierz co najmniej jedno uprawnienie.';
        }

        if (!$errors) {
            $plain    = bin2hex(random_bytes(32)); // 64-char hex key
            $hash     = hash('sha256', $plain);
            $user     = current_user();
            $created  = date('Y-m-d H:i:s');

            db_insert('api_keys', [
                'key_hash'    => $hash,
                'name'        => $name,
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'created_at'  => $created,
                'is_active'   => 1,
                'created_by'  => $user['id'] ?? null,
            ]);

            $new_plain = $plain;
            flash_set('success', 'Klucz API "' . $name . '" został utworzony.');
        }
    }

    // ── Revoke key ────────────────────────────────────────────────────────
    if ($action === 'revoke') {
        $id = (int)($_POST['key_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE api_keys SET is_active = 0 WHERE id = ?")
                ->execute([$id]);
            flash_set('success', 'Klucz API został unieważniony.');
            header('Location: ' . APP_URL . '/admin/api_keys.php');
            exit;
        }
    }
}

// ── Load all keys ──────────────────────────────────────────────────────────
$keys = db_all(
    "SELECT id, name, permissions, last_used_at, created_at, is_active, created_by
       FROM api_keys
      ORDER BY created_at DESC"
);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-key me-2"></i><?= h($PAGE_TITLE) ?></h4>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($new_plain): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Zapisz klucz &mdash; nie zostanie pokazany ponownie:</strong>
    <div class="mt-2">
      <code class="user-select-all fs-6 d-block p-2 bg-white border rounded"><?= h($new_plain) ?></code>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
<?php endif; ?>

<?php
// ── Existing keys table ────────────────────────────────────────────────────
if ($keys):
?>
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Istniejące klucze</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Nazwa</th>
          <th>Uprawnienia</th>
          <th>Ostatnie użycie</th>
          <th>Utworzony</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($keys as $k): ?>
          <?php
            $perms_arr = json_decode($k['permissions'] ?? '[]', true);
            if (!is_array($perms_arr)) $perms_arr = [];
          ?>
          <tr>
            <td class="fw-semibold"><?= h($k['name']) ?></td>
            <td>
              <?php if ($perms_arr): ?>
                <?php foreach ($perms_arr as $p): ?>
                  <span class="badge bg-secondary me-1"><?= h($p) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">brak</span>
              <?php endif; ?>
            </td>
            <td><?= $k['last_used_at'] ? h(date_pl($k['last_used_at'])) : '<span class="text-muted">nigdy</span>' ?></td>
            <td><?= h(date_pl($k['created_at'])) ?></td>
            <td>
              <?php if ($k['is_active']): ?>
                <span class="badge bg-success">Aktywny</span>
              <?php else: ?>
                <span class="badge bg-secondary">Unieważniony</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($k['is_active']): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Czy na pewno chcesz unieważnić ten klucz?')">
                  <?= csrf_token() ?>
                  <input type="hidden" name="action"   value="revoke">
                  <input type="hidden" name="key_id"   value="<?= (int)$k['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-slash-circle me-1"></i>Unieważnij
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
// ── Dokumentacja CRM API ─────────────────────────────────────────────────────
$api_base = rtrim(APP_URL, '/') . '/api/v1';
?>
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-book me-2"></i>Dokumentacja — CRM API</span>
    <code class="small text-muted"><?= h($api_base) ?>/crm.php</code>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Uwierzytelnianie: nagłówek <code>Authorization: Bearer &lt;klucz&gt;</code>
      (lub parametr <code>?api_key=&lt;klucz&gt;</code>). Odczyt wymaga uprawnienia
      <code>crm:read</code>, zapis — <code>crm:write</code>. Treść żądań POST/PATCH w formacie
      <code>application/json</code>. Klienci bez PATCH/DELETE mogą użyć
      <code>?_method=PATCH</code> lub nagłówka <code>X-HTTP-Method-Override</code>.
    </p>

    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th>Metoda</th><th>Ścieżka</th><th>Uprawnienie</th><th>Opis</th></tr>
        </thead>
        <tbody class="small">
          <tr><td><span class="badge bg-success">GET</span></td><td><code>/crm.php</code></td><td><code>crm:read</code></td>
              <td>Lista kontaktów. Filtry: <code>q, status, type, source, branza, tag, wojewodztwo, has_email, has_phone, created_from, created_to</code>; paginacja <code>page, per_page</code> (maks. 100).</td></tr>
          <tr><td><span class="badge bg-success">GET</span></td><td><code>/crm.php?id=N</code></td><td><code>crm:read</code></td>
              <td>Pojedynczy kontakt z tagami, notatkami i grupami.</td></tr>
          <tr><td><span class="badge bg-primary">POST</span></td><td><code>/crm.php</code></td><td><code>crm:write</code></td>
              <td>Utwórz kontakt. Wymagane: <code>imie_nazwisko</code> (lub <code>imie</code>+<code>nazwisko</code>). Domyślnie <code>type=osoba</code>, <code>status=prospect</code>, <code>source=api</code>.</td></tr>
          <tr><td><span class="badge bg-warning text-dark">PATCH</span></td><td><code>/crm.php?id=N</code></td><td><code>crm:write</code></td>
              <td>Aktualizuj wybrane pola kontaktu.</td></tr>
          <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/crm.php?id=N</code></td><td><code>crm:write</code></td>
              <td>Usuń kontakt (soft-delete).</td></tr>
          <tr><td><span class="badge bg-primary">POST</span></td><td><code>/crm.php?id=N&amp;resource=notes</code></td><td><code>crm:write</code></td>
              <td>Dodaj notatkę. Body: <code>{"note": "...", "pinned": false}</code>.</td></tr>
          <tr><td><span class="badge bg-primary">POST</span></td><td><code>/crm.php?id=N&amp;resource=tags</code></td><td><code>crm:write</code></td>
              <td>Dodaj tag. Body: <code>{"tag": "..."}</code>.</td></tr>
          <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/crm.php?id=N&amp;resource=tags&amp;tag=X</code></td><td><code>crm:write</code></td>
              <td>Usuń tag <code>X</code>.</td></tr>
        </tbody>
      </table>
    </div>

    <p class="small fw-semibold mb-1">Przykłady (curl):</p>
    <pre class="bg-dark text-light p-3 rounded small mb-0" style="white-space:pre-wrap"># Lista aktywnych kontaktów-organizacji
curl -H "Authorization: Bearer $KEY" \
  "<?= h($api_base) ?>/crm.php?type=organizacja&per_page=20"

# Utworzenie kontaktu
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"imie_nazwisko":"Anna Kowalska","email":"anna@example.pl","status":"prospect"}' \
  "<?= h($api_base) ?>/crm.php"

# Aktualizacja
curl -X PATCH -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"telefon":"+48 600 100 200"}' \
  "<?= h($api_base) ?>/crm.php?id=123"

# Dodanie notatki
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"note":"Pierwszy kontakt telefoniczny."}' \
  "<?= h($api_base) ?>/crm.php?id=123&resource=notes"</pre>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-book me-2"></i>Dokumentacja — Karty 30 API</span>
    <code class="small text-muted"><?= h($api_base) ?>/karty30.php</code>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Uwierzytelnianie jak wyżej (<code>Authorization: Bearer &lt;klucz&gt;</code> lub <code>?api_key=</code>).
      Odczyt wymaga <code>karty30:read</code>, zapis — <code>karty30:write</code>.
      Routing przez parametr <code>?resource=&lt;nazwa&gt;</code> + <code>&amp;id=N</code> dla pojedynczego rekordu.
      Body POST/PATCH w formacie <code>application/json</code>. Filtry list: per zasób (np. <code>client_id</code>,
      <code>course_id</code>, <code>status</code>), wyszukiwanie <code>q</code>, paginacja <code>page</code>, <code>per_page</code> (maks. 100).
    </p>

    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th>Metoda</th><th>Ścieżka</th><th>Uprawnienie</th><th>Opis</th></tr>
        </thead>
        <tbody class="small">
          <tr><td><span class="badge bg-success">GET</span></td><td><code>/karty30.php?resource=R</code></td><td><code>karty30:read</code></td>
              <td>Lista rekordów zasobu (filtry + paginacja).</td></tr>
          <tr><td><span class="badge bg-success">GET</span></td><td><code>/karty30.php?resource=R&amp;id=N</code></td><td><code>karty30:read</code></td>
              <td>Pojedynczy rekord.</td></tr>
          <tr><td><span class="badge bg-primary">POST</span></td><td><code>/karty30.php?resource=R</code></td><td><code>karty30:write</code></td>
              <td>Utwórz rekord (JSON body).</td></tr>
          <tr><td><span class="badge bg-warning text-dark">PATCH</span></td><td><code>/karty30.php?resource=R&amp;id=N</code></td><td><code>karty30:write</code></td>
              <td>Aktualizuj wybrane pola.</td></tr>
          <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/karty30.php?resource=R&amp;id=N</code></td><td><code>karty30:write</code></td>
              <td>Usuń rekord (kursy: soft-delete <code>status=cancelled</code>).</td></tr>
        </tbody>
      </table>
    </div>

    <p class="small mb-1"><strong>Zasoby (<code>resource</code>):</strong></p>
    <ul class="small mb-3">
      <li><strong>Konsultacje/wizyty:</strong> <code>clients</code>, <code>schedules</code>, <code>consultations</code>, <code>waiting</code></li>
      <li><strong>Dydaktyka TI:</strong> <code>courses</code>, <code>enrollments</code>, <code>lessons</code>, <code>homework</code>, <code>materials</code>, <code>grades</code>, <code>tests</code></li>
    </ul>

    <p class="small fw-semibold mb-1">Przykłady (curl):</p>
    <pre class="bg-dark text-light p-3 rounded small mb-0" style="white-space:pre-wrap"># Lista zasobów
curl -H "Authorization: Bearer $KEY" "<?= h($api_base) ?>/karty30.php"

# Lista beneficjentów (wyszukiwanie + paginacja)
curl -H "Authorization: Bearer $KEY" \
  "<?= h($api_base) ?>/karty30.php?resource=clients&q=kowalski&per_page=20"

# Lekcje danego kursu
curl -H "Authorization: Bearer $KEY" \
  "<?= h($api_base) ?>/karty30.php?resource=lessons&course_id=5"

# Utworzenie beneficjenta
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"name":"Jan Kowalski","email":"jan@example.pl","phone":"+48600100200"}' \
  "<?= h($api_base) ?>/karty30.php?resource=clients"

# Wystawienie oceny (e-dziennik) — value_num policzy się z value_text
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"course_id":5,"client_id":12,"category":"sprawdzian","value_text":"4+","weight":2}' \
  "<?= h($api_base) ?>/karty30.php?resource=grades"

# Aktualizacja statusu wizyty
curl -X PATCH -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"status":"confirmed"}' \
  "<?= h($api_base) ?>/karty30.php?resource=schedules&id=42"</pre>
  </div>
</div>

<?php
// ── Create new key form ────────────────────────────────────────────────────
?>
<div class="card">
  <div class="card-header bg-white fw-semibold">Utwórz nowy klucz API</div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <?= csrf_token() ?>
      <input type="hidden" name="action" value="create">

      <div class="col-md-6">
        <label for="key_name" class="form-label">Nazwa klucza <span class="text-danger">*</span></label>
        <input type="text" id="key_name" name="key_name" class="form-control"
               placeholder="np. Integracja z aplikacją mobilną"
               value="<?= h($_POST['key_name'] ?? '') ?>" required maxlength="200">
        <div class="form-text">Opis pomocny przy zarządzaniu kluczami.</div>
      </div>

      <div class="col-12">
        <label class="form-label">Uprawnienia <span class="text-danger">*</span></label>
        <div class="row g-2">
          <?php foreach (API_PERMISSIONS as $perm => $label): ?>
            <?php $field = 'perm_' . str_replace(':', '_', $perm); ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       id="<?= h($field) ?>"
                       name="<?= h($field) ?>"
                       value="1"
                       <?= !empty($_POST[$field]) ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= h($field) ?>">
                  <code class="small"><?= h($perm) ?></code><br>
                  <span class="text-muted small"><?= h($label) ?></span>
                </label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-plus-circle me-1"></i>Utwórz klucz
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
