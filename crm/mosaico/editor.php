<?php
/**
 * crm/mosaico/editor.php — bare shell edytora Mosaico (bez header_crm.php,
 * Mosaico przejmuje cały <body>). Osadzany przez crm/mosaico/index.php w iframe.
 *
 * ?template_id=  — edycja istniejącego szablonu (source='mosaico'); brak → tworzy
 *                   nowy szkic i przekierowuje na siebie z nowym id.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$can_write = can_write('crm') || is_admin();
if (!$can_write) { http_response_code(403); exit('Brak uprawnień.'); }

$template_id = (int)($_GET['template_id'] ?? 0);

if (!$template_id) {
    $uid = (int)(current_user()['id'] ?? 0);
    $template_id = db_insert('crm_templates', [
        'name'       => 'Nowy szablon (Mosaico) ' . date('Y-m-d H:i'),
        'channel'    => 'email',
        'subject'    => '',
        'body'       => '',
        'is_active'  => 0,
        'source'     => 'mosaico',
        'created_by' => $uid ?: null,
    ]);
    header('Location: ' . APP_URL . '/crm/mosaico/editor.php?template_id=' . $template_id);
    exit;
}

$tpl = db_one("SELECT * FROM crm_templates WHERE id=?", [$template_id]);
if (!$tpl || $tpl['source'] !== 'mosaico') { http_response_code(404); exit('Nieznany szablon Mosaico.'); }

$assets  = APP_URL . '/assets/mosaico';
$csrf    = csrf_token();
$img_backend   = APP_URL . '/crm/mosaico/img.php';
$email_backend = APP_URL . '/crm/mosaico/dl.php?template_id=' . (int)$template_id . '&_csrf=' . urlencode($csrf);
$upload_url    = APP_URL . '/crm/mosaico/upload.php';
$start_hash    = $tpl['body'] !== ''
    ? APP_URL . '/crm/mosaico/template_source.php?id=' . (int)$template_id
    : $assets . '/templates/versafix-1/template-versafix-1.html';
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=1024, initial-scale=1">
<link rel="shortcut icon" href="<?= h($assets) ?>/favicon.ico" type="image/x-icon" />

<script src="<?= h($assets) ?>/rs/mosaico-libs-and-tinymce.min.js?v=0.18.7"></script>
<script src="<?= h($assets) ?>/rs/mosaico.min.js?v=0.18.7"></script>
<script>
$(function() {
  if (!Mosaico.isCompatible()) { alert('Zaktualizuj przeglądarkę!'); return; }
  window.location.hash = <?= json_encode($start_hash) ?>;

  // Mosaico nie ma domyślnie przycisków Zapisz/Download/Test w tym buildzie —
  // trzeba je zarejestrować samodzielnie przez "plugins" (viewModel.download/.test),
  // wg wzorca z oficjalnych integracji (metoda viewModel.exportHTML() daje finalny,
  // zainlinowany HTML gotowy do zapisania/wysłania).
  var emailBackend = <?= json_encode($email_backend) ?>;
  var plugins = [function(vm) {
    var saveCmd = { name: 'Zapisz szablon', enabled: ko.observable(true) };
    saveCmd.execute = function() {
      saveCmd.enabled(false);
      $.post(emailBackend, { action: 'download', html: vm.exportHTML(), filename: 'szablon.html' })
        .done(function() { vm.notifier.success('Szablon zapisany.'); })
        .fail(function() { vm.notifier.error('Nie udało się zapisać szablonu.'); })
        .always(function() { saveCmd.enabled(true); });
    };
    vm.download = saveCmd;

    var testCmd = { name: 'Wyślij testowy e-mail', enabled: ko.observable(true) };
    testCmd.execute = function() {
      var rcpt = window.prompt('Adres e-mail do testu:');
      if (!rcpt) return;
      testCmd.enabled(false);
      $.post(emailBackend, { action: 'email', html: vm.exportHTML(), rcpt: rcpt, subject: 'Test szablonu' })
        .done(function() { vm.notifier.success('Wysłano testową wiadomość.'); })
        .fail(function() { vm.notifier.error('Nie udało się wysłać testu.'); })
        .always(function() { testCmd.enabled(true); });
    };
    vm.test = testCmd;

    return vm;
  }];

  var ok = Mosaico.init({
    imgProcessorBackend: <?= json_encode($img_backend) ?>,
    emailProcessorBackend: emailBackend,
    titleToken: "Edytor e-maili FEER CRM",
    fileuploadConfig: { url: <?= json_encode($upload_url) ?> }
  }, plugins);
  if (!ok) console.log("Mosaico.init nie powiódł się");
});
</script>

<link rel="stylesheet" href="<?= h($assets) ?>/rs/mosaico-libs-and-tinymce.min.css?v=0.18.7" />
<link rel="stylesheet" href="<?= h($assets) ?>/rs/mosaico-material.min.css?v=0.18.7" />
</head>
<body class="mo-standalone"></body>
</html>
