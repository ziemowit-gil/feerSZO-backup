<?php
/**
 * admin/graph_permissions.php — Konfigurator uprawnień Microsoft Graph
 *
 * Pokazuje listę wymaganych uprawnień API dla każdego modułu systemu,
 * weryfikuje które są aktualnie nadane przez Azure AD i podaje linki
 * do Azure Portal do szybkiego dodawania brakujących.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

auth_start();
require_role('admin');

$PAGE_TITLE = 'Uprawnienia Microsoft Graph';

$graph      = new M365Graph();
$configured = $graph->is_configured();
$client_id  = m365_setting('m365_graph_client_id');
$tenant_id  = m365_setting('m365_tenant_id');

// ── Definicja wymaganych uprawnień ────────────────────────────────────────────

/**
 * required = true  → wymagane do podstawowego działania
 * required = false → opcjonalne (dla konkretnego modułu)
 */
$PERMISSION_GROUPS = [
    [
        'title'    => 'Microsoft Graph — Application',
        'type'     => 'Application',
        'resource' => 'Microsoft Graph',
        'icon'     => 'bi-graph-up',
        'desc'     => 'Uprawnienia aplikacyjne — działają bez zalogowanego użytkownika (client credentials). Wymagają <strong>Admin Consent</strong>.',
        'items'    => [
            ['name' => 'User.ReadWrite.All',        'module' => 'Zarządzanie kontami M365',          'required' => true,  'desc' => 'Tworzenie, edycja i usuwanie kont użytkowników'],
            ['name' => 'Directory.ReadWrite.All',   'module' => 'Grupy i role katalogowe',           'required' => true,  'desc' => 'Odczyt i zapis grup, ról, obiektów katalogu'],
            ['name' => 'Organization.Read.All',     'module' => 'Informacje o organizacji',          'required' => true,  'desc' => 'Dane tenanta, domeny, subskrypcje licencji'],
            ['name' => 'Mail.Send',                 'module' => 'Wysyłanie maili przez Graph',       'required' => true,  'desc' => 'Wysyłanie e-maili jako dowolny użytkownik'],
            ['name' => 'Contacts.Read',             'module' => 'Synchronizacja kontaktów Outlook',  'required' => false, 'desc' => 'Odczyt kontaktów użytkowników (delta sync)'],
            ['name' => 'Calendars.Read',            'module' => 'Synchronizacja kalendarzy Outlook', 'required' => false, 'desc' => 'Odczyt kalendarzy i zdarzeń (delta sync)'],
            ['name' => 'Sites.ReadWrite.All',       'module' => 'Moduł Pliki, backup SP, sync umów', 'required' => true,  'desc' => 'Zapis i odczyt plików oraz folderów w SharePoint (wymagane przez moduł Pliki)'],
            ['name' => 'GroupMember.ReadWrite.All', 'module' => 'Zarządzanie członkostwem grup',     'required' => false, 'desc' => 'Dodawanie i usuwanie członków grup M365'],
        ],
    ],
    [
        'title'    => 'Office 365 Exchange Online — Delegated',
        'type'     => 'Delegated',
        'resource' => 'Office 365 Exchange Online',
        'icon'     => 'bi-envelope-at',
        'desc'     => 'Uprawnienia delegowane dla protokołów pocztowych — wymagane przez <strong>Roundcube</strong> (IMAP/SMTP OAuth2).',
        'items'    => [
            ['name' => 'IMAP.AccessAsUser.All', 'module' => 'Roundcube — odbieranie poczty IMAP', 'required' => false, 'desc' => 'Dostęp IMAP jako zalogowany użytkownik'],
            ['name' => 'SMTP.Send',             'module' => 'Roundcube — wysyłanie poczty SMTP',  'required' => false, 'desc' => 'Wysyłanie maili przez SMTP jako użytkownik'],
        ],
    ],
    [
        'title'    => 'Microsoft Graph — Delegated',
        'type'     => 'Delegated',
        'resource' => 'Microsoft Graph',
        'icon'     => 'bi-person-check',
        'desc'     => 'Uprawnienia delegowane Graph — wymagane przy logowaniu OAuth2 użytkownika.',
        'items'    => [
            ['name' => 'offline_access', 'module' => 'Długotrwałe sesje (refresh token)', 'required' => false, 'desc' => 'Pozwala odświeżać token bez ponownego logowania'],
            ['name' => 'openid',         'module' => 'Logowanie OAuth2 (SSO)',             'required' => false, 'desc' => 'Podstawowe dane profilu przy logowaniu'],
            ['name' => 'profile',        'module' => 'Logowanie OAuth2 (SSO)',             'required' => false, 'desc' => 'Imię, zdjęcie profilowe przy logowaniu'],
            ['name' => 'email',          'module' => 'Logowanie OAuth2 (SSO)',             'required' => false, 'desc' => 'Adres e-mail przy logowaniu'],
        ],
    ],
];

