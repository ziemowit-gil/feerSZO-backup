# Umowy zlecenie — ewidencja godzin, przesunięcia, korekty i saldo rozliczeń

Stan na 2026‑10‑06. Implementacja w czystym PHP (feerSZO v1), kod w `includes/zlecenie_rachunki.php` (sekcja „EWIDENCJA GODZIN …” na końcu pliku), interfejs w `contracts/zlecenie/view.php` (zakładki **Godziny** i **Rozliczenia**), formularze `add.php`/`edit.php`, lista `list.php`.

Zasada nadrzędna: **system nie generuje rachunków**. Rachunek wystawia księgowy; system liczy należność z godzin, zestawia ją z rachunkami z rejestru i prowadzi saldo między miesiącami.

## Tabele

| Tabela / kolumna | Rola |
|---|---|
| `umowy_zlecenie.zrodlo_godzin` | `reczne` (wpisy ręczne) lub `karty30` (Dydaktyka – Karty30 dziennik) |
| `umowy_zlecenie.ti_instructor_user_id` | konto prowadzącego w e‑dzienniku (`users.id`) |
| `umowy_zlecenie.ostatnia_synchronizacja_at` | ostatnie pobranie z dziennika |
| `zlecenie_ewidencja_wpisy` | wpis = dzień pracy (ręczny) albo sesja z dziennika (`ti_session_id`, `ti_course_id`, nazwa i kod grupy); liczony do miesiąca swojej daty; unikalność `(contract_id, ti_session_id)` |
| `zlecenie_ewidencja_przesuniecia` | „X godzin z miesiąca A rozliczamy w miesiącu B” (całość lub część), z powodem |
| `zlecenie_rozliczenie_miesiace` | stan miesiąca w saldzie: `otwarty`/`zamkniety`, `wyplata_override`, `naleznosc_override`, uwagi |
| `zlecenie_rozliczenie_korekty` | korekta `+/− zł`, `+/− h`, księgowana w otwartym miesiącu `ym`, z `dotyczy_ym` i opcjonalnym `rachunek_id` |
| `zlecenie_rachunki.rozliczenie_ym` | ręczne przypisanie rachunku (jednomiesięcznego) do miesiąca salda |
| `zlecenie_rachunek_podzial` | rachunek **łączony** (okres_od–okres_do za kilka miesięcy): ręczny podział kwoty na miesiące; bez podziału kwota dzielona proporcjonalnie do należności miesięcy (po równo, gdy należności brak), reszta z zaokrągleń do ostatniego miesiąca |
| `timesheets` (istniejąca) | karta miesięczna; dla miesięcy z wpisami dziennymi utrzymywana automatycznie (suma godzin rozliczanych), bez wpisów = ręczna ewidencja miesięczna admina |

Wszystkie tabele i kolumny tworzą się same (auto‑migracja przy załadowaniu `zlecenie_rachunki.php`).

## Godziny

- **Wpisy dzienne**: data, godziny (0,25–24), opis; data musi mieścić się w okresie umowy; zmiana daty na inny miesiąc przenosi wpis (oba miesiące muszą być otwarte).
- **E‑dziennik Karty30**: przycisk „Pobierz z dziennika” pobiera sesje prowadzącego z `k30_ti_sessions` o statusach `held`, `individual_change`, `remote_material` (bez szkiców), w granicach dat umowy; prowadzący = `s.instructor_id`, albo `c.instructor_id` gdy sesja nie ma własnego, albo współprowadzący (`k30_ti_course_coinstructors`). Godziny = `duration_min/60` z dokładnością do 0,01 (nie zaokrąglamy w górę). Synchronizacja jest idempotentna: dodaje, aktualizuje i usuwa sesje, które zniknęły. Wpis ręczny przy tym źródle wymaga opisu (praca poza dziennikiem). Stawka zawsze z umowy.
- **Ręczna karta miesięczna** (admin): gdy miesiąc nie ma wpisów dziennych, można wpisać jedną liczbę godzin za miesiąc (trafia do `timesheets` ze statusem „zatwierdzone”); 0 usuwa kartę.
- **Przesunięcia**: nie można przesunąć więcej niż miesiąc ma do rozliczenia; oba miesiące muszą być otwarte.

Godziny rozliczane w miesiącu M:

```
godziny_rozl(M) = ewidencja(M) − przesunięte Z M + przesunięte DO M + korekty_godzin(M)
```

