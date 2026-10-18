<?php
/**
 * modules/directory_profile/view/pulpitNotice.php — pas „Uzupełnij profil w katalogu”
 * na pulpicie (SZO i Panel współpracownika), szata Metro: płaski pas z grubą lewą
 * krawędzią, pasek postępu, lista brakujących pól, jeden przycisk.
 *
 * Wejście: $dpStatus z directoryProfileStatus(). Nic nie renderuje, gdy profil kompletny
 * albo wymóg wyłączony.
 */
if (empty($dpStatus) || $dpStatus['complete'] || $dpStatus['mode'] === 'off') return;
$_dpRequire = $dpStatus['mode'] === 'require';
?>
<style>
.dpf-note{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1.25rem;background:#f2f2f2;border-left:6px solid <?= $_dpRequire ? '#a20025' : '#c25100' ?>;padding:.9rem 1.1rem;margin:0 0 1.25rem;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#1d1d1d}
.dpf-note .dpf-ic{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $_dpRequire ? '#a20025' : '#c25100' ?>;color:#fff;font-size:1.35rem}
.dpf-note .dpf-body{flex:1;min-width:220px}
.dpf-note .dpf-t{font-size:1.05rem;font-weight:600;margin:0;line-height:1.25}
.dpf-note .dpf-s{font-size:.84rem;color:#4b4b4b;margin:.15rem 0 0}
.dpf-note .dpf-bar{height:4px;background:#d9d9d9;margin-top:.5rem;max-width:360px}
.dpf-note .dpf-bar span{display:block;height:100%;background:<?= $_dpRequire ? '#a20025' : '#c25100' ?>}
.dpf-note .dpf-btn{display:inline-flex;align-items:center;gap:.4rem;background:#1d1d1d;color:#fff;font-weight:600;font-size:.88rem;padding:.55rem 1rem;text-decoration:none;border:2px solid #1d1d1d}
.dpf-note .dpf-btn:hover,.dpf-note .dpf-btn:focus-visible{background:#fff;color:#1d1d1d}
:root[data-theme="hc"] .dpf-note{border:2px solid #000;border-left-width:6px}
</style>
<section class="dpf-note" role="<?= $_dpRequire ? 'alert' : 'region' ?>" aria-labelledby="dpf-t">
  <span class="dpf-ic" aria-hidden="true"><i class="bi bi-person-lines-fill"></i></span>
  <div class="dpf-body">
    <h2 class="dpf-t" id="dpf-t"><?= $_dpRequire ? 'Wymagane: uzupełnij swój profil w katalogu' : 'Uzupełnij swój profil w katalogu' ?></h2>
    <p class="dpf-s">Brakuje: <?= h(implode(', ', $dpStatus['missing'])) ?> · uzupełniono <?= (int)$dpStatus['done'] ?> z <?= (int)$dpStatus['total'] ?></p>
    <div class="dpf-bar" role="progressbar" aria-label="Postęp uzupełnienia profilu" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$dpStatus['pct'] ?>"><span style="width:<?= (int)$dpStatus['pct'] ?>%"></span></div>
  </div>
  <a class="dpf-btn" href="<?= APP_URL ?>/directory/profile_edit.php"><i class="bi bi-pencil-square" aria-hidden="true"></i>Uzupełnij profil</a>
</section>
