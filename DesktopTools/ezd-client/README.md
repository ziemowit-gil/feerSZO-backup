# EZD Client (Electron)

Offline-first klient EZD + CRM dla macOS i Windows. Dane lokalnie w SQLCipher, zmiany w kolejce `outbox`
wysyłanej na serwer (`POST /api/v1/desktop/sync` — endpoint do dopisania po stronie PHP).

- `npm run dev` — tryb deweloperski · `npm run typecheck` · `npm run test:smoke` (pod ABI Electrona)
- `npm run dist:mac` → `.dmg` + `.pkg` (universal) · `npm run dist:win` → `.exe` (NSIS) + `.msi`
- Install: gdy brak Xcode CLT lub Node bez prebuildów: `npm i --ignore-scripts && npx electron-builder install-app-deps`

Bezpieczeństwo: sandbox + contextIsolation, brak Node w rendererze, walidacja Zod każdego kanału IPC,
CSP, klucz DB w `safeStorage` (Keychain/DPAPI), renderer nie podaje ścieżek plików (wybór dialogiem w main).

Szkielety do uzupełnienia: podpis (`services/sign.ts`), OCR, konfiguracja IMAP w UI, kalendarz systemowy,
endpoint synchronizacji i pobieranie zmian z serwera.

## Podgląd UI i dostępność
`npm run preview` uruchamia sam renderer z atrapą API (src/renderer/devMock.ts) pod http://localhost:5199 — do przeglądu wyglądu i testów axe-core. Atrapa ładuje się tylko w trybie DEV bez Electrona.

## Build
- `npm run dist:win` buduje .exe (NSIS) i na końcu przywraca natywny moduł pod macOS — build pod Windows zostawia w node_modules binarkę Windows i bez tego testy oraz `npm run dev` padają.
- `npm run dist:mac` buduje .dmg (arm64). Bez certyfikatu Apple podpis jest tylko ad-hoc; po zbudowaniu `.app` popraw go: `codesign --force --deep --sign - "dist/mac-arm64/EZD Client.app"` i złóż dmg z `--prepackaged`.
- Lokalizacja: język i formaty w `src/shared/locale.ts` (pl-PL), menu po polsku w `src/main/menu.ts`.
