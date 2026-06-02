<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';

require_login();
require_role('admin');

// ── Akcja: wyślij przypomnienia teraz ─────────────────────────────────────────
$send_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminders') {
    csrf_check();

    $today       = date('Y-m-d');
    $notify_days = [30, 14, 7, 1];

    $contract_tables = [
        'wolontariat' => 'umowy_wolontariat',
        'zlecenie'    => 'umowy_zlecenie',
        'dzielo'      => 'umowy_dzielo',
        'uslugi'      => 'umowy_uslugi',
        'inne'        => 'umowy_inne',
    ];

    $sent = $skip = $errs = 0;
    $log  = [];

    foreach ($contract_tables as $type => $table) {
        $contracts = db_all(
            "SELECT id, numer_umowy, imie_nazwisko, data_zakonczenia, opiekun, email, guardian_editor_id
             FROM {$table}
             WHERE bezterminowa = 0
               AND data_zakonczenia IS NOT NULL
               AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')",
            []
        );

        foreach ($contracts as $c) {
            $end_date  = $c['data_zakonczenia'];
            if (!$end_date) continue;

            $days_left = (int) round(
                (strtotime($end_date) - strtotime($today)) / 86400
            );
            if (!in_array($days_left, $notify_days, true)) continue;

            $contract_id  = (int) $c['id'];
            $numer        = $c['numer_umowy'] ?? "#{$contract_id}";
            $osoba        = $c['imie_nazwisko'] ?? '';
            $opiekun_name = $c['opiekun'] ?? '';
            $to_email     = '';

            if (!empty($c['guardian_editor_id'])) {
                $user = db_one(
                    "SELECT email, imie_nazwisko FROM users WHERE id = ?",
                    [(int) $c['guardian_editor_id']]
                );
                if ($user && !empty($user['email'])) {
                    $to_email = $user['email'];
                    if (empty($opiekun_name) && !empty($user['imie_nazwisko'])) {
                        $opiekun_name = $user['imie_nazwisko'];
                    }
                }
            }
            if (!$to_email && !empty($c['email'])) {
                $to_email = $c['email'];
            }
            if (!$to_email) { $skip++; continue; }

            $settings_key = "expiry_reminder_sent_{$type}_{$contract_id}_{$days_left}";
            $already      = db_one("SELECT value FROM settings WHERE key_=?", [$settings_key]);
            if ($already) { $skip++; continue; }

            $type_label    = CONTRACT_TYPES[$type] ?? ucfirst($type);
            $days_label    = ($days_left === 1) ? 'dzień' : 'dni';
            $subject       = "Przypomnienie: {$type_label} {$numer} wygasa za {$days_left} {$days_label}";
            $urgency_color = match (true) {
                $days_left <= 7  => '#dc3545',
                $days_left <= 14 => '#fd7e14',
                default          => '#0d6efd',
            };
            $data_pl = date('d.m.Y', strtotime($end_date));

            try {
                mail_queue_add(
                    $to_email,
                    $opiekun_name,
                    $subject,
                    _expiry_email_html($type_label, $numer, $osoba, $opiekun_name, $data_pl, $days_left, $urgency_color)
                );
                db()->prepare(
                    "INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)"
                )->execute([$settings_key, $today]);

                $log[] = ['ok', "{$type} {$numer} ({$osoba}) — {$days_left} {$days_label}"];
                $sent++;
            } catch (\Throwable $e) {
                $log[] = ['err', "{$type} {$numer}: " . $e->getMessage()];
                $errs++;
            }
        }
    }

    $queue_result = mail_queue_process();
    $send_result  = compact('sent', 'skip', 'errs', 'log', 'queue_result');
}

// ── Dane dla widoku ────────────────────────────────────────────────────────────
$today      = date('Y-m-d');
$horizon    = date('Y-m-d', strtotime('+60 days'));
$filter_type = $_GET['typ'] ?? '';

$contract_tables = [
    'wolontariat' => 'umowy_wolontariat',
    'zlecenie'    => 'umowy_zlecenie',
    'dzielo'      => 'umowy_dzielo',
    'uslugi'      => 'umowy_uslugi',
    'inne'        => 'umowy_inne',
];

