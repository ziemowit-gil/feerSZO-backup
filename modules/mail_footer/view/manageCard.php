<?php
/**
 * modules/mail_footer/view/manageCard.php — „Zarządzanie pocztą”: krótki tekst i sześć zadań panelu
 * ms365webpanel (stopka, aliasy, hasło, 2FA, autoresponder, przekierowanie). Używa panel/poczta.php
 * i panel/m365.php (szata Metro .pvm). Wejście opcjonalne: $mfHighlight (bool) — wyróżnia „Stopkę”.
 */
$_mfHl = !empty($mfHighlight);
?>
<style>
.pvm .mf-list{list-style:none;margin:0;padding:0;display:grid;gap:8px;grid-template-columns:1fr}
@media(min-width:760px){.pvm .mf-list{grid-template-columns:1fr 1fr}}
@media(min-width:1100px){.pvm .mf-list{grid-template-columns:1fr 1fr 1fr}}
.pvm .mf-item{display:flex;gap:.8rem;align-items:flex-start;height:100%;box-sizing:border-box;padding:.85rem 1rem;background:var(--pvm-bg);border:2px solid #8a8a8a;border-left-width:6px;color:var(--pvm-ink);text-decoration:none}
.pvm .mf-item:hover,.pvm .mf-item:focus-visible{border-color:var(--pvm-acc);outline:0}
.pvm .mf-item.mf-hl{border-color:var(--pvm-acc);background:var(--pvm-light-h)}
.pvm .mf-item i{font-size:1.4rem;color:var(--pvm-acc);flex-shrink:0;line-height:1.2}
.pvm .mf-item b{display:block;font-size:1.02rem;font-weight:600}
.pvm .mf-item small{display:block;color:var(--pvm-muted);font-size:.84rem;line-height:1.35;margin-top:.1rem}
</style>
<section class="mt-group" id="zarzadzanie-poczta" aria-labelledby="mf-h">
  <h2 class="mt-h2" id="mf-h">Zarządzanie <b>pocztą</b></h2>
  <p style="margin:0 0 1rem;font-weight:300;max-width:75ch">Własną skrzynką zajmujesz się w osobnym panelu,
    <a href="<?= h(mailFooterUrl()) ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;font-weight:600">ms365webpanel.feer.org.pl<span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>.
    Zmieniasz tam wyłącznie swoje ustawienia, cudzej poczty nie ruszysz. Wybierz, co chcesz zrobić:</p>
  <ul class="mf-list">
    <?php foreach (mailFooterTasks() as $_slug => [$_label, $_icon, $_desc]): ?>
    <li>
      <a class="mf-item<?= $_mfHl && $_slug === 'stopka' ? ' mf-hl' : '' ?>" href="<?= h(mailFooterUrl($_slug)) ?>" target="_blank" rel="noopener noreferrer">
        <i class="bi <?= h($_icon) ?>" aria-hidden="true"></i>
        <span><b><?= h($_label) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span></b><small><?= h($_desc) ?></small></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
