<?php
/**
 * modules/dhosting_migrator/view/_why.php — „Dlaczego przenosimy pocztę?” (wspólne dla wejścia i strony migracji).
 * Bez nazwy nowego dostawcy (polecenie 2026-11-13). Fakty o Microsofcie (stan na 13.11.2026): koniec bezpłatnych
 * Business Premium i Office 365 E1 dla NGO od 1.07.2025 (wygasają przy odnowieniu, więc w 2026 r. kolejne organizacje
 * je tracą), zgłoszenia utraty dostępu do poczty/OneDrive po wygaśnięciu (Microsoft Q&A), za darmo tylko Business Basic
 * (aplikacje w przeglądarce i telefonie), podwyżka cen części pakietów od 1.07.2026, wycofanie Fundraising and
 * Engagement (wsparcie do 31.12.2026). Awarie 2026 (polecenie 2026-11-13; tylko potwierdzone w kilku źródłach): 22.01 (Outlook,
 * Teams; MO1221364), 31.08 (Exchange Online na świecie, logowanie/wysyłka/odbiór, też Teams/SharePoint/OneDrive; EX1464935,
 * MO1465074; przyczyna wg Microsoftu: konfiguracja uwierzytelniania), 12–13.11 (globalna, 74 kraje, ~140 tys. zgłoszeń,
 * EX1550543/MO1550821; nie atak — ustawienia serwerów po aktualizacji; 13.11 wciąż naprawiana). Pominięte jako niepewne:
 * 16.03, kwiecień/maj, 9.11. Przy zmianie treści sprawdź aktualny stan.
 */
// $whyCollapsed (strona migracji, polecenie 2026-11-13 „uprość”): ta sama treść, zwinięta pod jednym zdaniem — nadal na początku strony
$whyCollapsed = !empty($whyCollapsed);
?>
<section class="dm-sec" aria-labelledby="hWhy">
  <h2 id="hWhy" class="mt-h2">Dlaczego <b>przenosimy pocztę?</b></h2>
  <?php if ($whyCollapsed): ?>
  <details class="dm-more">
    <summary>Microsoft zmienia warunki dla organizacji pozarządowych, a w 2026 r. jego poczta kilka razy nie działała. Rozwiń, żeby przeczytać więcej.</summary>
  <?php endif; ?>
  <div class="mt-group">
    <p><strong>Microsoft kolejny raz zmienia warunki dla organizacji pozarządowych — w tym roku to już nie pierwsza zmiana, która nas dotyka.</strong></p>
    <ul class="dm-list">
      <li><strong>Od 1 lipca 2025 r.</strong> Microsoft zakończył bezpłatne licencje Microsoft 365 Business Premium i Office 365 E1
        dla organizacji pozarządowych. Nie są odnawiane: wygasają przy pierwszym odnowieniu subskrypcji po tej dacie,
        więc w 2026 r. tracą je kolejne organizacje.</li>
      <li><strong>Po wygaśnięciu</strong> część organizacji zgłaszała na forum Microsoftu, że z dnia na dzień straciła dostęp do poczty
        i plików w OneDrive — do czasu kupienia nowych licencji.</li>
      <li><strong>Za darmo zostaje tylko pakiet Business Basic</strong>: poczta i aplikacje Office w przeglądarce i w telefonie,
        bez programów na komputer (np. Outlooka czy Worda). Pełny pakiet trzeba kupić — z rabatem, ale za każdą osobę, co miesiąc.</li>
      <li><strong>Od 1 lipca 2026 r.</strong> Microsoft podniósł ceny części pakietów Microsoft 365.</li>
      <li><strong>Do końca 2026 r.</strong> Microsoft wycofuje też narzędzie dla organizacji pozarządowych
        <span lang="en">Fundraising and Engagement</span> (wsparcie kończy się 31 grudnia 2026 r.).</li>
    </ul>
    <p style="margin-top:.8rem"><strong>Do tego dochodzą awarie. W 2026 r. poczta Microsoft 365 przestawała działać kilka razy — także na całym świecie:</strong></p>
    <ul class="dm-list">
      <li><strong>22 stycznia 2026 r.</strong> — awaria Microsoft 365: Outlook i Teams nie działały u dziesiątek tysięcy osób.</li>
      <li><strong>31 sierpnia 2026 r.</strong> — kilkugodzinna awaria poczty Exchange Online na świecie: nie dało się wysyłać ani odbierać
        wiadomości ani zalogować do poczty; awaria objęła też Teams, SharePoint i OneDrive. Microsoft podał jako przyczynę błąd
        w konfiguracji logowania.</li>
      <li><strong>12–13 listopada 2026 r.</strong> — jedna z największych awarii Microsoft 365: od wieczora 12 listopada poczta w Outlooku,
        Teams, SharePoint i Copilot nie działały w 74 krajach, a zgłoszeń było ok. 140 tysięcy. Dotknęła m.in. urzędy i brytyjską
        służbę zdrowia. Następnego dnia Microsoft wciąż przywracał działanie usług. Według Microsoftu to nie był atak, tylko skutek
        zmian w ustawieniach serwerów po aktualizacji.</li>
    </ul>
    <p style="margin-top:.8rem">Gdy poczta, pliki i spotkania są u jednego dostawcy, jedna jego awaria zatrzymuje pracę całej Fundacji naraz.</p>
    <p>Każda zmiana zasad stawia nas przed wyborem: płacić więcej albo stracić dostęp. Ostatnie wydarzenia pokazały,
      jak bardzo zależymy od jednego dostawcy: gdy zmienia zasady, nie mamy wyboru i musimy się dostosować.
      Dlatego pozbywamy się takiego uzależnienia (ang. <span lang="en">vendor lock-in</span>).</p>
    <p>Poczta to usługa, z której korzysta każdy z nas — dlatego przenosimy ją do polskiego dostawcy,
      u którego koszt nie zależy od zmian w programach Microsoft. Nowa poczta działa na otwartych standardach (IMAP i SMTP),
      więc w razie potrzeby przeniesiemy ją dalej bez zmiany Twojego adresu.</p>
  </div>
  <?php if ($whyCollapsed): ?></details><?php endif; ?>
</section>
