<?php
/**
 * auth/reassign_print.php — Oświadczenie o przyjęciu zgłoszenia przepisania
 * konta na nowy adres e-mail (do wydruku i podpisu przez Operatora, potem
 * skan wraca do systemu przez modal "Prześlij skan" w widoku umowy).
 *
 * GET ?req=ID       — id żądania (user_reassignments)
 * GET &preview=1    — bez auto-druku
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/user_reassignment.php';

require_login();
if (!is_admin()) { http_response_code(403); exit('Brak dostępu.'); }

$req_id = (int)($_GET['req'] ?? 0);
$req = db_one("SELECT * FROM user_reassignments WHERE id=? AND admin_user_id=?", [$req_id, (int)current_user()['id']]);
if (!$req) { http_response_code(404); exit('Nie znaleziono żądania.'); }

$org_name   = org_setting('org_name')   ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = org_setting('org_adres')  ?: '';
$org_miasto = org_setting('org_miejscowosc') ?: '';

$preview = !empty($_GET['preview']);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Oświadczenie <?= h($req['doc_number']) ?></title>
<style>
  body { font-family: Georgia, 'Times New Roman', serif; color: #1e293b; max-width: 720px; margin: 2.5rem auto; line-height: 1.6; }
  h1 { font-size: 1.15rem; text-align: center; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem; }
  .doc-num { text-align: center; color: #64748b; font-size: .9rem; margin-bottom: 2rem; }
  .row { margin: .5rem 0; }
  .label { font-weight: 700; }
  .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem 1.2rem; margin: 1.5rem 0; background: #f8fafc; }
  .sign { margin-top: 4rem; display: flex; justify-content: space-between; }
  .sign div { text-align: center; width: 45%; }
  .sign .line { border-top: 1px solid #1e293b; margin-top: 3rem; padding-top: .3rem; font-size: .85rem; color: #475569; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>

<?php if (!$preview): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>

<div class="no-print" style="text-align:right;margin-bottom:1rem">
  <button onclick="window.print()">Drukuj / PDF</button>
</div>

<p><?= h($org_name) ?><?= $org_adres ? ', ' . h($org_adres) : '' ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></p>

<h1>Oświadczenie o przyjęciu zgłoszenia</h1>
<div class="doc-num">Nr <?= h($req['doc_number']) ?> · <?= date_pl($req['created_at']) ?></div>

<p>Niniejszym potwierdzam przyjęcie zgłoszenia dotyczącego zmiany danych konta w systemie
<?= h($org_name) ?> — przepisania konta użytkownika na nowy adres e-mail.</p>

<div class="box">
  <div class="row"><span class="label">Dotychczasowy adres e-mail:</span> <?= h($req['old_email']) ?></div>
  <div class="row"><span class="label">Nowy adres e-mail:</span> <?= h($req['new_email']) ?></div>
  <div class="row"><span class="label">Powód zgłoszenia:</span> <?= h($req['reason']) ?></div>
</div>

<p>Zgłoszenie zostanie zrealizowane (zamknięcie dotychczasowego konta i utworzenie nowego)
po dostarczeniu niniejszego oświadczenia podpisanego przez Operatora.</p>

<div class="sign">
  <div><div class="line">Podpis osoby zgłaszającej</div></div>
  <div><div class="line">Podpis Operatora</div></div>
</div>

</body>
</html>
