//go:build darwin

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

const pemHeartApp = "/Applications/PEM-HEART Signature.app"

// platformConfirm: natywne okno (osascript). Tekst idzie jako argument, nie
// jest wklejany w skrypt — bez ryzyka wstrzyknięcia AppleScriptu z treści maila.
// Domyślny przycisk to „Anuluj”, okno samo znika po 5 minutach (= anuluj).
func platformConfirm(text string) bool {
	out, err := exec.Command("osascript",
		"-e", "on run argv",
		"-e", `display dialog (item 1 of argv) with title "SzoCert — podpis kwalifikowany" buttons {"Anuluj", "Podpisz w PEM-HEART"} default button "Anuluj" cancel button "Anuluj" with icon caution giving up after 300`,
		"-e", "end run", text).Output()
	if err != nil {
		return false
	}
	s := string(out)
	return strings.Contains(s, "button returned:Podpisz w PEM-HEART") && !strings.Contains(s, "gave up:true")
}

func platformSignerInstalled() bool {
	_, err := os.Stat(pemHeartApp)
	return err == nil
}

func platformOpenSigner(path string) error {
	if platformSignerInstalled() {
		if err := exec.Command("open", "-a", pemHeartApp, path).Run(); err == nil {
			// Folder w Finderze ułatwia wskazanie miejsca zapisu podpisu.
			_ = exec.Command("open", "-R", path).Run()
			return nil
		}
	}
	if err := exec.Command("open", "-R", path).Run(); err != nil {
		return err
	}
	return fmt.Errorf("brak aplikacji PEM-HEART Signature w /Applications — plik pokazano w Finderze")
}

// ── Autostart agenta (LaunchAgent) ──────────────────────────────────────────

const qsignAgentLabel = "pl.feer.szocert.podpis"

func qsignAgentPlist() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "Library", "LaunchAgents", qsignAgentLabel+".plist"), nil
}

// qsignInstall kopiuje program do stałego miejsca (żeby autostart nie
// zależał od Pobranych) i rejestruje LaunchAgent uruchamiający „szocert podpis”.
func qsignInstall() error {
	self, err := os.Executable()
	if err != nil {
		return err
	}
	dir, err := identityDir()
	if err != nil {
		return err
	}
	binDir := filepath.Join(dir, "bin")
	if err := os.MkdirAll(binDir, 0700); err != nil {
		return err
	}
	target := filepath.Join(binDir, "szocert")
	if self != target {
		data, err := os.ReadFile(self)
		if err != nil {
			return err
		}
		if err := os.WriteFile(target+".new", data, 0700); err != nil {
			return err
		}
		if err := os.Rename(target+".new", target); err != nil {
			return err
		}
		_ = exec.Command("xattr", "-d", "com.apple.quarantine", target).Run()
	}
	plistPath, err := qsignAgentPlist()
	if err != nil {
		return err
	}
	home, _ := os.UserHomeDir()
	logPath := filepath.Join(home, "Library", "Logs", "SzoCert-podpis.log")
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>%s</string>
    <key>ProgramArguments</key>
    <array><string>%s</string><string>podpis</string></array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>ProcessType</key><string>Interactive</string>
    <key>StandardOutPath</key><string>%s</string>
    <key>StandardErrorPath</key><string>%s</string>
</dict>
</plist>
`, qsignAgentLabel, target, logPath, logPath)
	if err := os.MkdirAll(filepath.Dir(plistPath), 0755); err != nil {
		return err
	}
	if err := os.WriteFile(plistPath, []byte(plist), 0644); err != nil {
		return err
	}
	uid := fmt.Sprint(os.Getuid())
	_ = exec.Command("launchctl", "bootout", "gui/"+uid, plistPath).Run()
	if out, err := exec.Command("launchctl", "bootstrap", "gui/"+uid, plistPath).CombinedOutput(); err != nil {
		return fmt.Errorf("launchctl bootstrap: %v %s", err, strings.TrimSpace(string(out)))
	}
	fmt.Printf("Zainstalowano: %s\nAutostart: %s\nDziennik: %s\n", target, plistPath, logPath)
	return nil
}

func qsignUninstall() error {
	plistPath, err := qsignAgentPlist()
	if err != nil {
		return err
	}
	_ = exec.Command("launchctl", "bootout", "gui/"+fmt.Sprint(os.Getuid()), plistPath).Run()
	if err := os.Remove(plistPath); err != nil && !os.IsNotExist(err) {
		return err
	}
	fmt.Println("Usunięto autostart agenta podpisu.")
	return nil
}
