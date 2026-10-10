<?php
/**
 * karty30/ti/kursant/_alt_office.php — zakładka „Microsoft 365 (EDU)” w widoku alternatywnym.
 * Pokazywana tylko kursantom z aktywnym kontem szkoleniowym (k30_ti_student_accounts.ms_upn).
 * Bez zakładania/usuwania konta — tylko login, skróty do aplikacji i informacja o haśle.
 * Zmienne z index.php: $account.
 */
$_of_upn = trim((string)($account['ms_upn'] ?? ''));
$_of_q   = 'login_hint=' . rawurlencode($_of_upn);
$_of_apki = [
    ['Office (wszystkie aplikacje)', 'grid-3x3-gap', 'https://www.office.com/?' . $_of_q, 'Start Microsoft 365 w przeglądarce'],
    ['Outlook — poczta',            'envelope',     'https://outlook.office.com/mail/?' . $_of_q, 'Szkolna skrzynka e-mail'],
    ['Teams',                       'people',       'https://teams.microsoft.com/?' . $_of_q, 'Spotkania i czat'],
    ['OneDrive — pliki',            'cloud',        'https://www.office.com/launch/onedrive?' . $_of_q, 'Twoje pliki w chmurze'],
    ['Word',                        'file-earmark-word',  'https://www.office.com/launch/word?' . $_of_q, 'Dokumenty tekstowe'],
    ['Excel',                       'file-earmark-excel', 'https://www.office.com/launch/excel?' . $_of_q, 'Arkusze kalkulacyjne'],
];
?>
<h1 class="h5 fw-bold mb-1"><i class="bi bi-microsoft text-primary me-2" aria-hidden="true"></i>Microsoft 365 (EDU)</h1>
<p class="text-body-secondary small mb-3">Twoje konto szkoleniowe Microsoft 365. Aplikacje otwierają się w nowej karcie przeglądarki.</p>

<section class="kp-office-konto mb-3" aria-labelledby="kp-office-konto-h">
  <h2 class="h6 fw-bold mb-2" id="kp-office-konto-h">Twój login</h2>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <span class="font-monospace kp-lic-wartosc" id="kp-office-upn"><?= h($_of_upn) ?></span>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="kp-office-kopiuj" aria-label="Kopiuj login Microsoft 365">
      <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj
    </button>
    <span class="visually-hidden" id="kp-office-live" aria-live="polite"></span>
  </div>
  <p class="small mb-0 mt-2">
    <i class="bi bi-key me-1" aria-hidden="true"></i>Hasło do Microsoft 365 jest takie samo jak do panelu kursanta.
    Po zmianie hasła w <a href="<?= h(APP_URL) ?>/tozsamosc/kursant_haslo.php">Systemie Tożsamości</a> zmienia się też tutaj.
  </p>
</section>

<h2 class="h6 fw-bold mb-2">Aplikacje</h2>
<ul class="kp-office-kafle" aria-label="Aplikacje Microsoft 365">
  <?php foreach ($_of_apki as [$_l, $_i, $_u, $_o]): ?>
  <li>
    <a class="kp-office-kafel" href="<?= h($_u) ?>" target="_blank" rel="noopener">
      <i class="bi bi-<?= h($_i) ?>" aria-hidden="true"></i>
      <span class="kp-office-tytul"><?= h($_l) ?></span>
      <span class="kp-office-opis"><?= h($_o) ?></span>
      <span class="visually-hidden"> (otwiera się w nowej karcie)</span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
<p class="small text-body-secondary mt-3 mb-0">Kłopot z logowaniem? Napisz przez <a href="?tab=problem">Pomoc</a>.</p>

<script>
(function () {
  var b = document.getElementById('kp-office-kopiuj'), live = document.getElementById('kp-office-live');
  if (!b || !navigator.clipboard) return;
  b.addEventListener('click', function () {
    navigator.clipboard.writeText(document.getElementById('kp-office-upn').textContent.trim())
      .then(function () { live.textContent = 'Skopiowano login do schowka.'; });
  });
})();
</script>