$all_contracts = [];

foreach ($contract_tables as $type => $table) {
    if ($filter_type && $filter_type !== $type) continue;

    $rows = db_all(
        "SELECT id, numer_umowy, imie_nazwisko, data_zakonczenia, opiekun, guardian_editor_id
         FROM {$table}
         WHERE bezterminowa = 0
           AND data_zakonczenia IS NOT NULL
           AND data_zakonczenia BETWEEN ? AND ?
           AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')
         ORDER BY data_zakonczenia ASC",
        [$today, $horizon]
    );

    foreach ($rows as $row) {
        $days_left = (int) round(
            (strtotime($row['data_zakonczenia']) - strtotime($today)) / 86400
        );
        $opiekun_name  = $row['opiekun'] ?? '';
        $opiekun_email = '';
        if (!empty($row['guardian_editor_id'])) {
            $u = db_one(
                "SELECT email, imie_nazwisko FROM users WHERE id = ?",
                [(int) $row['guardian_editor_id']]
            );
            if ($u) {
                $opiekun_email = $u['email'] ?? '';
                if (empty($opiekun_name)) $opiekun_name = $u['imie_nazwisko'] ?? '';
            }
        }

        $all_contracts[] = [
            'type'          => $type,
            'id'            => (int) $row['id'],
            'numer_umowy'   => $row['numer_umowy'] ?? '',
            'imie_nazwisko' => $row['imie_nazwisko'] ?? '',
            'opiekun'       => $opiekun_name,
            'opiekun_email' => $opiekun_email,
            'data_zakonczenia' => $row['data_zakonczenia'],
            'days_left'     => $days_left,
        ];
    }
}

// Sortuj globalnie wg dni
usort($all_contracts, fn($a, $b) => $a['days_left'] <=> $b['days_left']);

// Liczniki urgency
$count_danger  = count(array_filter($all_contracts, fn($c) => $c['days_left'] <= 7));
$count_warning = count(array_filter($all_contracts, fn($c) => $c['days_left'] > 7 && $c['days_left'] <= 14));
$count_info    = count(array_filter($all_contracts, fn($c) => $c['days_left'] > 14 && $c['days_left'] <= 30));
$count_later   = count(array_filter($all_contracts, fn($c) => $c['days_left'] > 30));

$PAGE_TITLE = 'Wygasające umowy';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">
      <i class="bi bi-clock-history text-warning me-2"></i>Wygasające umowy
    </h4>
    <p class="text-muted small mb-0 mt-1">
      Umowy kończące się w ciągu najbliższych 60 dni
    </p>
  </div>
  <form method="post" action="<?= APP_URL ?>/admin/contract_expiry.php" class="d-inline">
    <input type="hidden" name="action" value="send_reminders">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <button type="submit" class="btn btn-primary"
            onclick="return confirm('Wysłać przypomnienia do opiekunów wygasających umów?')">
      <i class="bi bi-send me-1"></i> Wyślij przypomnienia teraz
    </button>
  </form>
</div>

