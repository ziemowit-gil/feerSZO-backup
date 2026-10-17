<?php
/**
 * modules/skierowania_badania/view/_umowaPanel.php — panel „Badania lekarskie” w karcie umowy (wolontariat / zlecenie).
 * Dołączany w contracts/{wolontariat,zlecenie}/view.php obok panelu EZD. Wymaga w scope: $TYPE, $id ($USER opcjonalnie — inaczej current_user()).
 */
if (!in_array($TYPE ?? '', ['wolontariat', 'zlecenie'], true)) return;
if (!in_array((($USER ?? null) ?: current_user())['role'] ?? '', ['admin', 'editor', 'manager'], true)) return;
require_once dirname(__DIR__) . '/logic/skierowania.php';
$_skbBase = rtrim(APP_URL, '/') . '/modules/skierowania_badania/router.php';
try { $_skbRows = skbForContract($TYPE, (int)$id); } catch (\Throwable $e) { $_skbRows = []; }
// Skierowania wystawione tej osobie przed zawarciem umowy (PESEL / imię i nazwisko) — do podpięcia jednym kliknięciem
try { $_skbPre = isset($row) && is_array($row) ? skbPreMatches($row, $TYPE) : []; } catch (\Throwable $e) { $_skbPre = []; }
?>
<div class="card mb-3" id="contract-skb-panel">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-heart-pulse text-primary" aria-hidden="true"></i><strong>Badania lekarskie (medycyna pracy)</strong>
    <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= h($_skbBase . '/nowe?' . http_build_query(['type' => $TYPE, 'id' => (int)$id])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Skierowanie</a>
  </div>
  <?php foreach ($_skbPre as $_p): ?>
  <form method="post" action="<?= h($_skbBase . '/' . (int)$_p['id'] . '/umowa') ?>" class="card-body border-bottom bg-warning-subtle small d-flex flex-wrap align-items-center gap-2">
    <?= csrf_field() ?><input type="hidden" name="contract" value="<?= h($TYPE . ':' . (int)$id) ?>"><input type="hidden" name="back" value="umowa">
    <span>Skierowanie <a href="<?= h($_skbBase . '/' . (int)$_p['id']) ?>"><?= h($_p['numer']) ?></a> wystawione tej osobie przed zawarciem umowy
      (<?= h(date('d.m.Y', strtotime((string)$_p['data_wystawienia']))) ?>).</span>
    <button class="btn btn-sm btn-warning ms-auto">Powiąż z tą umową</button>
  </form>
  <?php endforeach; ?>
  <?php if ($_skbRows): ?>
  <ul class="list-group list-group-flush small">
  <?php foreach ($_skbRows as $_s): [$_sl, $_sc] = SKB_STATUSY[$_s['status']] ?? [$_s['status'], 'secondary']; ?>
    <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
      <a href="<?= h($_skbBase . '/' . (int)$_s['id']) ?>"><?= h($_s['numer']) ?></a>
      <span><?= h(SKB_RODZAJE[$_s['rodzaj']] ?? '') ?>, <?= h(date('d.m.Y', strtotime((string)$_s['data_wystawienia']))) ?></span>
      <span class="badge text-bg-<?= h($_sc) ?>"><?= h($_sl) ?></span>
      <?php if ($_s['wazne_do']): ?><span class="<?= $_s['wazne_do'] < date('Y-m-d') ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">ważne do <?= h(date('d.m.Y', strtotime((string)$_s['wazne_do']))) ?></span><?php endif; ?>
      <a class="ms-auto" href="<?= h($_skbBase . '/' . (int)$_s['id'] . '/pdf') ?>" target="_blank" rel="noopener">PDF<span class="visually-hidden"> <?= h($_s['numer']) ?></span></a>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <div class="card-body text-body-secondary small">Brak skierowań na badania lekarskie do tej umowy.</div>
  <?php endif; ?>
</div>
