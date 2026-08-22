# Wizytówka — cyfrowa wizytówka i portfolio (Native PHP 8 + SQLite)

Samodzielna aplikacja z **trzema układami strony głównej** — jedna kolumna w stylu wizytówki
(jak carrd.co), przyklejona wizytówka z boku (portfolio) albo siatka Bento (jak bento.me) —
przełączane jednym kliknięciem w panelu. Bez kompilacji, bez Composera:
wrzucasz katalog na hosting PHP, wchodzisz na adres — instalator zrobi resztę.

* Backend: **PHP 8.1+**, PDO **SQLite** (plik `database.sqlite`, tworzony automatycznie)
* Frontend: własny CSS (zmienne kolorów z bazy) + **Alpine.js** (lightbox, modale, zakładki) + Bootstrap Icons
* Panel: **Tailwind CSS z CDN** + Alpine — ciemny motyw
* Zero build stepu, zero zależności zewnętrznych po stronie serwera

---

## 1. Struktura plików

```
wizytowka/
├── index.php                  # front controller (jedyne wejście)
├── .htaccess                  # ładne adresy + blokada bazy/katalogów
├── nginx.conf.example         # odpowiednik dla Nginx
├── schema.sql                 # schemat SQLite (users, settings, bento_tiles, pages, galleries, gallery_images)
├── database.sqlite            # baza (tworzona przez instalator, w .gitignore)
├── config.local.php.example   # opcjonalne nadpisania (np. baza poza katalogiem publicznym)
│
├── includes/
│   ├── bootstrap.php          # stałe, bezpieczna sesja, nagłówki, wykrycie instalacji
│   ├── db.php                 # PDO (singleton), q()/q_one()/q_all(), settings_*()
│   ├── helpers.php            # e(), CSRF, flash, slugi, upload obrazów, vCard, wzór tła
│   ├── auth.php               # password_hash/verify, throttling, sesje
│   ├── content.php            # odczyt kafelków/podstron/galerii, kolejność
│   ├── markdown.php           # mini-parser Markdown + sanityzacja HTML
│   ├── icons.php              # zestaw ikon (Bootstrap Icons) i picker
│   ├── installer.php          # schemat, presety, ustawienia domyślne, dane demo
│   └── ui.php                 # słownik klas Tailwind dla panelu
│
├── controllers/
│   ├── front.php              # / , /p/{slug} , /g/{slug} , /vcard.vcf , /sitemap.xml , /robots.txt
│   ├── install.php            # /install (kreator + testy środowiska)
│   └── admin/
│       ├── router.php         # routing /admin/... + admin_render()
│       ├── auth.php           # logowanie, wylogowanie, konto
│       ├── dashboard.php      # pulpit
│       ├── settings.php       # ustawienia strony
│       ├── tiles.php          # menedżer kafelków Bento
│       ├── pages.php          # menedżer podstron
│       └── galleries.php      # menedżer galerii i zdjęć
│
├── views/
│   ├── layout.php             # layout publiczny (SEO, OG, zmienne kolorów)
│   ├── home.php               # strona główna (stack / bento)
│   ├── page.php               # podstrona
│   ├── gallery.php            # galeria + lightbox
│   ├── 404.php , install.php
│   ├── partials/              # hero.php, tile.php, lightbox.php
│   └── admin/                 # layout, login, dashboard, tiles(+form), pages(+form),
│                              # galleries(+form), gallery_images, settings, account, 404
│
├── assets/
│   ├── css/site.css           # cały wygląd części publicznej (2 układy)
│   ├── js/site.js             # komponent Alpine: lightbox
│   └── favicon.svg
│
└── uploads/                   # pliki użytkownika (PHP wyłączony przez .htaccess)
    ├── avatars/  gallery/  tiles/
```

## 2. Instalacja

1. Skopiuj katalog na serwer (Apache z `mod_rewrite` albo Nginx — patrz `nginx.conf.example`).
2. Nadaj prawa zapisu katalogowi aplikacji (baza) i `uploads/`:
   ```bash
   chmod -R 775 uploads && chmod 775 .
   ```
3. Wejdź na adres strony — nastąpi przekierowanie na `/install`.
4. Podaj nazwę strony, imię i nazwisko, wybierz preset wyglądu, utwórz konto administratora.
5. Po instalacji `/install` jest zablokowany, a Ty jesteś zalogowany w `/admin`.

Lokalnie (bez Apache):

```bash
php -S 127.0.0.1:8000 index.php
```

## 3. Adresy (routing)

