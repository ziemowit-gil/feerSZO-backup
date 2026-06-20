<?php
/**
 * Diagnostyka SSO SAML (IdP) — szczególnie dla Canva.
 * Pokazuje stan certyfikatu, konfigurację SP vs wymagania Canva,
 * self-test budowy i podpisu asercji oraz ostatnie błędy z logu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/saml_idp.php';
require_once dirname(__DIR__) . '/includes/canva.php';
require_role('admin');

$PAGE_TITLE = 'Diagnostyka SSO (SAML) — Canva';

$enabled  = saml_idp_enabled();
$hasCert  = saml_idp_has_cert();
$certInfo = $hasCert ? saml_idp_cert_info() : null;
$sp       = function_exists('canva_saml_sp') ? canva_saml_sp() : null;

// Wymagania Canva (wg dokumentacji Canva)
$REQ = [
    'entity_id'     => 'https://www.canva.com',
    'acs_url'       => 'https://www.canva.com/login/saml',
    'nameid_format' => 'emailAddress',
];

// Self-test: zbuduj odpowiedź dla bieżącego admina i zweryfikuj nasz podpis.
$selfTest = ['ran' => false];
if ($enabled && $hasCert && $sp) {
    try {
        $u = db_one("SELECT * FROM users WHERE id=?", [(int)current_user()['id']]);
        $si = null;
        $xml = saml_build_response($sp, $u, '', (string)$sp['acs_url'], $si);
        $sigOk = saml_verify_xml_signature($xml, saml_idp_cert_pem());
        $selfTest = [
            'ran'        => true,
            'built'      => true,
            'sig_ok'     => $sigOk,
            'email'      => $u['email'] ?? '',
            'first_name' => $u['first_name'] ?? '',
            'last_name'  => $u['last_name'] ?? '',
            'nameid'     => saml_name_id_value($sp, $u),
            'size'       => strlen($xml),
        ];
    } catch (\Throwable $e) {
        $selfTest = ['ran' => true, 'built' => false, 'error' => $e->getMessage()];
    }
}

// Ostatnie wpisy logu dla Canva
$log = [];
try {
    $log = db_all(
        "SELECT * FROM saml_sso_log
         WHERE sp_entity LIKE '%canva%' " . ($sp ? "OR sp_id=" . (int)$sp['id'] : '') . "
         ORDER BY id DESC LIMIT 15"
    );
} catch (\Throwable $e) {}

$chk = fn(bool $ok) => $ok
    ? '<i class="bi bi-check-circle-fill text-success"></i>'
    : '<i class="bi bi-x-circle-fill text-danger"></i>';

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary me-2"></i>Diagnostyka SSO (SAML) — Canva</h4>
  <a href="<?= APP_URL ?>/admin/saml.php" class="btn btn-sm btn-outline-secondary ms-auto">Konfiguracja SAML</a>
</div>

<!-- IdP / certyfikat -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-key me-2 text-primary"></i>Tożsamość IdP i certyfikat</div>
  <div class="card-body">
    <dl class="row mb-0" style="font-size:.86rem;row-gap:.4rem">
      <dt class="col-sm-3 text-muted fw-normal">IdP włączony</dt><dd class="col-sm-9"><?= $chk($enabled) ?> <?= $enabled?'tak':'NIE — włącz w ustawieniach SAML' ?></dd>
      <dt class="col-sm-3 text-muted fw-normal">Entity ID (Issuer)</dt><dd class="col-sm-9 font-monospace"><?= h(saml_idp_entity_id()) ?></dd>
      <dt class="col-sm-3 text-muted fw-normal">SSO URL</dt><dd class="col-sm-9 font-monospace"><?= h(saml_idp_sso_url()) ?></dd>
      <dt class="col-sm-3 text-muted fw-normal">Metadane</dt><dd class="col-sm-9"><a href="<?= h(saml_idp_metadata_url()) ?>" target="_blank" class="font-monospace"><?= h(saml_idp_metadata_url()) ?></a></dd>
      <dt class="col-sm-3 text-muted fw-normal">Certyfikat</dt>
      <dd class="col-sm-9">
        <?php if($certInfo): ?>
          <?= $chk(true) ?> <?= h($certInfo['subject']) ?> ·
          ważny <?= h($certInfo['valid_from']) ?> – <?= h($certInfo['valid_to']) ?>
          <?php if($certInfo['days_left'] < 0): ?><span class="badge bg-danger ms-1">WYGASŁ</span>
          <?php elseif($certInfo['days_left'] < 30): ?><span class="badge bg-warning text-dark ms-1">wygasa za <?= (int)$certInfo['days_left'] ?> dni</span><?php endif; ?>
          <div class="text-muted mt-1" style="font-size:.78rem">Odcisk SHA-256: <span class="font-monospace"><?= h($certInfo['fingerprint']) ?></span></div>
          <div class="text-muted" style="font-size:.78rem"><i class="bi bi-info-circle me-1"></i>Ten odcisk musi zgadzać się z certyfikatem wgranym w panelu Canva. Po rotacji certu trzeba zaktualizować go w Canva.</div>
        <?php else: ?>
          <?= $chk(false) ?> Brak certyfikatu IdP — wygeneruj w ustawieniach SAML.
        <?php endif; ?>
      </dd>
    </dl>
  </div>
</div>

<!-- Konfiguracja SP Canva -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-box-arrow-in-right me-2 text-primary"></i>Service Provider „Canva"</div>
  <div class="card-body">
    <?php if(!$sp): ?>
    <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Brak zarejestrowanego SP Canva. Dodaj SP (preset „canva") w <a href="<?= APP_URL ?>/admin/saml.php">konfiguracji SAML</a>. Wartości: Entity ID <code><?= h($REQ['entity_id']) ?></code>, ACS <code><?= h($REQ['acs_url']) ?></code>.</div>
    <?php else: ?>
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <tbody>
        <tr><td style="width:38%">Aktywny</td><td><?= $chk((int)$sp['is_active']===1) ?> <?= (int)$sp['is_active']===1?'tak':'NIE' ?></td></tr>
        <tr><td>Entity ID (Audience)</td>
          <td><?= $chk($sp['entity_id']===$REQ['entity_id']) ?> <span class="font-monospace"><?= h($sp['entity_id']) ?></span>
          <?php if($sp['entity_id']!==$REQ['entity_id']): ?><div class="text-danger" style="font-size:.78rem">Powinno być: <?= h($REQ['entity_id']) ?></div><?php endif; ?></td></tr>
        <tr><td>ACS URL (Reply)</td>
          <td><?= $chk($sp['acs_url']===$REQ['acs_url']) ?> <span class="font-monospace"><?= h($sp['acs_url']) ?></span>
          <?php if($sp['acs_url']!==$REQ['acs_url']): ?><div class="text-danger" style="font-size:.78rem">Powinno być: <?= h($REQ['acs_url']) ?></div><?php endif; ?></td></tr>
        <tr><td>Format NameID</td>
          <td><?= $chk(($sp['nameid_format']??'')===$REQ['nameid_format']) ?> <?= h($sp['nameid_format']??'—') ?>
          <?php if(($sp['nameid_format']??'')!==$REQ['nameid_format']): ?><div class="text-danger" style="font-size:.78rem">Canva wymaga: emailAddress</div><?php endif; ?></td></tr>
        <tr><td>Podpis asercji</td>
          <td><?= $chk((int)($sp['sign_assertion']??0)===1) ?> <?= (int)($sp['sign_assertion']??0)===1?'włączony':'WYŁĄCZONY' ?>
          <?php if((int)($sp['sign_assertion']??0)!==1): ?><div class="text-danger" style="font-size:.78rem">Canva wymaga podpisanej asercji — włącz.</div><?php endif; ?></td></tr>
        <tr><td>Podpis odpowiedzi</td><td class="text-muted"><?= (int)($sp['sign_response']??0)===1?'włączony':'wyłączony (opcjonalny dla Canva)' ?></td></tr>
        <tr><td>NameID = e-mail źródło</td><td class="text-muted font-monospace"><?= h($sp['nameid_attr']?:'email') ?></td></tr>
        <tr><td>Dozwolone role</td><td class="text-muted"><?= h($sp['allowed_roles']?:'(wszystkie)') ?></td></tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Self-test -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-clipboard-check me-2 text-primary"></i>Self-test asercji (dla Twojego konta)</div>
  <div class="card-body">
    <?php if(!$selfTest['ran']): ?>
    <div class="text-muted" style="font-size:.85rem">Test niedostępny — brak certu, SP lub wyłączony IdP (patrz wyżej).</div>
    <?php elseif(empty($selfTest['built'])): ?>
    <div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-1"></i>Budowa odpowiedzi nie powiodła się: <?= h($selfTest['error'] ?? '') ?></div>
    <?php else: ?>
    <dl class="row mb-0" style="font-size:.86rem;row-gap:.35rem">
      <dt class="col-sm-3 text-muted fw-normal">Asercja zbudowana</dt><dd class="col-sm-9"><?= $chk(true) ?> tak (<?= (int)$selfTest['size'] ?> B)</dd>
      <dt class="col-sm-3 text-muted fw-normal">Podpis poprawny</dt>
      <dd class="col-sm-9"><?= $chk(!empty($selfTest['sig_ok'])) ?> <?= !empty($selfTest['sig_ok'])?'tak — klucz i certyfikat zgodne':'NIE — klucz nie pasuje do certyfikatu (zregeneruj parę / napraw certy)' ?></dd>
      <dt class="col-sm-3 text-muted fw-normal">NameID (e-mail)</dt>
      <dd class="col-sm-9"><?= $selfTest['nameid'] ? h($selfTest['nameid']) : '<span class="text-danger">PUSTY — Canva odrzuci (konto bez adresu e-mail)</span>' ?></dd>
      <dt class="col-sm-3 text-muted fw-normal">Atrybuty</dt>
      <dd class="col-sm-9">Email: <?= h($selfTest['email'] ?: '—') ?> · FirstName: <?= h($selfTest['first_name'] ?: '—') ?> · LastName: <?= h($selfTest['last_name'] ?: '—') ?></dd>
    </dl>
    <?php endif; ?>
  </div>
</div>

<!-- Log -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-list-ul me-2 text-primary"></i>Ostatnie zdarzenia SSO (Canva)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0" style="font-size:.8rem">
      <thead class="table-light"><tr><th>Czas</th><th>Wynik</th><th>Użytkownik</th><th>Binding</th><th>Szczegóły</th></tr></thead>
      <tbody>
      <?php foreach($log as $l): $res=$l['result']??''; $cls=$res==='ok'?'success':($res==='denied'?'warning':'danger'); ?>
        <tr>
          <td class="text-nowrap text-muted"><?= h($l['created_at'] ?? '') ?></td>
          <td><span class="badge bg-<?= $cls ?> bg-opacity-15 text-<?= $cls ?> border border-<?= $cls ?>"><?= h($res) ?></span></td>
          <td><?= h($l['user_email'] ?? '') ?></td>
          <td class="text-muted"><?= h($l['binding'] ?? '') ?></td>
          <td><?= h($l['detail'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$log): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak zdarzeń w logu (lub błędy logowane tylko do error_log serwera).</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted mt-2" style="font-size:.78rem"><i class="bi bi-info-circle me-1"></i>Jeśli Canva nadal zgłasza błąd mimo zielonych znaczników: najczęściej certyfikat IdP w panelu Canva jest nieaktualny (porównaj odcisk SHA-256) albo e-mail konta nie należy do zweryfikowanej domeny w Canva.</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
