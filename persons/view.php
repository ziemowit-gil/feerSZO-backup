<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';
require_once dirname(__DIR__) . '/includes/address.php';
require_once dirname(__DIR__) . '/includes/envelopes.php';

require_role('admin', 'editor');

$id = intval($_GET['id'] ?? 0);
$person = person_by_id($id);
if (!$person) { http_response_code(404); die('Nie znaleziono osoby.'); }

// ── POST: akcje kwestionariusza ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'gen_questionnaire') {
        $token = person_generate_questionnaire_token($id);
        // Opcjonalnie wyślij e-mailem jeśli podano adres
        $send_to = trim($_POST['send_to'] ?? '');
        if ($send_to && filter_var($send_to, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $url  = questionnaire_url($token);
                $name = $person['imie_nazwisko'] ?: 'Wolontariuszu';
                $html = "
                <p>Witaj <strong>" . h($name) . "</strong>,</p>
                <p>Prosimy o uzupełnienie kwestionariusza wolontariusza dla organizacji <strong>" . h($org) . "</strong>.</p>
                <p>Kliknij poniższy link, aby wypełnić formularz z Twoimi danymi — zajmie to tylko kilka minut:</p>
                <p style='margin:20px 0'>
                  <a href='" . h($url) . "' style='display:inline-block;padding:12px 28px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:700'>
                    Wypełnij kwestionariusz →
                  </a>
                </p>
                <p style='color:#64748b;font-size:.85rem'>
                  Lub skopiuj i wklej poniższy adres do przeglądarki:<br>
                  <a href='" . h($url) . "'>" . h($url) . "</a>
                </p>
                <p style='color:#94a3b8;font-size:.8rem'>Link jest jednorazowy i przypisany do Ciebie.</p>";
                db()->prepare("UPDATE persons SET questionnaire_email=?, questionnaire_sent_at=? WHERE id=?")
                    ->execute([$send_to, date('Y-m-d H:i:s'), $id]);
                mail_queue_add($send_to, '', "Kwestionariusz wolontariusza — " . $org, $html);
                mail_queue_process(1);
                flash_set('success', "Link do kwestionariusza wygenerowany i wysłany na {$send_to}.");
            } catch (\Throwable $e) {
                flash_set('warning', 'Link wygenerowany, ale nie udało się wysłać e-maila: ' . $e->getMessage());
            }
        } else {
            flash_set('success', 'Link do kwestionariusza wygenerowany. Skopiuj go i przekaż wolontariuszowi.');
        }
        header('Location: view.php?id=' . $id . '#questionnaire'); exit;
    }

    if ($action === 'reset_questionnaire') {
        db()->prepare("UPDATE persons SET questionnaire_token=NULL, questionnaire_sent_at=NULL, questionnaire_filled_at=NULL, questionnaire_email=NULL WHERE id=?")
            ->execute([$id]);
        flash_set('success', 'Kwestionariusz zresetowany.');
        header('Location: view.php?id=' . $id . '#questionnaire'); exit;
    }

    if ($action === 'resend_questionnaire') {
        $send_to = trim($_POST['send_to'] ?? $person['questionnaire_email'] ?? $person['email'] ?? '');
        if ($send_to && $person['questionnaire_token']) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $url  = questionnaire_url($person['questionnaire_token']);
                $name = $person['imie_nazwisko'] ?: 'Wolontariuszu';
                $html = "<p>Witaj <strong>" . h($name) . "</strong>,</p>
                <p>Przypominamy o wypełnieniu kwestionariusza wolontariusza dla <strong>" . h($org) . "</strong>.</p>
                <p style='margin:20px 0'>
                  <a href='" . h($url) . "' style='display:inline-block;padding:12px 28px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:700'>
                    Wypełnij kwestionariusz →
                  </a>
                </p>
                <p style='color:#64748b;font-size:.85rem'><a href='" . h($url) . "'>" . h($url) . "</a></p>";
                db()->prepare("UPDATE persons SET questionnaire_email=?, questionnaire_sent_at=? WHERE id=?")
                    ->execute([$send_to, date('Y-m-d H:i:s'), $id]);
                mail_queue_add($send_to, '', "Przypomnienie: kwestionariusz wolontariusza — " . $org, $html);
                mail_queue_process(1);
                flash_set('success', "Przypomnienie wysłane na {$send_to}.");
            } catch (\Throwable $e) {
                flash_set('error', 'Błąd wysyłki: ' . $e->getMessage());
            }
        }
        header('Location: view.php?id=' . $id . '#questionnaire'); exit;
    }
}

$PAGE_TITLE = $person['imie_nazwisko'];

