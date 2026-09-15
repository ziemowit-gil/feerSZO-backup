//go:build gui && darwin

package main

import (
	"fmt"
	"os/exec"
	"strings"
)

func guiChooseFile() (string, bool) {
	out, err := exec.Command("osascript", "-e",
		`POSIX path of (choose file with prompt "Wybierz plik certyfikatu (.p12/.pfx)" of type {"p12","pfx"})`,
	).Output()
	if err != nil {
		return "", false
	}
	return strings.TrimSpace(string(out)), true
}

func guiAskPassword() (string, bool) {
	script := `display dialog "Hasło do pliku certyfikatu:" default answer "" with hidden answer with title "SzoCert" buttons {"Anuluj","OK"} default button "OK"`
	out, err := exec.Command("osascript", "-e", script).Output()
	if err != nil {
		return "", false
	}
	s := string(out)
	idx := strings.Index(s, "text returned:")
	if idx < 0 {
		return "", false
	}
	rest := s[idx+len("text returned:"):]
	if end := strings.Index(rest, ", button returned:"); end >= 0 {
		rest = rest[:end]
	}
	return strings.TrimRight(rest, "\n"), true
}

func guiShowDialog(title, text string) {
	escText := strings.ReplaceAll(text, `\`, `\\`)
	escText = strings.ReplaceAll(escText, `"`, `\"`)
	escText = strings.ReplaceAll(escText, "\n", "\\n")
	escTitle := strings.ReplaceAll(title, `"`, `\"`)
	script := fmt.Sprintf(`display dialog "%s" with title "%s" buttons {"Zamknij"} default button "Zamknij"`, escText, escTitle)
	_ = exec.Command("osascript", "-e", script).Run()
}
