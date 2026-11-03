<?php
/**
 * modules/mail_footer/view/pulpitNotice.php — pas „Ustaw swoją stopkę” na pulpicie (SZO i Panel
 * współpracownika), szata Metro jak pas profilu w katalogu. Wejście: $mfStatus z mailFooterStatus().
 * Nic nie renderuje, gdy stopka potwierdzona, wymóg wyłączony albo osoba nie ma skrzynki.
 */
if (empty($mfStatus) || empty($mfStatus['pending'])) return;
$_mfReq = $mfStatus['mode'] === 'require';
$_mfCol = $_mfReq ? '#a20025' : '#c25100';
?>
<style>
.mfn-note{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1.25rem;background:#f2f2f2;border-left:6px solid <?= $_mfCol ?>;padding:.9rem 1.1rem;margin:0 0 1.25rem}
.mfn-note .mfn-ic{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $_mfCol ?>;color:#fff;font-size:1.35rem}
.mfn-note .mfn-body{flex:1;min-width:220px}
.mfn-note .mfn-t{font-size:1.05rem;font-weight:600;margin:0;line-height:1.25}
.mfn-note .mfn-s{font-size:.84rem;color:#4b4b4b;margin:.15rem 0 0}
.mfn-note .mfn-btn{display:inline-flex;align-items:center;gap:.4rem;background:#1d1d1d;color:#fff;font-weight:600;font-size:.88rem;padding:.55rem 1rem;text-decoration:none;border:2px solid #1d1d1d}
.mfn-note .mfn-btn:hover,.mfn-note .mfn-btn:focus-visible{background:#fff;color:#1d1d1d}
:root[data-theme="hc"] .mfn-note{border:2px solid #000;border-left-width:6px}
</style>
<section class="mfn-note" role="<?= $_mfReq ? 'alert' : 'region' ?>" aria-labelledby="mfn-t">
  <span class="mfn-ic" aria-hidden="true"><i class="bi bi-pen"></i></span>
  <div class="mfn-body">
    <h2 class="mfn-t" id="mfn-t"><?= $_mfReq ? 'Wymagane: ustaw swoją stopkę w poczcie' : 'Ustaw swoją stopkę w poczcie' ?></h2>
    <p class="mfn-s">Przygotuj podpis wiadomości w panelu poczty, a potem potwierdź to na stronie Zarządzanie pocztą.</p>
  </div>
  <a class="mfn-btn" href="<?= APP_URL ?>/panel/poczta.php"><i class="bi bi-envelope-paper" aria-hidden="true"></i>Zarządzanie pocztą</a>
</section>