## Saldo rozliczeń (zakładka Rozliczenia)

```
naleznosc(M)      = godziny_rozl(M) × stawka   (godzinowo)
                  | stawka_kwota                (miesiecznie)
                  | 0                           (ryczałt — cały ryczałt wchodzi jako saldo początkowe 1. miesiąca)
                  + korekty_kwota(M)            — albo kwota ustalona ręcznie (naleznosc_override)
do_rozliczenia(M) = naleznosc(M) + pozostalo(M−1)
wyplata(M)        = suma rachunków z rejestru przypisanych do M (albo wyplata_override)
pozostalo(M)      = do_rozliczenia(M) − wyplata(M)     → przechodzi do M+1
roznica(M)        = wyplata(M) − naleznosc(M)          → przy stawce godzinowej: brakuje / nadmiar godzin = roznica / stawka
```

Kilka osobnych rachunków na różne kwoty w jednym miesiącu sumuje się do wypłaty tego miesiąca. Rachunek łączony rozkłada się na miesiące (`zl_rozl_rachunek_podzial`), formularz podziału jest w karcie „Rachunki łączone” w zakładce Rozliczenia.

Miesiąc salda rachunku jednomiesięcznego (`zl_rozl_rachunek_ym`): 1) `rozliczenie_ym` ręcznie, 2) ostatni miesiąc zakresu `okres_od`–`okres_do`, 3) miesiąc z tekstu `okres` („sierpień 2026”), 4) miesiąc daty wystawienia. Rachunki testowe i odrzucone pomijane. Rachunki z odgadniętym miesiącem są wylistowane nad tabelą z prośbą o sprawdzenie; „zmień mies.” przy rachunku ustawia przypisanie ręcznie.

**Zamknięcie miesiąca** zamraża wpisy, przesunięcia, przypisania rachunków i korekty tego miesiąca. Błędy w zamkniętym miesiącu poprawia się **korektą** zaksięgowaną w otwartym miesiącu (z polem „dotyczy miesiąca” i opcjonalnie rachunkiem). Ponowne otwarcie wymaga powodu; wszystko trafia do `contract_audit_log` (`log_contract_action`).

`zl_rozl_close_blocker($contract)` zwraca powód, dla którego umowy nie można zakończyć (saldo ≠ 0 albo otwarty miesiąc z godzinami). Jest podpięty w `ContractSettlementGuard::assertClosable()` (`includes/contract_transitions.php`), czyli działa na każdej ścieżce zmiany statusu na zamykający — **tylko dla umów, które mają dane salda** (`zl_rozl_uses_saldo`); stare umowy bez wpisów, przesunięć, korekt i stanów miesięcy nie są blokowane.

## Alerty terminów

- `list.php`: filtry `?konczy=1` (kończące się w ciągu 14 dni) i `?po_term=1` (po terminie, wciąż otwarte), liczniki nad statystykami, znaczniki „za N dn.” / „po terminie” przy dacie zakończenia.
- `view.php`: baner nad zakładkami, gdy do końca umowy ≤ 14 dni albo termin minął, z saldem rozliczeń.
- Automatyczna zmiana statusu po terminie na „do rozliczenia” — istniejący `cron/contract_auto_complete.php`.

## Funkcje (prefiksy)

`zl_ew_*` — ewidencja i e‑dziennik; `zl_przes_*` — przesunięcia; `zl_korekta_*` — korekty; `zl_rozl_*` — saldo, stan miesiąca, rachunki. Obsługa POST w `view.php` przez pole `_zl_ew` (akcje: `sync`, `wpis_add/edit/del`, `miesiac`, `przes_add/del`, `zamknij`, `otworz`, `wyplata`, `naleznosc`, `korekta`, `korekta_del`, `rach_ym`, `rach_podzial`).

## Widok zleceniobiorcy

`panel/rozliczenia_zlecenie.php` (link „Godziny i rozliczenia” w nawigacji panelu i kafelek na stronie głównej, gdy konto ma umowę zlecenie dopasowaną po `m365_user_id`/`m365_login`/`email`): podsumowanie umowy i salda, tabela miesiąc po miesiącu, ewidencja wybranego miesiąca per grupa i dzień, przesunięcia i korekty. Tylko odczyt — wpisy i korekty wprowadza organizacja.

## Co dalej (nie zrobione)

- Agent CRON dociągający dziennik dla otwartych miesięcy umów ze źródłem `karty30` (dziś ręcznie przyciskiem).
