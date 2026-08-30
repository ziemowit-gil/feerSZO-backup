<?php
/**
 * karty30/ti/_owncloud_pomoc.php — Instrukcja „Mój dysk” (ownCloud), wspólna
 * dla panelu kursanta i dydaktyka. Włączane po zdefiniowaniu:
 *   $owncloud_pomoc_id  string  prefiks ID elementów akordeonu (unikalny per strona)
 */
$_oc_url = rtrim(owncloud_setting('url'), '/') ?: '(adres wskazany w przycisku „Otwórz ownCloud” powyżej)';
$_oc_id  = $owncloud_pomoc_id ?? 'oc-pomoc';
?>
<div class="accordion mt-3" id="<?= h($_oc_id) ?>" style="max-width:720px">
  <div class="accordion-item">
    <h3 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($_oc_id) ?>-co">
        <i class="bi bi-question-circle me-2" aria-hidden="true"></i>Czym jest „Mój dysk” i jak z niego korzystać?
      </button>
    </h3>
    <div id="<?= h($_oc_id) ?>-co" class="accordion-collapse collapse" data-bs-parent="#<?= h($_oc_id) ?>">
      <div class="accordion-body small" style="line-height:1.7">

        <p class="fw-semibold mb-1">„Mój dysk” umożliwia:</p>
        <ul class="mb-3">
          <li>przechowywanie plików na zdalnym serwerze,</li>
          <li>łatwy dostęp do plików przez przeglądarkę,</li>
          <li>synchronizowanie plików pomiędzy wieloma urządzeniami,</li>
          <li>współdzielenie plików z innymi użytkownikami systemu SZO,</li>
          <li>udostępnianie plików osobom z zewnątrz (bez konta w systemie SZO),</li>
          <li>podgląd zawartości niektórych plików wprost w przeglądarce: TXT, ODF (np. OpenOffice), PDF.</li>
        </ul>
        <p class="text-body-secondary mb-3">
          Wszelkie problemy zgłaszaj opiekunowi lub administratorowi systemu.
        </p>

        <hr>

        <h4 class="h6 fw-bold mb-2">Dostęp do plików</h4>
        <p class="mb-2">Do plików na „Moim dysku” dostaniesz się przez:</p>
        <ul class="mb-3">
          <li>przeglądarkę internetową, pod adresem <code><?= h($_oc_url) ?></code>,</li>
          <li>aplikację mobilną (Android/iOS),</li>
          <li>aplikację synchronizującą na komputerze (Windows/Linux/macOS),</li>
          <li>bezpieczny protokół WebDAV (szyfrowany, przez HTTPS).</li>
        </ul>

        <p class="mb-1"><strong>Przeglądarka</strong></p>
        <p class="mb-3 text-body-secondary">
          Po zalogowaniu loginem i hasłem swojego konta „Mój dysk" pod adresem
          <code><?= h($_oc_url) ?></code> od razu widzisz swoje pliki i foldery.
        </p>

        <p class="mb-1"><strong>Aplikacje mobilne</strong></p>
        <p class="mb-3 text-body-secondary">
          Odnośnik do aplikacji na Androida i iOS znajdziesz w ustawieniach konta po
          zalogowaniu (menu w prawym górnym rogu przeglądarki, adres e-mail konta →
          „Osobiste”).
        </p>

        <p class="mb-1"><strong>Aplikacja synchronizująca (komputer)</strong></p>
        <p class="mb-2 text-body-secondary">
          Pod adresem <a href="https://owncloud.org/sync-clients/" target="_blank" rel="noopener">owncloud.org/sync-clients</a>
          znajdziesz aplikację, która dwukierunkowo synchronizuje wskazany folder na
          Twoim komputerze z „Moim dyskiem”. Zmiana pliku w jedną albo w drugą stronę
          zostanie odwzorowana wszędzie.
        </p>
        <p class="mb-3 text-body-secondary">
          <strong>Uwaga:</strong> synchronizacja działa w obie strony — skasowanie
          pliku lub całego folderu podlegającego synchronizacji usunie go
          <strong>ze wszystkich</strong> zsynchronizowanych lokalizacji (innych
          komputerów oraz „Mojego dysku”), bez możliwości cofnięcia z poziomu
          samej aplikacji.
        </p>

        <p class="mb-1"><strong>Protokół WebDAV</strong></p>
        <p class="mb-2 text-body-secondary">
          Większość systemów operacyjnych obsługuje dostęp do zasobów przez WebDAV
          natywnie. W Windows konfiguruje się go tak samo jak mapowanie dysku
          sieciowego — w nazwie folderu wpisz adres serwera i wybierz „Połącz
          używając innej nazwy użytkownika”, a potem podaj swój login i hasło.
          W Linuksie WebDAV zwykle wspierają wprost środowiska graficzne (Gnome,
          KDE) albo komenda <code>mount</code> (pakiet <code>davfs2</code>).
        </p>
        <p class="mb-0 text-body-secondary">
          Parametry połączenia WebDAV:<br>
          URL: <code><?= h($_oc_url) ?>/remote.php/webdav/</code><br>
          Port: 443 (HTTPS)<br>
          Login/hasło: takie samo jak do „Mojego dysku”
        </p>

      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h3 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($_oc_id) ?>-pliki">
        <i class="bi bi-folder2-open me-2" aria-hidden="true"></i>Dodawanie i zarządzanie plikami
      </button>
    </h3>
    <div id="<?= h($_oc_id) ?>-pliki" class="accordion-collapse collapse" data-bs-parent="#<?= h($_oc_id) ?>">
      <div class="accordion-body small" style="line-height:1.7">
        <p class="mb-2">
          Przyciski „Nowy” i „Prześlij” u góry listy plików pozwalają odpowiednio: założyć
          nowy folder/plik tekstowy wprost na serwerze, albo wysłać pliki ze swojego
          komputera. Można też po prostu przeciągnąć plik z komputera na okno przeglądarki
          (metoda „przeciągnij i upuść”).
        </p>
        <p class="mb-2">Po najechaniu kursorem na plik lub folder pojawiają się opcje, które pozwalają:</p>
        <ul class="mb-2">
          <li>zmienić nazwę,</li>
          <li>pobrać na dysk lokalny,</li>
          <li>udostępnić innej osobie,</li>
          <li>usunąć.</li>
        </ul>
        <p class="mb-0 text-body-secondary">
          Pobieranie i usuwanie można wykonać także dla wielu obiektów naraz —
          zaznacz je (ikonka po lewej stronie nazwy), a potem wybierz czynność.
        </p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h3 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($_oc_id) ?>-udostep">
        <i class="bi bi-share me-2" aria-hidden="true"></i>Udostępnianie plików
      </button>
    </h3>
    <div id="<?= h($_oc_id) ?>-udostep" class="accordion-collapse collapse" data-bs-parent="#<?= h($_oc_id) ?>">
      <div class="accordion-body small" style="line-height:1.7">
        <p class="mb-2">
          Opcja „Udostępnij” pojawia się po najechaniu kursorem na plik lub folder.
          Udostępnianie jest rekursywne — udostępniając folder, udostępniasz też
          wszystko, co w nim (i w jego podfolderach) jest.
        </p>
        <p class="mb-1"><strong>Komu można udostępnić:</strong></p>
        <ul class="mb-2">
          <li>
            <strong>Osobie z kontem w systemie SZO</strong> — w polu „Współdziel z” wpisz
            imię, nazwisko, login albo e-mail tej osoby. Po zalogowaniu się na swoje
            konto „Mój dysk” zobaczy udostępniony zasób.
          </li>
          <li>
            <strong>Osobie bez konta</strong> — zaznacz „Współdziel wraz z odnośnikiem”:
            system wygeneruje link, którym można się podzielić (np. e-mailem wprost
            z tego samego okna). Link można dodatkowo zabezpieczyć hasłem, ustawić
            datę wygaśnięcia, a nawet pozwolić na wgrywanie plików przez ten link
            („Publiczne dodawanie plików”).
          </li>
        </ul>
        <p class="mb-1"><strong>Prawa dostępu</strong> (dla osób z kontem w SZO):</p>
        <ul class="mb-0">
          <li><strong>utwórz</strong> — może dodawać nowe pliki/foldery,</li>
          <li><strong>uaktualnij</strong> — może nadpisywać istniejące pliki,</li>
          <li><strong>usuń</strong> — może kasować pliki,</li>
          <li><strong>może edytować</strong> — wszystkie trzy powyższe naraz,</li>
          <li><strong>współdziel</strong> — nieaktywne (odbiorca nie może dalej udostępniać zasobu).</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h3 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($_oc_id) ?>-sync">
        <i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Synchronizacja z komputerem — instalacja krok po kroku
      </button>
    </h3>
    <div id="<?= h($_oc_id) ?>-sync" class="accordion-collapse collapse" data-bs-parent="#<?= h($_oc_id) ?>">
      <div class="accordion-body small" style="line-height:1.7">
        <ol class="mb-3">
          <li>Pobierz najnowszą aplikację z <a href="https://owncloud.org/sync-clients/" target="_blank" rel="noopener">owncloud.org/sync-clients</a> (Windows/Linux/macOS) i zainstaluj ją.</li>
          <li>Jako adres serwera podaj: <code><?= h($_oc_url) ?></code></li>
          <li>Podaj swój login i hasło do „Mojego dysku”.</li>
          <li>
            Kreator zaproponuje domyślną konfigurację — zsynchronizowanie całej
            zawartości „Mojego dysku” do folderu na komputerze (np.
            <code>C:\Users\[użytkownik]\ownCloud</code>). Możesz to zaakceptować albo
            usunąć ten proces zaraz po instalacji i skonfigurować własny — wskazując
            konkretny folder lokalny i konkretny folder zdalny do zsynchronizowania.
          </li>
        </ol>
        <p class="fw-semibold mb-1">Wskazówki</p>
        <ul class="mb-0">
          <li>
            Nie synchronizuj folderów, których zawartość zmienia się bez przerwy z
            powodu działania innych programów (np. plików programu pocztowego) —
            może to przeciążyć synchronizację albo spowodować błędy.
          </li>
          <li>
            Przy limitowanym transferze danych (np. internet mobilny) pamiętaj, że
            zmiana plików generuje realny ruch sieciowy.
          </li>
          <li>
            Aplikację można zainstalować na kilku komputerach naraz — zmiana na
            jednym pojawi się na „Moim dysku” i na wszystkich pozostałych.
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>