<?php if ($send_result !== null): ?>
<div class="alert alert-<?= $send_result['errs'] > 0 ? 'warning' : 'success' ?> alert-dismissible fade show" role="alert">
  <i class="bi bi-<?= $send_result['errs'] > 0 ? 'exclamation-triangle' : 'check-circle' ?> me-2"></i>
  <strong>Wyniki wysyłania:</strong>
  wysłano <?= $send_result['sent'] ?> przypomnień,
  pominięto <?= $send_result['skip'] ?> (już wysłane lub brak e-mail),
  błędy: <?= $send_result['errs'] ?>.
  Przetworzone z kolejki: <?= $send_result['queue_result']['sent'] ?>.
  <?php if ($send_result['log']): ?>
  <hr class="my-2">
  <ul class="mb-0 small">
    <?php foreach ($send_result['log'] as [$status, $msg]): ?>
    <li class="<?= $status === 'err' ? 'text-danger' : '' ?>"><?= h($msg) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Odznaki podsumowania -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <?php if ($count_danger): ?>
  <span class="badge bg-danger fs-6 px-3 py-2">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    Krytyczne (&le;7 dni): <?= $count_danger ?>
  </span>
  <?php endif; ?>
  <?php if ($count_warning): ?>
  <span class="badge bg-warning text-dark fs-6 px-3 py-2">
    <i class="bi bi-exclamation-circle-fill me-1"></i>
    Pilne (8–14 dni): <?= $count_warning ?>
  </span>
  <?php endif; ?>
  <?php if ($count_info): ?>
  <span class="badge bg-info text-dark fs-6 px-3 py-2">
    <i class="bi bi-info-circle-fill me-1"></i>
    Wkrótce (15–30 dni): <?= $count_info ?>
  </span>
  <?php endif; ?>
  <?php if ($count_later): ?>
  <span class="badge bg-secondary fs-6 px-3 py-2">
    <i class="bi bi-calendar3 me-1"></i>
    Powyżej 30 dni: <?= $count_later ?>
  </span>
  <?php endif; ?>
  <?php if (!$all_contracts && !$filter_type): ?>
  <span class="badge bg-success fs-6 px-3 py-2">
    <i class="bi bi-check-circle-fill me-1"></i> Brak wygasających umów
  </span>
  <?php endif; ?>
</div>

<!-- Filtr wg typu -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
  <span class="text-muted small me-1">Filtruj typ:</span>
  <a href="<?= APP_URL ?>/admin/contract_expiry.php"
     class="btn btn-sm btn-<?= !$filter_type ? '' : 'outline-' ?>secondary">
    Wszystkie
  </a>
  <?php foreach (CONTRACT_TYPES as $slug => $label): ?>
    <?php if (!isset($contract_tables[$slug])) continue; ?>
    <a href="<?= APP_URL ?>/admin/contract_expiry.php?typ=<?= h($slug) ?>"
       class="btn btn-sm btn-<?= $filter_type === $slug ? '' : 'outline-' ?>primary">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($all_contracts)): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle me-2"></i>
  <?= $filter_type
      ? 'Brak wygasających umów dla wybranego typu w ciągu najbliższych 60 dni.'
      : 'Brak umów wygasających w ciągu najbliższych 60 dni.' ?>
</div>
<?php else: ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Typ umowy</th>
          <th>Numer</th>
          <th>Osoba</th>
          <th>Opiekun</th>
          <th>Data zakończenia</th>
          <th class="text-center">Pozostało dni</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $prev_group = null;
      foreach ($all_contracts as $c):
          $days = $c['days_left'];

          // Nagłówek grupy
          $group = match(true) {
              $days <= 7  => 'danger',
              $days <= 14 => 'warning',
              $days <= 30 => 'info',
              default     => 'secondary',
          };
          $group_label = match($group) {
              'danger'    => 'Krytyczne — wygasają za 7 dni lub mniej',
              'warning'   => 'Pilne — wygasają za 8–14 dni',
              'info'      => 'Wkrótce — wygasają za 15–30 dni',
              'secondary' => 'Powyżej 30 dni',
          };
          if ($group !== $prev_group):
              $prev_group = $group;
      ?>
      <tr class="table-<?= $group ?>">
        <td colspan="7" class="fw-semibold small py-1 ps-3">
          <i class="bi bi-<?= match($group) {
              'danger' => 'exclamation-triangle-fill',
              'warning' => 'exclamation-circle-fill',
              'info' => 'info-circle-fill',
              default => 'calendar3',
          } ?> me-1"></i>
          <?= h($group_label) ?>
        </td>
      </tr>
      <?php endif; ?>

      <?php
          $row_class = match($group) {
              'danger'    => 'table-danger',
              'warning'   => 'table-warning',
              'info'      => 'table-info',
              default     => '',
          };
          $type_label = CONTRACT_TYPES[$c['type']] ?? ucfirst($c['type']);
          $view_url   = APP_URL . '/contracts/' . $c['type'] . '/view.php?id=' . $c['id'];
          $data_pl    = date('d.m.Y', strtotime($c['data_zakonczenia']));
          $days_label = ($days === 1) ? 'dzień' : 'dni';
      ?>
      <tr class="<?= $row_class ?>">
        <td>
          <span class="badge bg-<?= match($c['type']) {
              'wolontariat' => 'primary',
              'zlecenie'    => 'success',
              'dzielo'      => 'info',
              'uslugi'      => 'warning',
              'inne'        => 'secondary',
              default       => 'secondary',
          } ?>"><?= h($type_label) ?></span>
        </td>
        <td class="fw-semibold text-nowrap"><?= h($c['numer_umowy']) ?></td>
        <td><?= h($c['imie_nazwisko']) ?></td>
        <td>
          <?php if ($c['opiekun']): ?>
            <?= h($c['opiekun']) ?>
            <?php if ($c['opiekun_email']): ?>
            <br><small class="text-muted"><?= h($c['opiekun_email']) ?></small>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted small">—</span>
          <?php endif; ?>
        </td>
        <td class="text-nowrap"><?= h($data_pl) ?></td>
        <td class="text-center">
          <span class="badge bg-<?= $group ?><?= $group === 'warning' || $group === 'info' ? ' text-dark' : '' ?> fs-6">
            <?= $days ?> <?= $days_label ?>
          </span>
        </td>
        <td class="text-end text-nowrap">
          <a href="<?= h($view_url) ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i> Podgląd
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="text-muted small mt-2">
  Łącznie: <?= count($all_contracts) ?> umów wygasających w ciągu 60 dni.