// ── Akcja: weryfikacja przez Graph API ────────────────────────────────────────
$check_result = null;
$check_error  = null;

if ($configured && ($_POST['_action'] ?? '') === 'check') {
    csrf_check();
    try {
        $check_result = $graph->get_granted_permissions();
        if (!empty($check_result['error'])) {
            $check_error  = $check_result['error'];
            $check_result = null;
        }
    } catch (\Throwable $e) {
        $check_error = $e->getMessage();
    }
}

// ── Helper: status uprawnienia ────────────────────────────────────────────────
function perm_granted(?array $result, string $type, string $name): ?bool
{
    if ($result === null) return null;
    $list = ($type === 'Application') ? ($result['application'] ?? []) : ($result['delegated'] ?? []);
    return in_array($name, $list, true);
}

// ── Azure Portal deep links ───────────────────────────────────────────────────
$portal_api_url   = $client_id
    ? "https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/{$client_id}/isMSAApp~/false"
    : 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade';

$portal_consent_url = ($tenant_id && $client_id)
    ? "https://login.microsoftonline.com/{$tenant_id}/adminconsent?client_id={$client_id}"
    : '';

$portal_app_url = $client_id
    ? "https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Overview/appId/{$client_id}/isMSAApp~/false"
    : '';

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid px-4 py-4" style="max-width:1000px">

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/">Panel admina</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/m365_settings.php">Konfiguracja M365</a></li>
    <li class="breadcrumb-item active">Uprawnienia Graph</li>
  </ol>
</nav>

<h4 class="fw-bold mb-1"><i class="bi bi-shield-lock me-2 text-primary"></i><?= h($PAGE_TITLE) ?></h4>
<p class="text-muted small mb-4">
  Lista uprawnień Microsoft Graph API wymaganych przez moduły systemu oraz narzędzie do weryfikacji, które są aktualnie nadane.
</p>

<!-- Status i szybkie linki -->
<div class="card shadow-sm mb-4">
<div class="card-body">
  <div class="row g-3 align-items-center">
    <div class="col-md-5">
      <div class="d-flex align-items-center gap-3">
        <?php if ($configured): ?>
        <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle me-1"></i>Połączenie skonfigurowane</span>
        <?php else: ?>
        <span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-x-circle me-1"></i>Brak konfiguracji M365</span>
        <?php endif; ?>
        <?php if ($client_id): ?>
        <div class="small text-muted">
          Client ID:<br>
          <code class="text-primary"><?= h(substr($client_id, 0, 8)) ?>…</code>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-7 d-flex gap-2 flex-wrap justify-content-md-end">
      <?php if ($portal_app_url): ?>
      <a href="<?= h($portal_app_url) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-box-arrow-up-right me-1"></i>Azure Portal — Aplikacja
      </a>
      <?php endif; ?>
      <?php if ($portal_api_url): ?>
      <a href="<?= h($portal_api_url) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-outline-primary">
        <i class="bi bi-list-check me-1"></i>API permissions
      </a>
      <?php endif; ?>
      <?php if ($portal_consent_url): ?>
      <a href="<?= h($portal_consent_url) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-success">
        <i class="bi bi-patch-check me-1"></i>Grant Admin Consent
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$configured): ?>
  <div class="alert alert-warning mt-3 mb-0 py-2 small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Konfiguracja Microsoft 365 jest niekompletna.
    <a href="<?= APP_URL ?>/admin/m365_settings.php" class="alert-link">Skonfiguruj połączenie</a>
    aby móc weryfikować uprawnienia przez Graph API.
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Wynik weryfikacji -->
<?php if ($check_error): ?>
<div class="alert alert-danger mb-3">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  <strong>Błąd weryfikacji:</strong> <?= h($check_error) ?>
  <div class="small mt-1 text-muted">
    Upewnij się, że aplikacja ma uprawnienie <code>Directory.ReadWrite.All</code> lub
    <code>Application.ReadWrite.All</code> (wymagane do odczytu własnych uprawnień).
  </div>
