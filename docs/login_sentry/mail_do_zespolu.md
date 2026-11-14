# Mail do zespołu: Staszek (LoginSentry)

Temat: Poznajcie Staszka, który pilnuje naszych logowań

Treść (ta sama jest w `modules/login_sentry/lib/LoginSentryTemplates.php`, stała `ANNOUNCE_BODY`):

```text
Cześć,

do ekipy IT dołączył Staszek. To skrypt, który co 6 godzin przegląda logowania do SZO i do Systemu Tożsamość (Logto) i sprawdza, czy ktoś obcy nie wszedł na czyjeś konto.

Po co nam Staszek?

Jedno konto FEER otwiera dziś wiele drzwi: SZO, Nextcloud, pocztę i inne usługi. Jeśli ktoś pozna Twoje hasło, na przykład z wycieku z innego serwisu, może zalogować się spokojnie z drugiego końca kraju, a my dowiemy się o tym za późno. Część ataków zatrzymuje Cloudflare, który stoi przed naszymi stronami. Staszek patrzy na to, co przeszło dalej, czyli na udane logowania.

Jak to działa?

- Co 6 godzin Staszek sprawdza adresy IP i przeglądarki, z których ktoś się zalogował.
- Porównuje je z tym, skąd zwykle się logujesz. Każda osoba ma swój wzorzec, który Staszek sam buduje z historii logowań.
- Za nietypowe rzeczy przyznaje punkty: seria błędnych haseł tuż przed udanym logowaniem, nowa sieć i nowa przeglądarka naraz, logowania z kilku miejsc w krótkim czasie, logowanie przez skrypt zamiast przeglądarki.
- Gdy punktów jest trochę, pisze do administratora i nic więcej nie robi.
- Gdy punktów jest dużo, zawiesza konto w SZO i w Systemie Tożsamość i od razu daje znać administratorowi. Administrator sprawdza sprawę i jednym kliknięciem przywraca dostęp, jeśli to był fałszywy alarm.

Samo logowanie z nowego miejsca, na przykład z hotelu albo z telefonu u rodziny, nie wystarczy do blokady.

Staszek ma ludzkie imię tak jak Mietek, Bronek i Gosposia. Łatwiej powiedzieć „Staszek zablokował mi konto” niż podawać nazwę skryptu, a od razu wiadomo, do kogo z IT pisać. Każdy z nich robi jedną rzecz i robi ją po cichu w tle.

Czy musisz coś zrobić?

Nie. Loguj się tak jak zawsze. Jeśli kiedyś nie wpuści Cię SZO ani Nextcloud, a hasło na pewno jest dobre, napisz do nas. Sprawdzimy, co zobaczył Staszek, i odblokujemy konto.

Dobrze też mieć hasło, którego nie używasz nigdzie indziej. Jak je zmienić, przeczytasz w portalu pomocy IT: https://feer.org.pl/uslugi-it

Pozdrawiamy
Zespół IT Fundacji FEER
```
