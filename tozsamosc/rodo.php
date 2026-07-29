<?php
/**
 * tozsamosc/rodo.php — Katalog RODO w podsystemie Tożsamość.
 *
 * Pokazuje użytkownikowi: co dzieje się z jego danymi/umową (administrator, cel,
 * okres) oraz jakie ma upoważnienia do przetwarzania danych osobowych
 * (rodo_authorizations) — dla umów wolontariat + zlecenie. Reużywa includes/rodo.php.
 * Chrome podsystemu (_head/_foot), paleta #1E6DFF, WCAG AA.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();

auth_start();
if (!current_user()) { header('Location: ' . APP_URL . '/tozsamosc/login.php'); exit; }
require_login();

$PAGE_TITLE = 'Katalog RODO';
$user  = current_user();
$uid   = (int)$user['id'];
$email = $user['email'] ?? '';
$ms_id = db_one("SELECT microsoft_id FROM users WHERE id=?", [$uid])['microsoft_id'] ?? '';
$org   = rodo_org_data();

// ── Upoważnienia użytkownika (wolontariat + zlecenie) ───────────────────────
$authorizations = [];
$idsByType = [];
foreach (['wolontariat', 'zlecenie'] as $ctype) {
    $ids = [];
    try {
        $rows = db_all("SELECT id FROM umowy_{$ctype} WHERE email=? OR m365_login=?", [$email, $email]);
        foreach ($rows as $r) $ids[] = (int)$r['id'];
        if ($ms_id) {
            foreach (db_all("SELECT id FROM umowy_{$ctype} WHERE m365_user_id=?", [$ms_id]) as $r) {
                if (!in_array((int)$r['id'], $ids, true)) $ids[] = (int)$r['id'];
            }
        }
    } catch (\Throwable $e) { $ids = []; }
    if ($ids) $idsByType[$ctype] = $ids;
    if (!$ids) continue;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        foreach (db_all("SELECT * FROM rodo_authorizations WHERE contract_type=? AND contract_id IN ({$ph}) ORDER BY created_at DESC",
                        array_merge([$ctype], $ids)) as $a) {
            $authorizations[] = $a;
        }
    } catch (\Throwable $e) {}
}

// ── Umowy użytkownika (co się dzieje z umową) ───────────────────────────────
$contracts = [];
foreach ([['wolontariat','email','Wolontariat'],['zlecenie','email','Zlecenie']] as [$ctype,$ecol,$lbl]) {
    try {
        foreach (db_all("SELECT numer_umowy, status, data_zawarcia FROM umowy_{$ctype}
                         WHERE {$ecol}=? OR m365_login=? ORDER BY id DESC", [$email, $email]) as $c) {
            $contracts[] = $c + ['_label' => $lbl];
        }
    } catch (\Throwable $e) {}
}

// ── Historia zdarzeń umów (czytelnie) ───────────────────────────────────────
$history = [];
if ($idsByType) {
    $conds = []; $params = [];
    foreach ($idsByType as $ctype => $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $conds[] = "(contract_type=? AND contract_id IN ({$ph}))";
        $params[] = $ctype;
        foreach ($ids as $i) $params[] = $i;
    }
    try {
        $history = db_all(
            "SELECT contract_type, contract_id, action, note, created_at
             FROM contract_audit_log WHERE " . implode(' OR ', $conds) .
            " ORDER BY created_at DESC LIMIT 40", $params);
    } catch (\Throwable $e) { $history = []; }
}
// Mapa akcji → czytelna etykieta + ikona
$ACT = [
    'create'             => ['Utworzenie umowy', 'bi-file-earmark-plus', '#16a34a'],
    'contract_create'    => ['Utworzenie umowy', 'bi-file-earmark-plus', '#16a34a'],
    'user_create'        => ['Założenie konta', 'bi-person-plus', '#16a34a'],
    'edit'               => ['Edycja danych umowy', 'bi-pencil', '#1E6DFF'],
    'status'             => ['Zmiana statusu', 'bi-flag', '#f59e0b'],
    'renewal_create'     => ['Przedłużenie umowy', 'bi-arrow-repeat', '#1E6DFF'],
    'renewed_by'         => ['Przedłużenie umowy', 'bi-arrow-repeat', '#1E6DFF'],
    'certificate_issued' => ['Wydano certyfikat', 'bi-patch-check', '#16a34a'],
    'consent_accepted'   => ['Zaakceptowano zgodę', 'bi-check2-square', '#16a34a'],
    'submit_approval'    => ['Skierowano do akceptacji', 'bi-send', '#1E6DFF'],
    'approve'            => ['Zaakceptowano', 'bi-check-circle', '#16a34a'],
    'note'               => ['Notatka', 'bi-sticky', '#6B7280'],
    'cpc_verify_ok'      => ['Weryfikacja tożsamości', 'bi-shield-check', '#16a34a'],
    'user_password_reset'=> ['Reset hasła', 'bi-key', '#f59e0b'],
    'user_role_change'   => ['Zmiana roli', 'bi-person-gear', '#f59e0b'],
    'login'              => ['Logowanie', 'bi-box-arrow-in-right', '#6B7280'],
    'delete'             => ['Usunięcie', 'bi-trash', '#dc2626'],
];

$TZ_ACTIVE = 'rodo';
include __DIR__ . '/_head.php';
?>

<div class="tz-h">
  <h1><i class="bi bi-shield-lock me-2" style="color:#1E6DFF" aria-hidden="true"></i>Katalog RODO</h1>
  <p>Co dzieje się z Twoją umową i danymi oraz jakie masz upoważnienia · Data protection</p>
</div>

<p class="mb-3"><a href="<?= APP_URL ?>/tozsamosc/index.php" class="tz-btn tz-btn--ghost btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do Tożsamości</a></p>

<!-- ── Co się dzieje z danymi / umową ── -->
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>Co dzieje się z Twoimi danymi <span class="lbl-en">How your data is processed</span></span>
  </div>
  <div class="tz-card__bd">
    <dl class="tz-dl mb-0" style="border:1px solid var(--tz-line);border-radius:10px;overflow:hidden">
      <div style="border-top:0"><dt>Administrator danych</dt><dd><?= h($org['name'] ?: '—') ?></dd></div>
      <div style="border-top:0"><dt>Siedziba</dt><dd><?= h(trim(($org['address'] ?? '') . ' ' . ($org['city'] ?? ''))) ?: '—' ?></dd></div>
      <div style="border-top:0"><dt>NIP / KRS</dt><dd><?= h(trim(($org['nip'] ?? '') . ($org['krs'] ? ' / ' . $org['krs'] : ''))) ?: '—' ?></dd></div>
      <div><dt>Cel przetwarzania</dt><dd>Realizacja umowy oraz obowiązków organizacji</dd></div>
      <div><dt>Podstawa prawna</dt><dd>Wykonanie umowy (art. 6 ust. 1 lit. b RODO)</dd></div>
      <div><dt>Okres przechowywania</dt><dd>Przez czas trwania umowy i okres wymagany przepisami</dd></div>
    </dl>
    <p class="tz-note mt-3 mb-0" style="border:0;padding-left:0">
      <i class="bi bi-person-check" aria-hidden="true"></i>
      <span>Masz prawo dostępu do swoich danych, ich sprostowania, ograniczenia przetwarzania oraz — w zakresie dozwolonym prawem — usunięcia. Skontaktuj się z administratorem lub inspektorem ochrony danych organizacji.</span>
    </p>
  </div>
</section>

<!-- ── Status umów ── -->
<?php if ($contracts): ?>
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>
    <span>Twoje umowy <span class="lbl-en">Your agreements</span></span>
  </div>
  <div class="tz-card__bd py-2">
    <?php foreach ($contracts as $c): ?>
    <div class="tz-svc">
      <span class="tz-svc__ico"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($c['_label']) ?> <?= h($c['numer_umowy'] ?: '') ?></div>
        <div class="text-muted small">Status: <?= h($c['status'] ?: '—') ?><?= !empty($c['data_zawarcia']) ? ' · zawarta ' . h(date('d.m.Y', strtotime($c['data_zawarcia']))) : '' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── Upoważnienia ── -->
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-patch-check" aria-hidden="true"></i>
    <span>Twoje upoważnienia do przetwarzania danych <span class="lbl-en">Your data-processing authorizations</span></span>
  </div>
  <div class="tz-card__bd">
    <?php if (!$authorizations): ?>
      <div class="text-center text-muted py-3">
        <i class="bi bi-shield-check fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
        <div class="fw-semibold">Brak upoważnień powiązanych z Twoim kontem</div>
        <div class="small">Jeśli uważasz, że powinno istnieć — skontaktuj się z administratorem.</div>
      </div>
    <?php else: foreach ($authorizations as $a):
      $scope = json_decode($a['scope_items'] ?? '[]', true) ?: [];
    ?>
      <div class="border rounded p-3 mb-3 <?= $a['status'] !== 'aktywne' ? 'opacity-75' : '' ?>" style="border-color:var(--tz-line)!important">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
          <div>
            <span class="font-monospace fw-bold text-muted" style="font-size:.82rem"><?= h($a['number']) ?></span>
            <?= rodo_status_badge($a['status']) ?>
            <?php if (empty($a['training_done']) && $a['status'] === 'aktywne'): ?>
              <span class="tz-badge tz-badge--warn ms-1"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Brak szkolenia RODO</span>
            <?php endif; ?>
            <div class="text-muted small mt-1">
              Wydane przez: <strong><?= h($a['org_name'] ?: $org['name']) ?></strong>
              · od <?= !empty($a['authorized_from']) ? h(date('d.m.Y', strtotime($a['authorized_from']))) : '—' ?>
              <?= !empty($a['authorized_until']) ? ' do ' . h(date('d.m.Y', strtotime($a['authorized_until']))) : ' (do zakończenia umowy)' ?>
            </div>
          </div>
          <a href="<?= APP_URL ?>/rodo/print.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener" class="tz-btn tz-btn--ghost btn-sm">
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Pobierz / drukuj
          </a>
        </div>
        <?php if ($scope || !empty($a['scope_custom'])): ?>
        <div class="small fw-semibold text-muted mb-1">Zakres upoważnienia:</div>
        <ul class="mb-0 ps-3" style="font-size:.87rem">
          <?php foreach ($scope as $k): ?><li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li><?php endforeach; ?>
          <?php if (!empty($a['scope_custom'])): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
        </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <p class="tz-note mb-0" style="border:0;padding-left:0">
      <i class="bi bi-lock" aria-hidden="true"></i>
      <span>Upoważnienie uprawnia do przetwarzania danych osobowych w zakresie wskazanym wyżej. Obowiązuje Cię <strong>poufność</strong> — również po zakończeniu współpracy.</span>
    </p>
  </div>
</section>

<!-- ── Historia zdarzeń ── -->
<section class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-clock-history" aria-hidden="true"></i>
    <span>Historia zdarzeń umowy <span class="lbl-en">Activity history</span></span>
  </div>
  <div class="tz-card__bd">
    <?php if (!$history): ?>
      <div class="text-muted small">Brak zapisanych zdarzeń dla Twoich umów.</div>
    <?php else: ?>
      <ol class="list-unstyled mb-0" style="position:relative">
        <?php foreach ($history as $ev):
          [$lbl, $ic, $col] = $ACT[$ev['action']] ?? [$ev['action'], 'bi-dot', '#6B7280'];
        ?>
        <li class="d-flex gap-3 pb-3" style="border-left:2px solid var(--tz-line);margin-left:14px;padding-left:16px;position:relative">
          <span style="position:absolute;left:-9px;top:0;width:16px;height:16px;border-radius:50%;background:#fff;border:2px solid <?= $col ?>;display:flex;align-items:center;justify-content:center">
            <i class="bi <?= h($ic) ?>" style="font-size:.55rem;color:<?= $col ?>" aria-hidden="true"></i>
          </span>
          <div>
            <div class="fw-semibold" style="font-size:.9rem"><?= h($lbl) ?>
              <span class="text-muted fw-normal" style="font-size:.78rem">· <?= h(ucfirst($ev['contract_type'])) ?></span>
            </div>
            <div class="text-muted" style="font-size:.8rem">
              <?= !empty($ev['created_at']) ? h(date('d.m.Y H:i', strtotime($ev['created_at']))) : '' ?>
              <?php if (!empty($ev['note'])): ?> · <?= h(mb_strimwidth($ev['note'], 0, 120, '…')) ?><?php endif; ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/_foot.php';