// Fetch linked contracts via UNION ALL on person_id
try {
    $contracts = db_all("
        SELECT 'zlecenie' AS type, id, numer_umowy, status, data_zawarcia FROM umowy_zlecenie WHERE person_id = ?
        UNION ALL
        SELECT 'dzielo', id, numer_umowy, status, data_zawarcia FROM umowy_dzielo WHERE person_id = ?
        UNION ALL
        SELECT 'wolontariat', id, numer_umowy, status, data_zawarcia FROM umowy_wolontariat WHERE person_id = ?
        UNION ALL
        SELECT 'praca', id, numer_umowy, status, data_zawarcia FROM umowy_praca WHERE person_id = ?
        ORDER BY data_zawarcia DESC
    ", [$id, $id, $id, $id]);
} catch (\Throwable $e) { $contracts = []; }

$type_labels = [
    'zlecenie'    => 'Umowa zlecenie',
    'dzielo'      => 'Umowa o dzieło',
    'wolontariat' => 'Porozumienie wolontariackie',
    'praca'       => 'Umowa o pracę',
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-person-vcard text-primary"></i>
    <?= h($person['imie_nazwisko']) ?>
  </h4>
  <div class="d-flex gap-2">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edytuj</a>
    <?= envelope_dropdown_html('person_id=' . (int)$id) ?>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<div class="row g-3">
<div class="col-lg-8">

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane osobowe</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-6"><div class="detail-label">Imię i nazwisko</div><div class="detail-value fw-semibold"><?= h($person['imie_nazwisko']) ?></div></div>
  <div class="col-md-3"><div class="detail-label">PESEL</div><div class="detail-value font-monospace"><?= h($person['pesel']) ?: '—' ?></div></div>
  <div class="col-md-3"><div class="detail-label">Data urodzenia</div><div class="detail-value"><?= date_pl($person['data_urodzenia']) ?></div></div>
  <div class="col-md-6">
    <div class="detail-label">Adres zamieszkania</div>
    <div class="detail-value"><?= address_format($person, true) ?: '—' ?></div>
  </div>
  <?php if ($person['adres_korespondencyjny']): ?>
  <div class="col-md-6">
    <div class="detail-label">Adres korespondencyjny</div>
    <div class="detail-value"><?= nl2br(h($person['adres_korespondencyjny'])) ?></div>
  </div>
  <?php endif; ?>
  <div class="col-md-3"><div class="detail-label">Telefon</div><div class="detail-value"><?= h($person['telefon']) ?: '—' ?></div></div>
  <div class="col-md-3"><div class="detail-label">E-mail</div>
    <div class="detail-value"><?= $person['email'] ? '<a href="mailto:'.h($person['email']).'">'.h($person['email']).'</a>' : '—' ?></div>
  </div>
  <div class="col-md-4"><div class="detail-label">Seria i nr dowodu</div><div class="detail-value"><?= h($person['seria_nr_dowodu']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Urząd skarbowy</div><div class="detail-value"><?= h($person['urzad_skarbowy']) ?: '—' ?></div></div>
  <div class="col-md-4"><div class="detail-label">Rachunek bankowy</div><div class="detail-value font-monospace small"><?= h($person['rachunek_bankowy']) ?: '—' ?></div></div>
  <?php if ($person['uwagi']): ?>
  <div class="col-12"><div class="detail-label">Uwagi</div><div class="detail-value"><?= nl2br(h($person['uwagi'])) ?></div></div>
  <?php endif; ?>
</div>
</div>
</div>

<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
  <span><i class="bi bi-file-earmark-text"></i> Powiązane umowy</span>
  <span class="badge bg-secondary"><?= count($contracts) ?></span>
</div>
<?php if ($contracts): ?>
<div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle">
  <thead class="table-light">
    <tr><th>Typ</th><th>Numer</th><th>Status</th><th>Data zawarcia</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($contracts as $c): ?>
  <tr>
    <td><span class="small text-muted"><?= h($type_labels[$c['type']] ?? $c['type']) ?></span></td>
    <td>
      <a href="<?= APP_URL ?>/contracts/<?= h($c['type']) ?>/view.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none">
        <?= h($c['numer_umowy']) ?>
      </a>
    </td>
    <td><?= status_badge($c['status']) ?></td>
    <td class="small text-muted"><?= date_pl($c['data_zawarcia']) ?></td>
    <td class="text-end">
      <a href="<?= APP_URL ?>/contracts/<?= h($c['type']) ?>/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<div class="card-body text-muted small">Brak powiązanych umów.</div>
<?php endif; ?>
</div>

</div>
<div class="col-lg-4">

<!-- ── Kwestionariusz ─────────────────────────────────────────────────── -->
<?php
$q_status = questionnaire_status($person);
$q_token  = $person['questionnaire_token'] ?? null;
$q_url    = $q_token ? questionnaire_url($q_token) : null;
$status_colors = [
    'filled'    => ['success', 'bi-patch-check-fill', 'Wypełniony'],
    'sent'      => ['primary',  'bi-send-check',       'Wysłany'],
    'generated' => ['warning',  'bi-link-45deg',       'Link wygenerowany'],
    'none'      => ['secondary','bi-clipboard2-x',     'Nie wysłany'],
];
[$sc, $si, $sl] = $status_colors[$q_status];
?>
<div class="card shadow-sm mb-3" id="questionnaire">
<div class="card-header fw-semibold d-flex align-items-center justify-content-between">
  <span><i class="bi bi-clipboard2-heart text-primary me-1"></i> Kwestionariusz</span>
  <span class="badge bg-<?= $sc ?> <?= $sc==='warning'?'text-dark':'' ?>">
    <i class="bi <?= $si ?> me-1"></i><?= $sl ?>
  </span>
</div>
<div class="card-body">

  <?= flash_html() ?>

  <?php if ($q_status === 'filled'): ?>
  <!-- Wypełniony -->
  <div class="alert alert-success py-2 mb-3" style="font-size:.82rem">
    <i class="bi bi-check-circle-fill me-1"></i>
    Wolontariusz wypełnił kwestionariusz
    <?php if ($person['questionnaire_filled_at']): ?>
    dnia <strong><?= date('d.m.Y \g\o\d\z. H:i', strtotime($person['questionnaire_filled_at'])) ?></strong>.
    <?php endif; ?>
  </div>
  <form method="post" onsubmit="return confirm('Zresetować kwestionariusz? Wypełnione dane pozostaną, ale wolontariusz będzie mógł wypełnić formularz ponownie.')">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="reset_questionnaire">
    <button type="submit" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-clockwise me-1"></i>Resetuj (wyślij ponownie)
    </button>
  </form>

  <?php elseif ($q_status === 'none'): ?>
  <!-- Brak tokenu — formularz generowania -->
  <p class="text-muted small mb-3">
    Wygeneruj link i wyślij wolontariuszowi — uzupełni swoje dane samodzielnie.
  </p>
  <form method="post">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="gen_questionnaire">
    <div class="mb-3">
      <label class="form-label small fw-semibold">Wyślij link e-mailem (opcjonalnie)</label>
      <input type="email" name="send_to" class="form-control form-control-sm"
             placeholder="email@wolontariusza.pl"
             value="<?= h($person['email'] ?? '') ?>">
      <div class="form-text">Zostaw puste, żeby tylko wygenerować link (skopiujesz go ręcznie).</div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm w-100">
      <i class="bi bi-link-45deg me-1"></i>Generuj link do kwestionariusza
    </button>
  </form>

  <?php elseif (in_array($q_status, ['generated', 'sent'])): ?>
  <!-- Token istnieje, nie wypełniony -->

  <?php if ($q_status === 'sent' && $person['questionnaire_sent_at']): ?>
  <div class="alert alert-primary py-2 mb-3" style="font-size:.8rem">
    <i class="bi bi-send me-1"></i>
    Wysłano <?= date('d.m.Y H:i', strtotime($person['questionnaire_sent_at'])) ?>
    <?php if ($person['questionnaire_email']): ?>
    na <strong><?= h($person['questionnaire_email']) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Link do skopiowania -->
  <div class="mb-3">
    <label class="form-label small fw-semibold">Link do kwestionariusza</label>
    <div class="input-group input-group-sm">
      <input type="text" class="form-control form-control-sm font-monospace"
             id="q_link_input" value="<?= h($q_url) ?>" readonly style="font-size:.72rem">
      <button class="btn btn-outline-secondary" type="button"
              onclick="navigator.clipboard.writeText('<?= h($q_url) ?>');this.innerHTML='<i class=\'bi bi-check-lg\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-copy\'></i>',1500)">
        <i class="bi bi-copy"></i>
      </button>
    </div>
  </div>

  <!-- Wyślij / Przypomnij -->
  <form method="post" class="mb-2">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="resend_questionnaire">
    <div class="input-group input-group-sm">
      <input type="email" name="send_to" class="form-control form-control-sm"
             placeholder="email wolontariusza"
             value="<?= h($person['questionnaire_email'] ?: $person['email'] ?? '') ?>">
      <button type="submit" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-envelope-arrow-up me-1"></i><?= $q_status==='sent' ? 'Przypomnij' : 'Wyślij' ?>
      </button>
    </div>
  </form>

  <!-- Reset -->
  <form method="post" onsubmit="return confirm('Wygenerować nowy link? Stary przestanie działać.')">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="gen_questionnaire">
    <input type="hidden" name="send_to"  value="">
    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
      <i class="bi bi-arrow-clockwise me-1"></i>Wygeneruj nowy link
    </button>
  </form>

  <?php endif; ?>

</div>
</div>

<!-- Metadata -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold">Metadata</div>
<div class="card-body small text-muted">
  <div class="mb-1"><i class="bi bi-calendar-plus"></i> Dodano: <?= date_pl($person['created_at']) ?></div>
  <div><i class="bi bi-calendar-check"></i> Zmodyfikowano: <?= date_pl($person['updated_at']) ?></div>
</div>
</div>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
