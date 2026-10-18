<?php
/**
 * modules/collaborator_data/view/sign.php — podpis wniosku SZD kodem jednorazowym: podgląd zmian (tabela jak
 * we wniosku), pole kodu, ponowna wysyłka, anulowanie. Zmienne: $req, $rows, $user, $token.
 */
$PAGE_TITLE = 'Podpisz wniosek SZD';
$_shell = function_exists('panel_volunteer_shell') && panel_volunteer_shell(current_user());
$ROOT = dirname(__DIR__, 3);
if ($_shell) { $user = current_user(); include $ROOT . '/panel/includes/header_panel.php'; }
else include $ROOT . '/includes/header.php';
$hasApproval = (bool)array_filter($rows, fn($r) => $r[3]);
?>
<style>
.cds{max-width:860px;font-family:"Segoe UI",system-ui,sans-serif;--a:#0050ef;--d:#1d1d1d;--m:#5f5f5f}
.cds *{border-radius:0!important}
.cds-k{font-size:.68rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#003bb0}
.cds h1{font-size:2rem;font-weight:300;margin:.15rem 0 .4rem}
.cds-lead{color:var(--m);font-size:.9rem}
.cds-doc{background:#f2f2f2;padding:1rem 1.15rem;margin:1rem 0}
.cds-doc h2{font-size:.95rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin:0 0 .6rem}
.cds table{width:100%;border-collapse:collapse;background:#fff;font-size:.88rem}
.cds th{font-size:.66rem;text-transform:uppercase;letter-spacing:.1em;color:var(--m);border-bottom:2px solid var(--d);padding:.45rem .6rem;text-align:left}
.cds td{border-bottom:1px solid #e1e1e1;padding:.45rem .6rem}
.cds-acc{font-size:.62rem;font-weight:700;background:#c25100;color:#fff;padding:.08rem .35rem;margin-left:.3rem}
.cds-code{display:flex;flex-wrap:wrap;gap:0;align-items:stretch;margin-top:.6rem}
.cds-code input{width:12rem;height:52px;border:2px solid #8a8a8a;font-size:1.6rem;letter-spacing:.35em;text-align:center;font-family:ui-monospace,Menlo,monospace}
.cds-code input:focus{border-color:var(--a);outline:none}
.cds-code button{border:0;height:52px;padding:0 1.4rem;background:var(--a);color:#fff;font-weight:600}
.cds-code button:hover{background:#003bb0}
.cds-alt{display:flex;flex-wrap:wrap;gap:2px;margin-top:1rem}
.cds-alt button{border:0;height:38px;padding:0 1rem;background:#f2f2f2;color:var(--d);font-size:.85rem}
.cds-alt button:hover{background:#e6e6e6}
</style>
<div class="cds">
  <div class="cds-k">Zmiana danych · wniosek SZD</div>
  <h1>Podpisz wniosek o zmianę danych</h1>
  <p class="cds-lead">Zmiany wejdą dopiero po podpisaniu i złożeniu wniosku. Kod wysłaliśmy
    <?= ($req['channel'] ?? '') === 'email' ? 'e-mailem na adres' : 'SMS-em na numer' ?> <b><?= h((string)($req['sent_to'] ?? '—')) ?></b>; jest ważny 10 minut.</p>
  <?= flash_html() ?>

  <section class="cds-doc" aria-labelledby="cds-h">
    <h2 id="cds-h">Wniosek o zmianę danych w systemie · <?= h((string)($user['name'] ?? '')) ?></h2>
    <table>
      <tr><th>Pole / dana</th><th>Dotychczasowa wartość</th><th>Nowa wartość</th></tr>
      <?php foreach ($rows as [$label, $old, $new, $acc]): ?>
      <tr><td><?= h($label) ?><?php if ($acc): ?><span class="cds-acc">akceptacja</span><?php endif; ?></td><td><?= h($old) ?></td><td><b><?= h($new) ?></b></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($hasApproval): ?><p class="small text-muted mt-2 mb-0">Pola oznaczone „akceptacja” wejdą po zatwierdzeniu przez administratora — dostaniesz powiadomienie.</p><?php endif; ?>
  </section>

  <form method="post" action="<?= h(route('cdc.sign.do', ['token' => $token])) ?>" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <label for="cds_code" class="fw-semibold">Kod podpisu</label>
    <div class="cds-code">
      <input id="cds_code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code" aria-describedby="cds_code_h">
      <button type="submit"><i class="bi bi-pen me-1" aria-hidden="true"></i>Podpisz i złóż wniosek</button>
    </div>
    <div id="cds_code_h" class="small text-muted mt-1">Podpisując potwierdzasz, że dane są prawdziwe i że to Ty wnioskujesz o ich zmianę.</div>
  </form>
  <div class="cds-alt">
    <form method="post" action="<?= h(route('cdc.sign.resend', ['token' => $token])) ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><button type="submit"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Wyślij kod ponownie</button></form>
    <?php if (($req['channel'] ?? '') !== 'email' && filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)): ?>
    <form method="post" action="<?= h(route('cdc.sign.resend', ['token' => $token])) ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="channel" value="email"><button type="submit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Nie dotarł SMS? Wyślij kod e-mailem</button></form>
    <?php endif; ?>
    <form method="post" action="<?= h(route('cdc.sign.cancel', ['token' => $token])) ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><button type="submit"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Anuluj zmiany</button></form>
  </div>
</div>
<?php
if ($_shell) include $ROOT . '/panel/includes/footer_panel.php';
else include $ROOT . '/includes/footer.php';