</div>
<?php elseif ($check_result !== null): ?>
<div class="alert alert-info mb-3 py-2">
  <i class="bi bi-info-circle me-1"></i>
  Wyniki weryfikacji z Azure AD.
  <?php if ($check_result['sp_display_name']): ?>
    Aplikacja: <strong><?= h($check_result['sp_display_name']) ?></strong>
    (SP: <code><?= h($check_result['sp_id']) ?></code>)
  <?php endif; ?>
  &nbsp;·&nbsp;
  <span class="text-success fw-semibold"><?= count($check_result['application']) ?></span>
  uprawnień aplikacyjnych,
  <span class="text-primary fw-semibold"><?= count($check_result['delegated']) ?></span>
  zakresów delegowanych.
</div>
<?php endif; ?>

<!-- Tabela uprawnień -->
<?php foreach ($PERMISSION_GROUPS as $group):
    $has_missing = false;
    if ($check_result !== null) {
        foreach ($group['items'] as $item) {
            if (perm_granted($check_result, $group['type'], $item['name']) === false) {
                $has_missing = true;
                break;
            }
        }
    }
?>
<div class="card shadow-sm mb-3<?= ($check_result && $has_missing) ? ' border-warning' : '' ?>">
<div class="card-header fw-semibold d-flex align-items-center justify-content-between">
  <span>
    <i class="bi <?= h($group['icon']) ?> me-2"></i><?= h($group['title']) ?>
    <span class="badge bg-secondary ms-2"><?= h($group['type']) ?></span>
    <?php if ($check_result && $has_missing): ?>
    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Brakujące uprawnienia</span>
    <?php elseif ($check_result !== null): ?>
    <span class="badge bg-success ms-1"><i class="bi bi-check2 me-1"></i>OK</span>
    <?php endif; ?>
  </span>
  <?php
    // Deep link do dodania uprawnień dla tej grupy
    if ($group['resource'] === 'Office 365 Exchange Online' && $client_id) {
        $exchange_url = "https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/{$client_id}/isMSAApp~/false";
        echo '<a href="' . h($exchange_url) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-box-arrow-up-right me-1"></i>Dodaj w Azure</a>';
    } elseif ($client_id) {
        echo '<a href="' . h($portal_api_url) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-box-arrow-up-right me-1"></i>Dodaj w Azure</a>';
    }
  ?>
</div>
<div class="card-body p-0">
  <?php if ($group['desc']): ?>
  <div class="px-3 pt-3 pb-1 small text-muted"><?= $group['desc'] ?></div>
  <?php endif; ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0" style="font-size:.875rem">
    <thead class="table-light">
      <tr>
        <th style="width:1rem"></th>
        <th>Uprawnienie</th>
        <th>Moduł / funkcja</th>
        <th>Opis</th>
        <th class="text-center" style="width:7rem">Wymagane</th>
        <?php if ($check_result !== null): ?><th class="text-center" style="width:7rem">Status</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($group['items'] as $item):
        $granted = perm_granted($check_result, $group['type'], $item['name']);
    ?>
      <tr<?php
        if ($granted === true) echo ' class="table-success"';
        elseif ($granted === false && $item['required']) echo ' class="table-danger"';
        elseif ($granted === false) echo ' class="table-warning"';
      ?>>
        <td class="text-center ps-3">
          <?php if ($granted === true): ?>
            <i class="bi bi-check-circle-fill text-success"></i>
          <?php elseif ($granted === false): ?>
            <i class="bi bi-x-circle-fill <?= $item['required'] ? 'text-danger' : 'text-warning' ?>"></i>
          <?php else: ?>
            <i class="bi bi-circle text-muted"></i>
          <?php endif; ?>
        </td>
        <td class="font-monospace fw-semibold"><?= h($item['name']) ?></td>
        <td><?= h($item['module']) ?></td>
        <td class="text-muted small"><?= h($item['desc']) ?></td>
        <td class="text-center">
          <?php if ($item['required']): ?>
          <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Wymagane</span>
          <?php else: ?>
          <span class="badge bg-light text-secondary border">Opcjonalne</span>
          <?php endif; ?>
        </td>
        <?php if ($check_result !== null): ?>
        <td class="text-center">
          <?php if ($granted === true): ?>
            <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Nadane</span>
          <?php else: ?>
            <span class="badge bg-<?= $item['required'] ? 'danger' : 'warning text-dark' ?>">
              <i class="bi bi-x me-1"></i>Brak
            </span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
</div>
<?php endforeach; ?>

