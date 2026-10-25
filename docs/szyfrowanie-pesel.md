# Szyfrowanie PESEL w bazie (field_crypto)

Kod: `modules/field_crypto/` (biblioteka, warstwa db(), rejestr kolumn), narzędzie: `cli/fieldCrypto.php`.

## Jak działa
- **AES-256-GCM**, kontekst „tabela.kolumna” (szyfrogram nie da się przenieść do innej kolumny), format `enc1:<kid>:<dane>`.
- **Odczyt**: `db()` odszyfrowuje wartości zarejestrowanych kolumn przy każdym `db_one/db_all/fetch*/foreach` — także w JOIN-ach, `SELECT *` i aliasach. Kod aplikacji czyta PESEL jawnie jak dotąd.
- **Zapis**: kod zapisuje jawnie; po żądaniu, które coś zapisało do zarejestrowanej tabeli, „zamiatanie” (`fcSweep`) szyfruje wartości i uzupełnia indeksy ślepe. Wyjątek: zapisy przez `PDO::exec()` — domknie je kolejny zapis do tabeli albo `php cli/fieldCrypto.php encrypt --apply`.
- **Szukanie**: po zaszyfrowanej kolumnie nie działa `WHERE pesel = ?`, `LIKE`, `SUBSTR`. Używamy `fcMatchPesel(tabela, kolumna, wartość, alias)` (dokładny, 11 cyfr) i `fcMatchLast5(...)` (ostatnie 5 cyfr; kolumna z `'l5' => true`). Oba działają także na jeszcze jawnych wierszach. **Wyszukiwanie fragmentu PESEL-u (LIKE) działa tylko na jawnych wierszach** — po zaszyfrowaniu zostaje wyszukiwanie dokładne.
- **Włączenie**: dopiero z `DATA_KEY` w `config.local.php` (`php cli/fieldCrypto.php keygen`). Bez klucza nic się nie zmienia.

## Zarejestrowane kolumny (13)
donations.donor_pesel, pelnomocnictwa.pelnomocnik_pesel, skierowania_badania.pesel, rodo_authorizations.person_pesel, rodo_deletion_log.auth_pesel, k30_clients.pesel, crm_contacts.pesel, umowy_wolontariat.pesel*, umowy_zlecenie.pesel*, umowy_dzielo.pesel*, umowy_praca.pesel*, onboarding_volunteers.pesel, persons.pesel, users.rpts_pesel (* = z indeksem ostatnich 5 cyfr).
Poza zakresem (pola mieszane NIP/PESEL albo PESEL/data): umowy_uslugi.nip_pesel, umowy_inne.pesel_nip_krs, ti_nabor_submissions.participant_birth_date_or_pesel.

## Wdrożenie
1. `php cli/fieldCrypto.php keygen` → `DATA_KEY` do `config.local.php` (+ kopia klucza poza serwerem).
2. **Kopia bazy.**
3. `php cli/fieldCrypto.php status` → `encrypt --apply` (szyfruje dotychczasowe jawne wartości i buduje indeksy).
4. Wymiana klucza: stary → `DATA_KEY_PREVIOUS`, nowy → `DATA_KEY`, `rotate --apply`.

## Nowa kolumna
Dopisz wpis z `'auto' => true` w `modules/field_crypto/logic/fcColumns.php` **po** przejrzeniu zapytań SQL, które jej używają w warunkach (`WHERE`, `LIKE`, `SUBSTR`, `LENGTH`, `GROUP BY`, `ORDER BY`, JOIN) — zamień je na `fcMatchPesel/fcMatchLast5`.
Kopie bazy (backupy) zawierają szyfrogramy, więc bez `DATA_KEY` są nieczytelne — klucz musi być przechowywany osobno.