</p>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

<?php
// ── Pomocnicza funkcja emaila (wywoływana inline przy wysyłce) ─────────────────
function _expiry_email_html(
    string $type_label,
    string $numer,
    string $osoba,
    string $opiekun,
    string $data_zakonczenia,
    int    $days_left,
    string $accent_color
): string {
    $days_label    = ($days_left === 1) ? 'dzień' : 'dni';
    $urgency_text  = $days_left === 1
        ? 'Umowa wygasa <strong>jutro</strong>!'
        : "Umowa wygasa za <strong>{$days_left} {$days_label}</strong>.";
    $opiekun_html  = $opiekun ? ', <strong>' . htmlspecialchars($opiekun) . '</strong>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Przypomnienie o wygaśnięciu umowy</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">
        <tr>
          <td style="background:{$accent_color};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">Przypomnienie o wygasającej umowie</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 16px;font-size:15px;color:#333333;">Dzień dobry{$opiekun_html},</p>
            <p style="margin:0 0 24px;font-size:15px;color:#333333;">{$urgency_text} Prosimy o podjęcie stosownych działań.</p>
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#f8f9fa;border-radius:6px;border-left:4px solid {$accent_color};margin-bottom:24px;">
              <tr><td style="padding:20px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0">
                  <tr>
                    <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Typ umowy</td>
                    <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{$type_label}</td>
                  </tr>
                  <tr>
                    <td style="padding:5px 0;font-size:13px;color:#6c757d;">Numer umowy</td>
                    <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{$numer}</td>
                  </tr>
                  <tr>
                    <td style="padding:5px 0;font-size:13px;color:#6c757d;">Osoba</td>
                    <td style="padding:5px 0;font-size:14px;color:#212529;">{$osoba}</td>
                  </tr>
                  <tr>
                    <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data zakończenia</td>
                    <td style="padding:5px 0;font-size:14px;color:{$accent_color};font-weight:700;">{$data_zakonczenia}</td>
                  </tr>
                  <tr>
                    <td style="padding:5px 0;font-size:13px;color:#6c757d;">Pozostało</td>
                    <td style="padding:5px 0;font-size:14px;color:{$accent_color};font-weight:700;">{$days_left} {$days_label}</td>
                  </tr>
                </table>
              </td></tr>
            </table>
            <p style="margin:0;font-size:14px;color:#495057;">Zaloguj się do systemu, aby sprawdzić szczegóły umowy i podjąć działania (przedłużenie, zakończenie lub anulowanie).</p>
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
