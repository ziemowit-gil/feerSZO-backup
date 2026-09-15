// Wariant GUI (dwuklik, natywne okna zamiast terminala) — budowany osobno:
//   go build -tags gui -ldflags "-H=windowsgui" -o szocert-gui.exe   (Windows)
//   go build -tags gui -o szocert-gui                                 (macOS, spakuj w .app)
//
// Platformowe okna (wybór pliku / hasło / komunikat) są w gui_darwin.go
// i gui_windows.go — ten plik tylko spina przepływ, wspólny dla obu.
//go:build gui

package main

import "fmt"

func main() {
	if !identityExists() {
		p12Path, ok := guiChooseFile()
		if !ok {
			return
		}
		p12Password, ok := guiAskPassword("Hasło do pliku certyfikatu (od administratora):")
		if !ok {
			return
		}
		protectPassword, ok := guiAskPassword("Ustaw hasło zabezpieczające SzoCert (będzie proszone przy KAŻDYM uruchomieniu):")
		if !ok {
			return
		}
		if len(protectPassword) < 4 {
			guiShowDialog("Błąd — SzoCert", "Hasło zabezpieczające musi mieć co najmniej 4 znaki.")
			return
		}
		if err := importP12(p12Path, p12Password, protectPassword); err != nil {
			guiShowDialog("Błąd — SzoCert", "Import nie powiódł się: "+err.Error())
			return
		}
		guiShowDialog("SzoCert", "Zaimportowano certyfikat. Teraz podaj hasło zabezpieczające, żeby uruchomić nasłuch.")
	}

	unlockPassword, ok := guiAskPassword("Hasło zabezpieczające SzoCert:")
	if !ok {
		return
	}
	id, err := loadIdentity(unlockPassword)
	if err != nil {
		guiShowDialog("Błąd — SzoCert", err.Error())
		return
	}

	if !guiAutostartInstalled() {
		if guiAskYesNo("SzoCert", "Uruchamiać SzoCert automatycznie przy starcie systemu? (i tak trzeba będzie podać hasło zabezpieczające)") {
			if err := guiInstallAutostart(); err != nil {
				guiShowDialog("Błąd — SzoCert", "Nie udało się dodać do autostartu: "+err.Error())
			}
		}
	}

	go func() { _ = serveIdentity(id) }()

	guiShowDialog("SzoCert uruchomiony", fmt.Sprintf(
		"Zalogowany jako: %s\nWażny do: %s\nNasłuch: 127.0.0.1:52117\n\nZamknij to okno, żeby wyłączyć SzoCert.",
		id.Cert.Subject.CommonName, id.Cert.NotAfter.Format("2006-01-02"),
	))
}
