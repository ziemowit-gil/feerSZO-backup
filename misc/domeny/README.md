# Strony domen technicznych

Fundacja trzyma kilka domen, które **nie są jej stroną**. Wpisanie takiego adresu
w przeglądarce kończyło się pustą stroną albo błędem serwera — czyli wyglądało na
awarię, a nie na zamierzony stan. Te pliki to jednozdaniowa odpowiedź: czym ta
domena jest i co się zaraz stanie.

| Domena | Rola | Zachowanie strony |
|---|---|---|
| `edukacja.cloud` | domena techniczna | odlicza 6 s i przenosi na feer.org.pl |
| `feer.me` | domena techniczna, krótkie odnośniki | odlicza 6 s i przenosi na feer.org.pl |
| `equi.org.pl` | poczta projektów siostrzanych | **bez przekierowania** — tylko wyjaśnienie |

## Wgranie

Każdy plik `<domena>.html` wgrywa się na serwer swojej domeny **jako `index.html`**.
Strony są samodzielne: brak CDN-u, fontów z zewnątrz i osobnych plików CSS/JS —
tło jest kaflem SVG wpisanym w data-URI. Działają na hostingu, który potrafi
wyłącznie serwować plik.

## Zmiana treści

Nie edytuj plików `.html` — powstają z generatora. Zmień `_generuj.php`
(tablica `$DOMENY` na górze) i uruchom:

```
php misc/domeny/_generuj.php
```

## Dlaczego przekierowanie da się zatrzymać

Automatyczne przeniesienie po kilku sekundach zabiera stronę komuś, kto właśnie
czyta, dlaczego tu trafił — dlatego jest przycisk „Zatrzymaj przekierowanie"
(WCAG 2.2.1). Z tego samego powodu nie ma `<meta http-equiv="refresh">`:
odliczania w metatagu nie da się przerwać. Przy wyłączonym JavaScripcie strona
nie przenosi nigdzie, tylko pokazuje odnośnik.

Tło pochodzi z tego samego generatora co reszta systemu — `includes/app_bg.php`.
