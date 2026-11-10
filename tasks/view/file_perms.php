<?php
/**
 * tasks/view/file_perms.php — macierz „działanie × rola w obszarze” dla Plików obszarów.
 * Wejście: $roles, $actions, $matrix, $isTypical.
 */
?>
<style>
html body #tsk-main .fp-table{width:100%;max-width:980px;border-collapse:collapse;font-size:.88rem;background:#fff}
html body #tsk-main .fp-table th,html body #tsk-main .fp-table td{padding:.6rem .75rem;border-bottom:1px solid var(--tm-line);vertical-align:middle}
html body #tsk-main .fp-table thead th{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3f3f46;text-align:center;white-space:nowrap}
html body #tsk-main .fp-table thead th:first-child{text-align:left}
html body #tsk-main .fp-table tbody th{font-weight:600;color:var(--tsk-text);text-align:left}
html body #tsk-main .fp-table tbody th small{display:block;font-weight:400;color:var(--tm-muted);font-size:.78rem;margin-top:.1rem}
html body #tsk-main .fp-table td{text-align:center;width:9rem}
html body #tsk-main .fp-table input[type=checkbox]{width:1.15rem;height:1.15rem;accent-color:var(--tsk-green);cursor:pointer}
html body #tsk-main .fp-table input[disabled]{cursor:not-allowed;opacity:.7}
html body #tsk-main .fp-table tbody tr:hover>*{background:#f7f7f7}
html body #tsk-main .fp-roles{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:4px;max-width:980px;margin:0 0 1.25rem;padding:0;list-style:none}
html body #tsk-main .fp-roles li{padding:.6rem .8rem;background:var(--tm-light);border-left:4px solid var(--tm-field);font-size:.82rem;color:#3f3f46}
html body #tsk-main .fp-roles li strong{display:block;color:var(--tsk-text);font-size:.88rem}
html body #tsk-main .fp-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;max-width:980px;margin-top:1rem}
html body #tsk-main .fp-state{margin-left:auto;font-size:.82rem;color:var(--tm-muted)}
</style>

<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · ustawienia administratora</div>
    <h1 class="tp-title">Uprawnienia do plików</h1>
    <p class="tp-lead">Kto co może w Plikach obszarów (foldery na SharePoincie, folder zadania, przypinanie do zadań). Dotyczy tylko plików —
      zadania, komentarze i załączniki zadań mają własne zasady. Rola osoby w obszarze wynika z członkostwa w obszarze lub zespole.</p>
  </div>
</div>

<ul class="fp-roles" aria-label="Role w obszarze">
  <li><strong>Podgląd</strong>Członek obszaru z rolą „podgląd” — ogląda, nie zmienia.</li>
  <li><strong>Członek</strong>Zwykły członek obszaru — pracuje na plikach swoich zadań.</li>
  <li><strong>Lider</strong>Lider (edytor) obszaru — porządkuje foldery i pliki innych.</li>
  <li><strong>Administrator obszaru</strong>Twórca/administrator obszaru i administrator systemu — zawsze wszystko.</li>
</ul>

<form method="post" action="<?= h(route('fileperms.save')) ?>"><?= Router::csrfField() ?>
  <div class="table-responsive">
  <table class="fp-table">
    <caption class="visually-hidden">Macierz uprawnień: działanie na plikach × rola w obszarze</caption>
    <thead>
      <tr>
        <th scope="col">Działanie</th>
        <?php foreach ($roles as $rk => $rl): ?><th scope="col"><?= h($rl) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($actions as $ak => [$al, $ad]): ?>
      <tr>
        <th scope="row"><?= h($al) ?><small><?= h($ad) ?></small></th>
        <?php foreach ($roles as $rk => $rl):
          $on = in_array($rk, $matrix[$ak] ?? [], true); $locked = $rk === 'admin'; ?>
        <td>
          <input type="checkbox" name="perm[<?= h($ak) ?>][]" value="<?= h($rk) ?>" <?= $on ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>
                 aria-label="<?= h($al) ?> — <?= h($rl) ?><?= $locked ? ' (zawsze)' : '' ?>">
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="fp-bar">
    <button type="submit" class="tk-hbtn tk-hbtn--cta"><i class="bi bi-check2" aria-hidden="true"></i>Zapisz uprawnienia</button>
    <button type="submit" name="typical" value="1" class="tk-hbtn" onclick="return confirm('Przywrócić typową macierz uprawnień do plików?')">
      <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Przywróć typowe</button>
    <span class="fp-state"><?= $isTypical ? 'Obecnie: typowe ustawienia.' : 'Obecnie: ustawienia zmienione względem typowych.' ?></span>
  </div>
</form>
