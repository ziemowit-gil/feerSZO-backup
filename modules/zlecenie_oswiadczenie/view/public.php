<?php
/**
 * modules/zlecenie_oswiadczenie/view/public.php — publiczny start kreatora oświadczeń (bez logowania).
 * Wymaga: $title; opcjonalnie $resume (adres rozpoczętego w tej przeglądarce kreatora), $flash.
 */
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card">
  <h1>Oświadczenia zleceniobiorcy</h1>
  <p>Wypełnij online dwa dokumenty potrzebne do umowy zlecenia:</p>
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
  <?php if (!empty($resume)): ?>
  <p class="note note-info">W tej przeglądarce masz już rozpoczęte oświadczenie. <a href="<?= h($resume) ?>">Wróć do niego</a>.</p>
  <?php endif; ?>
  <form method="post" action="<?= h(route('osw.public')) ?>">
    <?= Router::csrfField() ?>
    <div style="position:absolute;left:-5000px" aria-hidden="true"><label for="f-website">Nie wypełniaj tego pola</label><input type="text" id="f-website" name="website" tabindex="-1" autocomplete="off"></div>
    <button type="submit" class="btn">Rozpocznij wypełnianie</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">Masz już konto z oświadczeniem? <a href="<?= h(route('osw.login')) ?>">Zaloguj się</a>.</p>
</div>
<div class="card small">
  <h2 style="font-size:1rem">Twoje dane</h2>
  <p style="margin:0">Dane z oświadczeń przetwarza Fundacja w celu zawarcia i rozliczenia umowy zlecenia (podatek, ZUS, wypłata wynagrodzenia). Każdy krok zapisuje się od razu; adres strony kreatora jest Twoim kluczem do niego — nie udostępniaj go innym osobom.</p>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