<!-- Przycisk weryfikacji -->
<div class="card shadow-sm mb-4">
<div class="card-body d-flex align-items-center gap-3 flex-wrap">
  <div class="flex-grow-1">
    <h6 class="mb-1 fw-semibold"><i class="bi bi-cloud-check me-2 text-primary"></i>Weryfikacja przez Graph API</h6>
    <p class="text-muted small mb-0">
      Pobiera aktualną listę nadanych uprawnień bezpośrednio z Azure AD poprzez Graph API.
      Wymaga uprawnienia <code>Directory.ReadWrite.All</code> lub <code>Application.ReadWrite.All</code>.
    </p>
  </div>
  <?php if ($configured): ?>
  <form method="post" class="d-flex gap-2 align-items-center">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="check">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-arrow-repeat me-1"></i>Sprawdź przez Graph API
    </button>
  </form>
  <?php else: ?>
  <a href="<?= APP_URL ?>/admin/m365_settings.php" class="btn btn-outline-primary">
    <i class="bi bi-gear me-1"></i>Skonfiguruj M365
  </a>
  <?php endif; ?>
</div>
</div>

<!-- Instrukcja nadania uprawnień -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-book me-2"></i>Jak nadać uprawnienia w Azure Portal</div>
<div class="card-body small">
  <ol class="ps-3 mb-3">
    <li class="mb-2">
      Przejdź do
      <?php if ($portal_app_url): ?>
      <a href="<?= h($portal_app_url) ?>" target="_blank" rel="noopener">
        <strong>Azure Portal → App registrations → Twoja aplikacja</strong>
      </a>
      <?php else: ?>
      <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade"
         target="_blank" rel="noopener"><strong>Azure Portal → App registrations</strong></a>
      i wybierz swoją aplikację
      <?php endif; ?>
    </li>
    <li class="mb-2">
      Kliknij <strong>API permissions</strong> → <strong>Add a permission</strong>
    </li>
    <li class="mb-2">
      <strong>Application permissions</strong> (Microsoft Graph):<br>
      Wybierz <em>Microsoft Graph</em> → <em>Application permissions</em> →
      wyszukaj i zaznacz wymagane uprawnienia z tabeli powyżej
    </li>
    <li class="mb-2">
      <strong>Delegated permissions</strong> (Exchange Online):<br>
      Wybierz <em>APIs my organization uses</em> → <em>Office 365 Exchange Online</em> →
      <em>Delegated permissions</em> → zaznacz <code>IMAP.AccessAsUser.All</code> i <code>SMTP.Send</code>
    </li>
    <li class="mb-2">
      Po dodaniu wszystkich uprawnień kliknij
      <strong>Grant admin consent for [organizacja]</strong> — wymagana rola <em>Global Administrator</em>
    </li>
    <li>
      Wróć tutaj i kliknij <strong>Sprawdź przez Graph API</strong> aby potwierdzić
    </li>
  </ol>
  <?php if ($portal_consent_url): ?>
  <div class="alert alert-success py-2 mb-0 d-flex align-items-center gap-3">
    <i class="bi bi-patch-check-fill text-success fs-5"></i>
    <div>
      <strong>Szybki Admin Consent:</strong>
      <a href="<?= h($portal_consent_url) ?>" target="_blank" rel="noopener" class="alert-link">
        Kliknij ten link aby nadać consent dla Client ID <code><?= h($client_id ?: '—') ?></code>
      </a>
      <div class="text-muted small mt-1">Wymagana rola Global Administrator w tenancie <?= h($tenant_id ?: '—') ?></div>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

<?php if ($check_result !== null && !empty($check_result['application'])): ?>
<!-- Wszystkie nadane uprawnienia (raw) -->
<details class="mb-4">
  <summary class="fw-semibold text-muted small" style="cursor:pointer">
    <i class="bi bi-code-square me-1"></i>Pokaż surową listę z Azure AD
  </summary>
  <div class="card mt-2 border-0 bg-light">
  <div class="card-body small">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="fw-semibold mb-1">Application permissions (<?= count($check_result['application']) ?>):</div>
        <ul class="list-unstyled font-monospace mb-0" style="font-size:.8rem">
          <?php foreach (sort_ignore_case($check_result['application']) as $p): ?>
          <li><code><?= h($p) ?></code></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-6">
        <div class="fw-semibold mb-1">Delegated scopes (<?= count($check_result['delegated']) ?>):</div>
        <ul class="list-unstyled font-monospace mb-0" style="font-size:.8rem">
          <?php foreach (sort_ignore_case($check_result['delegated']) as $p): ?>
          <li><code><?= h($p) ?></code></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  </div>
</details>
<?php endif; ?>

</div><!-- /container -->

<?php
include dirname(__DIR__) . '/includes/footer.php';

function sort_ignore_case(array $arr): array {
    natcasesort($arr);
    return array_values($arr);
}
?>
