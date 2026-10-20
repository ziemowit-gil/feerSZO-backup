<?php
/**
 * admin/tiLoginyMail.php — e-mail „nowe dane logowania” do kont panelu TI (nowe formaty loginów, TiLoginFormat):
 * edytowalny szablon (org_setting ti_login_mail_subject / ti_login_mail_body, znaczniki {login} itd.), podgląd dla
 * przykładowego odbiorcy, lista odbiorców i przycisk wysyłki (kolejka poczty; domyślnie bez osób, którym ten sam
 * login już wysłano — ti_login_mail_log). Zmianę samych loginów robi cli/tiLoginyNowyFormat.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
require_once dirname(__DIR__) . '/modules/ti_login/logic/TiLoginFormat.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $uid = (int)(current_user()['id'] ?? 0);
    if ($op === 'save') {
        org_setting_set('ti_login_mail_subject', mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 250));
        org_setting_set('ti_login_mail_body', mb_substr(trim((string)($_POST['body'] ?? '')), 0, 20000));
        flash_set('success', 'Zapisano szablon e-maila.');
    } elseif ($op === 'reset') {
        org_setting_set('ti_login_mail_subject', ''); org_setting_set('ti_login_mail_body', '');
        flash_set('success', 'Przywrócono domyślną treść.');
    } elseif ($op === 'send') {
        $c = TiLoginFormat::mailSend(!empty($_POST['resend']), $uid);
        flash_set('success', "Dodano do kolejki poczty: {$c['sent']} e-maili. Pominięto (już wysłane): {$c['skipped']}. Bez adresu e-mail: {$c['no_email']}.");
    }
    header('Location: ' . APP_URL . '/admin/tiLoginyMail.php'); exit;
}

$tpl = TiLoginFormat::mailTemplate();
$rec = TiLoginFormat::mailRecipients();
$withMail = array_filter($rec, fn($r) => filter_var($r['email'], FILTER_VALIDATE_EMAIL));
$toSend = array_filter($withMail, fn($r) => !$r['sent']);
$sample = reset($withMail) ?: ['name' => 'Jan Kowalski', 'login' => 'jan.kowalski', 'kind_label' => 'kursant'];
$PAGE_TITLE = 'E-mail: nowe dane logowania (TI)';
include dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="h4 mb-1"><i class="bi bi-envelope-paper me-2 text-primary" aria-hidden="true"></i>E-mail: nowe dane logowania do panelu TI</h1>
<p class="text-muted small mb-3">Informacja o nowych formatach loginów z indywidualnym loginem odbiorcy. Loginy zmienia <code>php cli/tiLoginyNowyFormat.php --apply</code> (SMS: <code>--sms</code>).</p>
<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card shadow-sm"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <h2 class="h6 fw-bold">Szablon</h2>
      <label class="form-label small mb-0" for="tm-s">Temat</label>
      <input class="form-control mb-2" id="tm-s" name="subject" maxlength="250" value="<?= h($tpl['subject']) ?>">
      <label class="form-label small mb-0" for="tm-b">Treść</label>
      <textarea class="form-control font-monospace" id="tm-b" name="body" rows="22" style="font-size:.85rem"><?= h($tpl['body']) ?></textarea>
      <div class="small text-muted mt-2">Znaczniki: <?php foreach (TiLoginFormat::MAIL_PLACEHOLDERS as $k => $l): ?><code><?= h($k) ?></code> — <?= h($l) ?>; <?php endforeach; ?></div>
      <div class="d-flex gap-2 mt-2"><button class="btn btn-primary btn-sm">Zapisz szablon</button></div>
    </div></form>
    <form method="post" class="mt-1" onsubmit="return confirm('Przywrócić domyślną treść szablonu?')"><?= csrf_field() ?><input type="hidden" name="_op" value="reset">
      <button class="btn btn-link btn-sm text-danger px-0">Przywróć domyślną treść</button></form>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6 fw-bold">Podgląd — <?= h($sample['name']) ?></h2>
      <div class="small fw-semibold mb-1"><?= h(TiLoginFormat::mailRender($tpl['subject'], $sample)) ?></div>
      <div class="border rounded p-2 small bg-light" style="white-space:pre-wrap"><?= h(TiLoginFormat::mailRender($tpl['body'], $sample)) ?></div>
    </div></div>
    <form method="post" class="card shadow-sm border-primary" onsubmit="return confirm('Wysłać e-mail z nowymi danymi logowania do ' + (this.resend.checked ? <?= count($withMail) ?> : <?= count($toSend) ?>) + ' osób?')"><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="_op" value="send">
      <h2 class="h6 fw-bold">Wysyłka</h2>
      <p class="small mb-2">Konta: <?= count($rec) ?> · z adresem e-mail: <?= count($withMail) ?> · jeszcze nie wysłano: <strong><?= count($toSend) ?></strong></p>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="resend" value="1" id="tm-r"><label class="form-check-label small" for="tm-r">Wyślij ponownie także osobom, które już dostały ten login</label></div>
      <button class="btn btn-primary w-100"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij e-mail z nowymi danymi logowania</button>
    </div></form>
  </div>
</div>

<div class="card shadow-sm mt-3"><div class="card-body">
  <h2 class="h6 fw-bold">Odbiorcy</h2>
  <div class="table-responsive" style="max-height:420px"><table class="table table-sm small align-middle mb-0">
    <thead class="table-light"><tr><th scope="col">Osoba</th><th scope="col">Rodzaj</th><th scope="col">Login</th><th scope="col">E-mail</th><th scope="col">Wysłano</th></tr></thead>
    <tbody>
    <?php foreach ($rec as $r): ?>
      <tr><td><?= h($r['name']) ?></td><td><?= h($r['kind_label']) ?></td><td class="font-monospace"><?= h($r['login']) ?></td>
        <td><?= $r['email'] !== '' ? h($r['email']) : '<span class="text-danger">brak</span>' ?></td><td><?= $r['sent'] ? '✓' : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
