<?php
/**
 * Wzór wbudowany „Umowa PFRON2”: umowa uczestnictwa w szkoleniu indywidualnym finansowanym ze środków PFRON
 * (projekt FEER z 27.06.2026): obowiązki stron, okres próbny, zmiana terminów, kara umowna, informowanie MOPS/PFRON, RODO.
 * Składnia wzoru: modules/doc_generator/logic/docGenEngine.php.
 */
$F = static function (string $key, string $label, string $group, string $type = 'text', array $o = []): array {
    return ['key' => $key, 'label' => $label, 'group' => $group, 'type' => $type] + $o;
};
return [
    'slug' => 'umowa-pfron2',
    'name' => 'Umowa PFRON2 — uczestnictwo w szkoleniu indywidualnym finansowanym ze środków PFRON',
    'category' => 'Umowy',
    'description' => 'Zasady uczestnictwa w szkoleniu skierowanym przez MOPS/PFRON: okres próbny, zmiana terminów, kara umowna, informowanie instytucji finansujących.',
    'fields' => [
        $F('miejscowosc', 'Miejscowość zawarcia', 'Zawarcie umowy', 'text', ['default' => 'Krakowie', 'required' => true, 'hint' => 'w miejscowniku: „w Krakowie”']),
        $F('data_zawarcia', 'Data zawarcia umowy', 'Zawarcie umowy', 'date', ['required' => true, 'default' => '@dzisiaj']),
        $F('reprezentant', 'Reprezentant Fundacji', 'Zawarcie umowy', 'text', ['default' => 'Ziemowita Gila – Prezesa Zarządu', 'required' => true, 'hint' => 'w bierniku, np. „Ziemowita Gila – Prezesa Zarządu”']),
        $F('plec', 'Uczestnik — forma w umowie', 'Uczestnik', 'gender', ['default' => 'K', 'hint' => 'K = Pani, M = Pan', 'client' => 'plec']),
        $F('uczestnik_nazwa', 'Imię i nazwisko uczestnika', 'Uczestnik', 'text', ['required' => true, 'client' => 'name']),
        $F('pesel', 'PESEL', 'Uczestnik', 'text', ['client' => 'pesel']),
        $F('adres', 'Adres', 'Uczestnik', 'text', ['client' => 'adres']),
        $F('telefon', 'Telefon', 'Uczestnik', 'text', ['client' => 'telefon']),
        $F('email', 'E-mail', 'Uczestnik', 'text', ['client' => 'email']),
        $F('skierowanie_data', 'Umowa główna [skierowanie] — z dnia', 'Finansowanie i szkolenie', 'date'),
        $F('znak_sprawy', 'Znak sprawy', 'Finansowanie i szkolenie', 'text', ['suggest' => 'MOPS/…/2026|PFRON/…/2026']),
        $F('godziny', 'Liczba godzin dydaktycznych szkolenia', 'Finansowanie i szkolenie', 'text', ['default' => '30', 'required' => true, 'hint' => 'używana w § 1 i § 3 (w projekcie z 27.06.2026 były tam 30 i 25 — ujednolicono)']),
        $F('termin_zmiany', 'Termin zgłoszenia zmiany terminu (godziny przed zajęciami)', 'Terminy i kara', 'text', ['default' => '48']),
        $F('limit_zmian', 'Maksymalna liczba zmian terminu', 'Terminy i kara', 'text', ['default' => 'pięć', 'hint' => 'słownie', 'suggest' => 'trzy|cztery|pięć|sześć']),
        $F('kara', 'Kara umowna za godzinę (zł)', 'Terminy i kara', 'text', ['default' => '100,00', 'required' => true]),
        $F('kara_slownie', 'Kara umowna — słownie', 'Terminy i kara', 'text', ['default' => 'sto złotych']),
    ],
    'body' => <<<'BODY'
# UMOWA UCZESTNICTWA W SZKOLENIU INDYWIDUALNYM
# FINANSOWANYM ZE ŚRODKÓW PFRON
zawarta w dniu {{data_zawarcia}} w {{miejscowosc}} pomiędzy:
Fundacją Edukacji Empatii Rozwoju FEER z siedzibą w Nowym Sączu (adres: ul. Barbackiego 28/18, 33-300 Nowy Sącz), wpisaną do rejestru stowarzyszeń Krajowego Rejestru Sądowego, którego akta przechowuje Sąd Rejonowy dla Krakowa Śródmieścia w Krakowie Wydział XII Gospodarczy KRS pod numerem 0000779281, posiadającą NIP: 7343570539, reprezentowaną przez: {{reprezentant}}; zwaną dalej „Fundacją”,
a
[Panią|Panem@plec] {{uczestnik_nazwa}}
PESEL: {{pesel}}
adres: {{adres}}
telefon: {{telefon}}
e-mail: {{email}}
[zwaną|zwanym@plec] dalej „Uczestnikiem”.
## § 1.
## Przedmiot umowy
1. Przedmiotem niniejszej umowy jest określenie zasad uczestnictwa Uczestnika w indywidualnym szkoleniu finansowanym ze środków Państwowego Funduszu Rehabilitacji Osób Niepełnosprawnych (PFRON), realizowanym za pośrednictwem właściwego Miejskiego Ośrodka Pomocy Społecznej lub innej jednostki uprawnionej do finansowania szkolenia – umowa główna [skierowanie] z dnia {{skierowanie_data}} - znak sprawy: {{znak_sprawy}}
2. Szkolenie obejmuje łącznie {{godziny}} godzin dydaktycznych.
3. Szkolenie realizowane będzie zgodnie z indywidualnie ustalanym harmonogramem.
4. Integralną część niniejszej umowy stanowi Regulamin uczestnictwa w szkoleniach Fundacji.
## § 2.
## Oświadczenia Uczestnika
Uczestnik oświadcza, że:
a) został poinformowany o zasadach finansowania szkolenia;
b) wie, że środki publiczne przekazywane są Fundacji przed zakończeniem szkolenia;
c) ma świadomość, że Fundacja rezerwuje dla niego czas pracy trenera, zasoby organizacyjne oraz możliwość udziału w szkoleniu kosztem innych osób oczekujących na wsparcie;
d) rozumie, że nieuzasadnione odwoływanie zajęć powoduje rzeczywiste koszty organizacyjne oraz może skutkować obowiązkiem zwrotu części środków publicznych i składania wyjaśnień wobec instytucji finansujących;
e) zobowiązuje się współdziałać z Fundacją w sposób umożliwiający prawidłowe wykonanie szkolenia.
## § 3.
## Obowiązki Fundacji
Fundacja zobowiązuje się do:
a) przeprowadzenia {{godziny}} godzin szkolenia;
b) zapewnienia wykwalifikowanego trenera;
c) pozostawania w gotowości do realizacji szkolenia przez okres jego trwania;
d) ustalania terminów zajęć z uwzględnieniem możliwości Uczestnika;
e) prowadzenia dokumentacji wymaganej przez instytucję finansującą.
## § 4.
## Obowiązki Uczestnika
Uczestnik zobowiązuje się do:
a) uczestnictwa we wszystkich zaplanowanych zajęciach;
b) aktywnego współdziałania z Fundacją w realizacji szkolenia;
c) punktualnego rozpoczynania zajęć;
d) niezwłocznego informowania o okolicznościach uniemożliwiających realizację szkolenia;
e) ukończenia szkolenia w terminie nie dłuższym niż 3 miesiące od pierwszych zajęć, chyba że Fundacja wyrazi zgodę na jego przedłużenie.
## § 5.
## Okres próbny
1. W ciągu pierwszych trzech godzin szkolenia Uczestnik może zrezygnować z udziału bez podawania przyczyny. W tym okresie Uczestnik może zgłosić potrzebę zmiany trenera.
2. Po upływie trzech godzin strony uznają, że zaakceptowały sposób realizacji szkolenia oraz zobowiązują się do jego ukończenia w całości.
## § 6.
## Zmiana terminów
1. Terminy zajęć ustalane są wspólnie przez Strony. Uczestnik, z zastrzeżeniem warunku, o którym mowa w ust. 2 poniżej, może dokonać zmiany terminu maksymalnie {{limit_zmian}} razy w całym okresie szkolenia.
2. Zmiana terminu wymaga zgłoszenia najpóźniej {{termin_zmiany}} godzin przed rozpoczęciem zajęć.
3. Zmiana wymaga akceptacji Fundacji. Zgłoszenie dokonane po upływie terminu wskazanego w ust. 2 traktowane jest jako odwołanie zajęć z przyczyn leżących po stronie Uczestnika.
## § 7.
## Kara umowna
1. Uczestnik przyjmuje do wiadomości, że przed każdym szkoleniem Fundacja dokonuje rezerwacji czasu pracy trenera, przygotowuje harmonogram zajęć oraz pozostaje w gotowości do wykonania szkolenia wyłącznie na rzecz danego Uczestnika – co dodatkowo wiąże się z kosztami transportu oraz czasem pracy trenera.
2. Rezerwacja terminu uniemożliwia wykorzystanie tego czasu na realizację szkolenia innych beneficjentów oraz powoduje ponoszenie przez Fundację kosztów organizacyjnych niezależnie od tego, czy szkolenie zostanie przeprowadzone.
3. Strony zgodnie postanawiają, że prawidłowa realizacja niniejszej umowy wymaga współdziałania Uczestnika z Fundacją.
4. W przypadku niewykonania lub nienależytego wykonania obowiązków wynikających z niniejszej umowy z przyczyn leżących wyłącznie po stronie Uczestnika, Fundacja jest uprawniona do naliczenia kary umownej.
5. Kara umowna wynosi {{kara}} zł (słownie: {{kara_slownie}}) za każdą godzinę szkolenia, która nie została zrealizowana wskutek:
a) niestawienia się na umówione zajęcia;
b) odwołania zajęć z naruszeniem terminu {{termin_zmiany}} godzin;
c) przekroczenia dopuszczalnego limitu zmian terminów;
d) przerwania szkolenia po zakończeniu okresu próbnego;
e) odmowy kontynuowania szkolenia bez uzasadnionej przyczyny;
f) innych zawinionych działań lub zaniechań Uczestnika uniemożliwiających wykonanie szkolenia.
6. Kara umowna nie jest naliczana w przypadku:
a) nagłej choroby,
b) hospitalizacji,
c) wypadku,
d) śmierci osoby najbliższej,
e) innych zdarzeń losowych o charakterze nadzwyczajnym, których Uczestnik nie mógł przewidzieć ani im zapobiec.
7. Fundacja może zażądać przedstawienia dokumentów potwierdzających okoliczności wskazane w ust. 6.
8. Łączna wysokość naliczonych kar umownych nie może przekroczyć wartości odpowiadającej liczbie godzin szkolenia niezrealizowanych z przyczyn leżących po stronie Uczestnika.
9. Zapłata kary umownej nie wyłącza prawa Fundacji do dochodzenia odszkodowania przewyższającego jej wysokość, jeżeli poniesiona szkoda jest wyższa.
## § 8.
## Rozwiązanie umowy
Fundacja może rozwiązać niniejszą umowę ze skutkiem natychmiastowym w przypadku:
a) dwukrotnego nieusprawiedliwionego niestawienia się Uczestnika;
b) uporczywego przekładania terminów;
c) przekroczenia limitu zmian terminów;
d) odmowy współpracy;
e) naruszenia Regulaminu;
f) zachowania uniemożliwiającego prowadzenie szkolenia.
## § 9.
## Informowanie instytucji finansujących
Uczestnik przyjmuje do wiadomości, że w przypadku przerwania szkolenia lub niewykonania niniejszej umowy Fundacja może przekazać właściwemu MOPS, PFRON lub innemu podmiotowi finansującemu informacje dotyczące przebiegu realizacji szkolenia oraz przyczyn jego zakończenia w zakresie wymaganym przepisami prawa i zasadami rozliczania środków publicznych.
## § 10.
## Przetwarzanie danych
Zgodnie z art. 13 ust. 1 i 2 Rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 z dnia 27 kwietnia 2016 r. w sprawie ochrony osób fizycznych w związku z przetwarzaniem danych osobowych i w sprawie swobodnego przepływu takich danych oraz uchylenia dyrektywy 95/46/WE (ogólne rozporządzenie o ochronie danych), dalej: RODO - (Dz. Urz. UE L119/1) Fundacja informuje, że:
1. Administratorem danych osobowych Uczestnika jest Fundacja Edukacji Empatii Rozwoju FEER z siedzibą w Nowym Sączu (adres: ul. Barbackiego 28/18, 33-300 Nowy Sącz), wpisana do rejestru stowarzyszeń Krajowego Rejestru Sądowego, którego akta przechowuje Sąd Rejonowy dla Krakowa Śródmieścia w Krakowie Wydział XII Gospodarczy KRS pod numerem 0000779281, posiadająca NIP: 7343570539, kontakt e-mail pod adresem: kontakt@feer.org.pl;
2. dane osobowe Uczestnika przetwarzane są przez Fundację w związku z zawartą umową na wykonanie szkolenia (art. 6 ust. 1 lit b RODO), przy realizacji zadań statutowych, na potrzeby wykonywania niniejszej Umowy i wypełniania innych z tym związanych obowiązków prawnych Fundacji – w tym związanych z faktem, że szkolenie finansowane jest ze środków publicznych. Dla tych celów dane osobowe będą przechowywane przez okres określony przepisami prawa, w tym przepisami o rachunkowości (art. 6 ust. 1 lit c RODO);
3. Uczestnikowi przysługuje również prawo żądania dostępu do danych osobowych ich sprostowania, usunięcia, ograniczenia przetwarzania oraz prawo żądania przeniesienia danych. W celu skorzystania z uprawnień należy skontaktować się z Fundacją na dane wskazane w ust. 1 powyżej;
4. na działania Fundacji w zakresie przetwarzania danych osobowych przysługuje Uczestnikowi skarga do Prezesa Urzędu Ochrony Danych Osobowych, ul. Stawki 2, 00-193 Warszawa;
5. W stosunku do danych osobowych Uczestnika nie są podejmowane czynności polegające na zautomatyzowanym podejmowaniu decyzji, w tym profilowaniu, jak i nie są przekazywane do państw trzecich;
6. dane osobowe Uczestnika mogą być przekazywane innym podmiotom, w szczególności podmiotom finansującym szkolenie (MOPS/PFRON), oraz podmiotom, z którymi Fundacja współpracuje (odbiorcy) w zakresie usług informatycznych, serwisowych, transportowych, internetowych i prawnych, na potrzeby realizacji powyższych celów.
## § 11.
## Postanowienia końcowe
1. W sprawach nieuregulowanych zastosowanie mają przepisy Kodeksu cywilnego.
2. Wszelkie zmiany niniejszej umowy wymagają formy pisemnej pod rygorem nieważności.
3. Spory wynikłe z wykonania umowy strony będą starały się rozwiązać polubownie, a gdy to okaże się niemożliwe oraz w przypadku braku porozumienia - spór rozstrzygać będzie właściwy miejscowo sąd powszechny, ustalony ze względu na miejsce wykonania umowy szkoleniowej.
4. Umowę wraz z załącznikiem sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.
[[podpisy: Fundacja~{{reprezentant}} || Uczestnik~{{uczestnik_nazwa}}]]
Załącznik: Regulamin uczestnictwa w szkoleniach Fundacji.
BODY,
];
