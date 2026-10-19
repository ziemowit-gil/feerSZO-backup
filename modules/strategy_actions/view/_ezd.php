<?php
/**
 * modules/strategy_actions/view/_ezd.php — sekcja „EZD” na karcie działania: koszulki powiązane z działaniem
 * (znak, segregator, liczniki pism / dokumentów / załączników / notatek), założenie nowej, przypięcie po znaku, odpięcie.
 * Zmienne: $id, $a, $ezdCases.
 */
?>
<section id="ezd" aria-labelledby="dz-ezd-h">
  <h2 class="dz-head" id="dz-ezd-h">EZD — koszulki <small><?= count($ezdCases) ?></small></h2>
  <?php foreach ($ezdCases as $c): ?>
  <div class="dz-ezd<?= $c['open'] ? '' : ' closed' ?>">
    <a href="<?= h($c['url']) ?>" class="t"><i class="bi bi-folder2-open" aria-hidden="true"></i> <?= h($c['title']) ?></a>
    <small class="d-block dz-muted">Znak <b><?= h($c['znak']) ?></b><?= $c['segregator'] ? ' · segregator ' . h($c['segregator']) : '' ?><?= $c['open'] ? '' : ' · zamknięta' ?></small>
    <small class="d-block">pism: <?= (int)$c['pisma'] ?> · dokumentów: <?= (int)$c['dokumenty'] ?> · załączników: <?= (int)$c['zalaczniki'] ?> · notatek: <?= (int)$c['notatki'] ?></small>
    <form method="post" action="<?= h(ActionsService::url($id . '/ezd/' . (int)$c['id'] . '/odepnij')) ?>" class="mt-1" onsubmit="return confirm('Odpiąć koszulkę od działania? Koszulka zostaje w EZD.')">
      <?= Router::csrfField() ?>
      <button type="submit" class="dz-btn dz-btn--ghost dz-btn--sm" aria-label="Odepnij koszulkę <?= h($c['znak']) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i>Odepnij</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$ezdCases): ?><p class="dz-muted small">Działanie nie ma jeszcze koszulki w EZD Wirtualne biurko.</p><?php endif; ?>
  <form method="post" action="<?= h(ActionsService::url($id . '/ezd/koszulka')) ?>" class="mb-2">
    <?= Router::csrfField() ?>
    <button type="submit" class="dz-btn dz-btn--sm"<?= empty($a['cel_statutowy']) ? ' disabled aria-describedby="dz-ezd-cel"' : '' ?>><i class="bi bi-folder-plus" aria-hidden="true"></i><?= $ezdCases ? 'Załóż kolejną koszulkę' : 'Załóż koszulkę w EZD' ?></button>
    <?php if (empty($a['cel_statutowy'])): ?><small id="dz-ezd-cel" class="d-block dz-muted mt-1">Najpierw ustaw cel statutowy §6 — koszulka trafia do segregatora w jego klasie JRWA.</small><?php endif; ?>
  </form>
  <form method="post" action="<?= h(ActionsService::url($id . '/ezd/przypnij')) ?>" class="dz-ms-add">
    <?= Router::csrfField() ?>
    <div class="full">
      <label class="dz-lbl" for="dz-ezd-znak">Przypnij istniejącą koszulkę (znak)</label>
      <input id="dz-ezd-znak" name="znak" class="dz-in" placeholder="np. 32.001.1.2026" maxlength="60" required>
    </div>
    <div class="full"><button type="submit" class="dz-btn dz-btn--ghost dz-btn--sm"><i class="bi bi-link-45deg" aria-hidden="true"></i>Przypnij</button></div>
  </form>
</section>
