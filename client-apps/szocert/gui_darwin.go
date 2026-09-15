//go:build gui && darwin

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
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

func guiAskPassword(prompt string) (string, bool) {
	escPrompt := strings.ReplaceAll(prompt, `"`, `\"`)
	script := fmt.Sprintf(`display dialog "%s" default answer "" with hidden answer with title "SzoCert" buttons {"Anuluj","OK"} default button "OK"`, escPrompt)
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

func guiAskYesNo(title, text string) bool {
	escText := strings.ReplaceAll(text, `"`, `\"`)
	escTitle := strings.ReplaceAll(title, `"`, `\"`)
	script := fmt.Sprintf(`display dialog "%s" with title "%s" buttons {"Nie","Tak"} default button "Tak"`, escText, escTitle)
	out, err := exec.Command("osascript", "-e", script).Output()
	if err != nil {
		return false
	}
	return strings.Contains(string(out), "button returned:Tak")
}

func autostartPlistPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "Library", "LaunchAgents", "pl.feer.szocert.plist"), nil
}

func guiAutostartInstalled() bool {
	path, err := autostartPlistPath()
	if err != nil {
		return false
	}
	_, err = os.Stat(path)
	return err == nil
}

// guiInstallAutostart dodaje LaunchAgent uruchamiający SzoCert przy logowaniu.
// Nie omija podania hasła zabezpieczającego — tylko oszczędza ręcznego
// odnajdywania i klikania aplikacji.
func guiInstallAutostart() error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	path, err := autostartPlistPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>pl.feer.szocert</string>
    <key>ProgramArguments</key>
    <array>
        <string>%s</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
</dict>
</plist>
`, exe)
	if err := os.WriteFile(path, []byte(plist), 0644); err != nil {
		return err
	}
	_ = exec.Command("launchctl", "load", path).Run()
	return nil
}
