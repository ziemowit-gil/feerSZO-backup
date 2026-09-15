# SzoCert — logowanie certyfikatem X.509 (klucz prywatny lokalnie)

Aplikacja kliencka do logowania w feerSZO certyfikatem X.509, jako alternatywa
dla ręcznego uploadu pliku `.p12` w [auth/login.php](../../auth/login.php).
Certyfikat wciąż wystawia administrator w
[admin/x509_login.php](../../admin/x509_login.php) (bez zmian) — SzoCert tylko
zastępuje "wklej plik + hasło przy każdym logowaniu" jednorazowym importem.

## Jak to działa

1. **Import (jednorazowo)** — `szocert import certyfikat.p12`. Klucz prywatny
   trafia do lokalnego pliku (`~/Library/Application Support/SzoCert/` na
   macOS, `%APPDATA%\SzoCert\` na Windows) i **nigdy więcej nie opuszcza tego
   komputera**.
2. **Logowanie** — `szocert serve` uruchamia nasłuch na `127.0.0.1:52117`.
   Strona logowania (`auth/_login_modals.php`) wykrywa aplikację (`GET /ping`),
   pobiera jednorazowe wyzwanie z serwera (`auth/x509_challenge.php`), wysyła
   je do SzoCert (`POST /sign`), które podpisuje je kluczem prywatnym i zwraca
   podpis + certyfikat publiczny. Serwer weryfikuje podpis
   (`includes/x509_login.php` → `x509_verify_challenge()`) — hasło do pliku
   `.p12` nie jest już nigdzie potrzebne po imporcie.

To ten sam wzorzec architektoniczny co istniejąca integracja ePodpisu
(`includes/ezd_rsign.php` — mSzafir/rSign/SIGILLUM), tylko własną aplikacją
zamiast cudzych programów do podpisu kwalifikowanego.

## Budowanie

```bash
cd client-apps/szocert
go mod tidy

# macOS (uniwersalny arm64+amd64)
GOOS=darwin GOARCH=arm64 go build -o /tmp/szocert-arm64 .
GOOS=darwin GOARCH=amd64 go build -o /tmp/szocert-amd64 .
lipo -create /tmp/szocert-arm64 /tmp/szocert-amd64 -output szocert-macos

# Windows
GOOS=windows GOARCH=amd64 go build -o szocert-windows.exe .
```

## Ograniczenia MVP (do rozważenia później)

- Klucz prywatny leży w zwykłym pliku (uprawnienia 0600 na Unix) — bez
  integracji z Keychain (macOS) / DPAPI (Windows) / TPM.
- Brak autostartu — `szocert serve` trzeba uruchomić ręcznie przed
  logowaniem i zostawić okno otwarte.
- CORS ograniczony do `*.feer.org.pl` / `*.ngosystem.pl` / localhost — patrz
  `server.go` → `isAllowedOrigin()`.