| Adres | Opis |
|---|---|
| `/` | strona główna (Bento albo jedna kolumna) |
| `/p/{slug}` | podstrona |
| `/g/{slug}` | galeria z lightboxem |
| `/vcard.vcf` | pobranie wizytówki vCard |
| `/sitemap.xml`, `/robots.txt` | generowane dynamicznie |
| `/install` | instalator (tylko przed instalacją) |
| `/admin` | panel: pulpit, kafelki, podstrony, galerie, ustawienia, konto |

Bez `mod_rewrite` działa też forma `?r=/p/slug`.

## 4. Moduły panelu

* **Pulpit** — statystyki, lista braków („Stan wizytówki”), najczęściej odwiedzane strony.
* **Kafelki Bento** — CRUD, kolejność (strzałki), włącz/wyłącz, picker ikon, kolor akcentu, obraz tła.
  Typy: link, podstrona, galeria, ikona social, widżet tekstowy (Markdown), obraz, e-mail, telefon,
  nagłówek sekcji, linia rozdzielająca, embed HTML.
  Rozmiary (siatka Bento): 1×1, 2×1, 1×2, 2×2, pełna szerokość.
* **Podstrony** — Markdown lub HTML, zajawka, obraz nagłówkowy, meta title/description/keywords, `noindex`,
  szkic/publikacja, kolejność, licznik odsłon.
* **Galerie** — wiele galerii, **multi-upload**, opisy alt i podpisy, kolejność, okładka, układ
  (proporcje naturalne / kwadraty), SEO. Front: responsywna siatka + lightbox (klawiatura ←/→/Esc, swipe).
* **Ustawienia** — profil (avatar, bio, nazwisko pogrubione, wyróżnienie), kontakt + vCard,
  wygląd (3 układy, 8 kolorów, font z Google Fonts, animowane tło, 4 gotowe presety), SEO/OG, stopka, analityka.
* **Konto** — zmiana e-maila i hasła (wymaga podania aktualnego hasła).

## 5. Bezpieczeństwo

* Sesje: `httponly`, `samesite=Lax`, `secure` przy HTTPS, `use_strict_mode`, regeneracja ID po logowaniu.
* Hasła: `password_hash()` / `password_verify()` + automatyczny rehash; min. 10 znaków z wielką literą i cyfrą.
* Throttling: 5 nieudanych prób → 5 minut blokady.
* CSRF: token w każdym formularzu, weryfikacja `hash_equals()`, odpowiedź 419.
* Akcje zmieniające dane tylko przez POST (GET → 405).
* SQL: wyłącznie prepared statements (PDO, `EMULATE_PREPARES = false`).
* XSS: `e()` na całym wyjściu; HTML od administratora przechodzi `sanitize_html()`
  (usuwa `script`, `iframe`, `object`, `embed`, `form`, atrybuty `on*`, `javascript:`).
* Uploady: typ MIME z zawartości pliku (`finfo`), weryfikacja `getimagesize()`, limit 8 MB,
  losowe nazwy plików, `uploads/.htaccess` wyłącza wykonywanie PHP.
* `.htaccess` blokuje dostęp do `*.sqlite`, `*.sql`, `config.local.php` oraz katalogów
  `includes/`, `controllers/`, `views/`.

## 6. Kopia zapasowa i przenoszenie

Cała instancja to katalog: **`database.sqlite` + `uploads/`**. Skopiuj oba i masz pełny backup:

```bash
tar czf wizytowka-backup-$(date +%F).tar.gz database.sqlite uploads
```

Na produkcji warto trzymać bazę poza katalogiem publicznym — skopiuj `config.local.php.example`
jako `config.local.php` i odkomentuj `define('DB_FILE', …)`.

## 7. Presety wyglądu

| Preset | Układ | Charakter |
|---|---|---|
| **Coral** | jedna kolumna | jasny (`#F5F5F5`), akcent koralowy `#FF724F`, tekst `#D67F69`, białe pigułki, grube linie sekcji |
| **Studio** | wizytówka z boku | jasny (`#FAF9F7`), akcent amber `#B45309`; dane kontaktowe przyklejone w lewej kolumnie, treść w dwóch kolumnach |
| **Bento** | siatka 4 kolumn | ciemny (`#09090B`), akcent indygo, szkło (blur), kafelki 1×1…2×2 |
| **Mono** | jedna kolumna | biały, minimalny, grafitowy akcent |

Każdy kolor można potem dopracować ręcznie w Ustawieniach → Wygląd.
