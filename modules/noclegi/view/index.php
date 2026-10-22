<?php
/** Moje noclegi (self-service): lista zgłoszeń, rezerwacje z instrukcją zameldowania. Zmienne: $rows, $counts, $cards, $role. */
$PAGE_TITLE = 'Moje noclegi'; $active = 'index';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$d = fn($x) => date('d.m.Y', strtotime($x));
?>
<h1>Moje noclegi</h1>
<p class="ncl-lead">Twoje zgłoszenia i rezerwacje. Po potwierdzeniu zobaczysz tu instrukcję zameldowania — adres, mapę, godziny i (od <?= (int)noclRevealDays() ?> dni przed przyjazdem) kod do drzwi lub skrytki.</p>
<div class="ncl-tiles" role="list" aria-label="Zgłoszenia wg statusu">
  <?php foreach (noclStatusy() as $c => $l): ?><a role="listitem" class="ncl-tile" href="#lista"><b><?= (int)($counts[$c] ?? 0) ?></b><span><?= h($l) ?></span></a><?php endforeach; ?>
</div>
<p><a class="btn btn-primary" href="<?= h(route('nocl.create')) ?>">Zgłoś nocleg</a></p>

<?php foreach ($cards as $rid => $ins): $rq = noclRequestGet($rid); ?>
<section class="ncl-card" aria-labelledby="ins-<?= (int)$rid ?>">
  <h2 id="ins-<?= (int)$rid ?>" class="mt-0">Instrukcja zameldowania: <?= h($ins['facility']['name']) ?>, <?= h($d($ins['arrive'])) ?></h2>
  <dl class="ncl-kv">
    <dt>Adres</dt><dd><?= h($ins['address']) ?><?php if ($ins['map']): ?> — <a href="<?= h($ins['map']) ?>" target="_blank" rel="noopener">mapa (nowa karta)</a><?php endif; ?></dd>
    <dt>Termin</dt><dd><?= h($d($ins['arrive'])) ?> – <?= h($d($ins['leave'])) ?>, <?= (int)$ins['nights'] ?> <?= (int)$ins['nights'] === 1 ? 'noc' : 'noce/nocy' ?></dd>
    <dt>Zameldowanie</dt><dd><?= h($ins['checkin']) ?>, wymeldowanie do <?= h($ins['checkout']) ?></dd>
    <dt>Pokój</dt><dd><?= h($ins['room']) ?> (<?= h($ins['room_type']) ?>)<?= $ins['accessible'] ? ' — pokój dostosowany dla osób z niepełnosprawnościami' : '' ?></dd>
    <?php if ($ins['phone']): ?><dt>Kontakt</dt><dd><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $ins['phone'])) ?>"><?= h($ins['phone']) ?></a></dd><?php endif; ?>
    <?php if ($ins['codes']): ?>
      <?php if ($ins['door_code'] !== ''): ?><dt>Kod do drzwi</dt><dd><span class="ncl-code"><?= h($ins['door_code']) ?></span></dd><?php endif; ?>
      <?php if ($ins['lockbox'] !== ''): ?><dt>Skrytka na klucz</dt><dd><span class="ncl-code"><?= h($ins['lockbox']) ?></span></dd><?php endif; ?>
    <?php else: ?><dt>Kod do drzwi</dt><dd class="ncl-muted">pojawi się tu od <?= h($d($ins['reveal_from'])) ?></dd><?php endif; ?>
  </dl>
  <?php if ($ins['access_info']): ?><p class="mt-2 mb-1"><strong>Jak wejść:</strong><br><?= nl2br(h($ins['access_info'])) ?></p><?php endif; ?>
  <?php if ($ins['rules']): ?><p class="mb-0"><strong>Zasady pobytu:</strong><br><?= nl2br(h($ins['rules'])) ?></p><?php endif; ?>
</section>
<?php endforeach; ?>

<h2 id="lista">Wszystkie zgłoszenia</h2>
<?php if (!$rows): ?><p class="ncl-muted">Nie masz jeszcze zgłoszeń.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table">
  <caption>Moje zgłoszenia noclegów</caption>
  <thead><tr><th scope="col">Nr</th><th scope="col">Termin</th><th scope="col">Osoba</th><th scope="col">Cel</th><th scope="col">Obiekt / pokój</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= h(route('nocl.show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
      <td><?= h($d($r['date_from'])) ?> – <?= h($d($r['date_to'])) ?></td>
      <td><?= h($r['person_name']) ?></td>
      <td><?= h($r['purpose']) ?></td>
      <td><?= $r['room_name'] ? h($r['facility_name'] . ', pok. ' . $r['room_name']) : '<span class="ncl-muted">jeszcze nie przydzielono</span>' ?></td>
      <td><span class="badge <?= h(noclStatusBadge($r['status'])) ?>"><?= h(noclStatusLabel($r['status'])) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
