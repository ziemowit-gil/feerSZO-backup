<?php
/**
 * modules/zlecenie_oswiadczenie/view/account.php — konto oświadczeń: lista własnych oświadczeń i dane z oświadczenia.
 * Wymaga: $acc, $rows (pakiety z _docs), $limited (konto „tylko podgląd oświadczenia”), $szoUser; opcjonalnie $flash.
 */
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card">
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center">
    <h1 style="margin:0">Moje oświadczenia</h1>
    <form method="post" action="<?= h(route('osw.logout')) ?>"><?= Router::csrfField() ?><button type="submit" class="btn btn-sec btn-sm">Wyloguj</button></form>
  </div>
  <p class="muted small" style="margin:6px 0 0">Zalogowano jako <?= h($szoUser['email'] ?? $acc['email']) ?>.</p>
</div>
<?php if ($limited): ?>
<div class="note note-info" role="note">
  <strong>To konto służy do podglądu oświadczenia.</strong> Widzisz tu swoje oświadczenia zleceniobiorcy, ich status i dane —
  możesz je też ponownie wydrukować. Logujesz się adresem <?= h($acc['email']) ?> i swoim hasłem (także na stronie logowania SZO).
  Gdy Fundacja przypisze Ci konto służbowe Microsoft 365, zalogujesz się nim do pełnego panelu.
</div>
<?php elseif ($szoUser): ?>
<div class="note note-success" role="note">Twoje oświadczenia są przypięte do konta w SZO. <a href="<?= h(rtrim(APP_URL, '/')) ?>/panel/index.php">Przejdź do panelu</a>.</div>
<?php endif; ?>

<h2 class="mt-head">Złożone oświadczenia</h2>
<?php $_tileC = ['secondary' => 'var(--muted)', 'info' => 'var(--indigo)', 'warning' => 'var(--orange)', 'success' => 'var(--green)', 'danger' => 'var(--red)']; ?>
<div class="mt-tiles">
<?php foreach ($rows as $_r): [$_sl, $_sc] = OswService::summaryStatus($_r, $_r['_docs']); $_dt = date('d.m.Y', (int)strtotime((string)($_r['submitted_at'] ?: $_r['created_at']))); ?>
  <a class="mt-tile w2" style="--c:<?= $_tileC[$_sc] ?? 'var(--acc)' ?>" href="<?= h(route('osw.a.home', ['oid' => (int)$_r['id']])) ?>">
    <strong>Oświadczenia z <?= h($_dt) ?></strong>
    <small><?= h($_sl) ?><?php foreach (OswService::KINDS as $_k => [, $_short]): $_d = $_r['_docs'][$_k] ?? null; if (!$_d) continue; ?> · <?= h($_short) ?>: <?= h(mb_strtolower(OswService::DOC_STATUS[$_d['status']][0])) ?><?php endforeach; ?></small>
  </a>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="card"><p style="margin:0">Na koncie nie ma jeszcze oświadczeń.</p></div><?php endif; ?>

<h2 class="mt-head" id="dane">Moje dane</h2>
<section class="card" aria-labelledby="dane">
  <p class="small muted">Uzupełnione z ostatniego oświadczenia. Aby je zmienić, popraw oświadczenie albo skontaktuj się z Fundacją.</p>
  <dl class="sum">
    <dt>Imiona i nazwisko</dt><dd><?= h(trim($acc['first_name'] . ' ' . $acc['last_name']) ?: '—') ?></dd>
    <dt>PESEL</dt><dd><?= h($acc['pesel'] ? substr((string)$acc['pesel'], 0, 2) . '•••••••' . substr((string)$acc['pesel'], -2) : '—') ?></dd>
    <dt>Data urodzenia</dt><dd><?= h(OswForm::pl((string)$acc['birth_date']) ?: '—') ?></dd>
    <dt>Adres</dt><dd><?= h($acc['address'] ?: '—') ?></dd>
    <dt>Telefon</dt><dd><?= h($acc['phone'] ?: '—') ?></dd>
    <dt>E-mail (login)</dt><dd><?= h($acc['email']) ?></dd>
  </dl>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
