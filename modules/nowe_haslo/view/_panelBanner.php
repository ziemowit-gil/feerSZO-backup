<?php
/**
 * modules/nowe_haslo/view/_panelBanner.php — pas „Zmień hasło startowe” na pulpicie (panel współpracownika i pulpit kadry),
 * szata Metro jak dawny pas stopki. Wejście: $pwInit z NoweHasloInitial::enforce() (panel/index.php).
 * Nic nie renderuje, gdy osoba nie ma haseł startowych. Po terminie panel i tak przekierowuje na /nowehaslo.
 */
if (empty($pwInit) || empty($pwInit['rows'])) return;
$_piLeft = $pwInit['deadline'] !== null ? (int)ceil(($pwInit['deadline'] - time()) / 86400) : null;
?>
<style>
.pwi-note{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1.25rem;background:#f2f2f2;border-left:6px solid #a20025;padding:.9rem 1.1rem;margin:0 0 1.25rem}
.pwi-note .pwi-ic{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#a20025;color:#fff;font-size:1.35rem}
.pwi-note .pwi-body{flex:1;min-width:220px}
.pwi-note .pwi-t{font-size:1.05rem;font-weight:600;margin:0;line-height:1.25}
.pwi-note .pwi-s{font-size:.88rem;color:#3b3b3b;margin:.15rem 0 0}
.pwi-note .pwi-btn{display:inline-flex;align-items:center;gap:.4rem;background:#1d1d1d;color:#fff;font-weight:600;font-size:.9rem;padding:.6rem 1rem;min-height:44px;text-decoration:none;border:2px solid #1d1d1d}
.pwi-note .pwi-btn:hover,.pwi-note .pwi-btn:focus-visible{background:#fff;color:#1d1d1d}
.pwi-note .pwi-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
:root[data-theme="hc"] .pwi-note{border:2px solid #000;border-left-width:6px}
</style>
<section class="pwi-note" role="alert" aria-labelledby="pwi-t">
  <span class="pwi-ic" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></span>
  <div class="pwi-body">
    <h2 class="pwi-t" id="pwi-t">Zmień hasło startowe</h2>
    <p class="pwi-s">Masz hasło nadane przez Fundację: <?= h(implode(' oraz ', $pwInit['labels'])) ?>. Ustaw własne.
      <?php if ($_piLeft !== null && $_piLeft > 0): ?>
        Masz na to <?= $_piLeft === 1 ? '1 dzień' : $_piLeft . ' dni' ?> (do <?= h(NoweHasloInitial::dateLabel((int)$pwInit['deadline'])) ?>). Potem panel będzie zablokowany, dopóki go nie zmienisz.
      <?php endif; ?></p>
  </div>
  <a class="pwi-btn" href="<?= APP_URL ?>/nowehaslo"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</a>
</section>
