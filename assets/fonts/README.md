# Fonty marki (dokumenty PDF)

Statyczne pliki TTF używane przez mpdf przy generowaniu dokumentów
(m.in. oferty CRM — `crm_offer_pdf_fontdata()` w `includes/crm_offers.php`).
Strony HTML biorą te same rodziny z Google Fonts, więc wydruk z przeglądarki
i PDF wyglądają tak samo.

| Rodzina | Zastosowanie | Źródło | Licencja |
|---|---|---|---|
| Lato | tekst dokumentu | [google/fonts → ofl/lato](https://github.com/google/fonts/tree/main/ofl/lato) | SIL Open Font License 1.1 |
| Montserrat | nagłówki, tytuł dokumentu | [JulietaUla/Montserrat → fonts/ttf](https://github.com/JulietaUla/Montserrat/tree/master/fonts/ttf) (statyczne odmiany; w google/fonts jest tylko wersja variable, której mpdf nie obsługuje) | SIL Open Font License 1.1 |

Wymagane nazwy plików (inne są ignorowane):
`Lato-Regular.ttf`, `Lato-Bold.ttf`, `Lato-Italic.ttf`, `Lato-BoldItalic.ttf`,
`Montserrat-Regular.ttf`, `Montserrat-Bold.ttf`, `Montserrat-Italic.ttf`, `Montserrat-BoldItalic.ttf`.

Gdy plików nie ma, PDF powstaje w DejaVu Sans — dokument dalej się generuje,
tylko bez firmowej typografii.
