<?php
/**
 * modules/zlecenie_oswiadczenie/view/public.php — publiczny start kreatora oświadczeń (bez logowania).
 * Wymaga: $title; opcjonalnie $resume (adres rozpoczętego w tej przeglądarce kreatora), $flash.
 */
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card">
  <h1>Wypełnij oświadczenia online</h1>
  <p>Dwa dokumenty potrzebne do umowy zlecenia:</p>
  <ul>
    <li><strong>Oświadczenie dla celów podatkowych i ubezpieczenia ZUS</strong> — na oryginalnym wzorze Fundacji,</li>
    <li><strong>Oświadczenie o numerze rachunku bankowego</strong> (dokument ZLB).</li>
  </ul>
  <h2>Jak to działa</h2>
  <ol>
    <li>Wypełniasz kreator (ok. 10 minut). Przygotuj PESEL, adres zamieszkania, urząd skarbowy i numer rachunku.</li>
    <li>Pobierasz i drukujesz oświadczenia z instrukcją — <strong>sprawdzasz, wpisujesz datę i podpisujesz</strong>.</li>
    <li>Przesyłasz skan albo zdjęcie podpisanych dokumentów przez tę stronę.</li>
    <li>Po przesłaniu skanu zakładamy Ci <strong>konto</strong> z dostępem tylko do Twoich oświadczeń — link do ustawienia hasła wyślemy na podany e-mail. Na koncie sprawdzisz status i ponownie wydrukujesz dokumenty.</li>
  </ol>
</div>
<form method="post" action="<?= h(route('osw.public')) ?>" class="mt-tiles">
  <?= Router::csrfField() ?>
  <div style="position:absolute;left:-5000px" aria-hidden="true"><label for="f-website">Nie wypełniaj tego pola</label><input type="text" id="f-website" name="website" tabindex="-1" autocomplete="off"></div>
  <button type="submit" class="mt-tile w2"><span class="sym" aria-hidden="true">1–6</span><strong>Rozpocznij wypełnianie</strong><small>Kreator krok po kroku, ok. 10 minut</small></button>
  <?php if (!empty($resume)): ?>
  <a class="mt-tile" style="--c:var(--green)" href="<?= h($resume) ?>"><strong>Wróć do rozpoczętego</strong><small>Masz już w tej przeglądarce rozpoczęte oświadczenie</small></a>
  <?php endif; ?>
  <a class="mt-tile" style="--c:var(--dark)" href="<?= h(route('osw.login')) ?>"><strong>Zaloguj się</strong><small>Masz już konto z oświadczeniem</small></a>
</form>
<div class="card small">
  <h2 style="font-size:1rem">Twoje dane</h2>
  <p style="margin:0">Dane z oświadczeń przetwarza Fundacja w celu zawarcia i rozliczenia umowy zlecenia (podatek, ZUS, wypłata wynagrodzenia). Każdy krok zapisuje się od razu; adres strony kreatora jest Twoim kluczem do niego — nie udostępniaj go innym osobom.</p>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
