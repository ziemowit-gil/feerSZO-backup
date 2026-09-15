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
	id, err := loadIdentity()
	if err != nil {
		p12Path, ok := guiChooseFile()
		if !ok {
			return
		}
		password, ok := guiAskPassword()
		if !ok {
			return
		}
		if impErr := importP12(p12Path, password); impErr != nil {
			guiShowDialog("Błąd — SzoCert", "Import nie powiódł się: "+impErr.Error())
			return
		}
		id, err = loadIdentity()
		if err != nil {
			guiShowDialog("Błąd — SzoCert", "Import się powiódł, ale odczyt się nie udał: "+err.Error())
			return
		}
		guiShowDialog("SzoCert", "Zaimportowano certyfikat: "+id.Cert.Subject.CommonName)
	}

	go func() { _ = serveIdentity(id) }()

	guiShowDialog("SzoCert uruchomiony", fmt.Sprintf(
		"Zalogowany jako: %s\nWażny do: %s\nNasłuch: 127.0.0.1:52117\n\nZamknij to okno, żeby wyłączyć SzoCert.",
		id.Cert.Subject.CommonName, id.Cert.NotAfter.Format("2006-01-02"),
	))
}
