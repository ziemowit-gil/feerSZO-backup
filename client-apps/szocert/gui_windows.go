//go:build gui && windows

package main

import (
	"os/exec"
	"strings"
	"syscall"
	"unsafe"
)

func guiChooseFile() (string, bool) {
	ps := `Add-Type -AssemblyName System.Windows.Forms; ` +
		`$f = New-Object System.Windows.Forms.OpenFileDialog; ` +
		`$f.Filter = 'Certyfikat (*.p12;*.pfx)|*.p12;*.pfx'; ` +
		`if ($f.ShowDialog() -eq 'OK') { Write-Output $f.FileName }`
	out, err := exec.Command("powershell", "-NoProfile", "-Command", ps).Output()
	path := strings.TrimSpace(string(out))
	if err != nil || path == "" {
		return "", false
	}
	return path, true
}

// guiAskPassword — okno wpisywania hasła. UWAGA: VisualBasic InputBox nie
// maskuje wpisywanych znaków (ograniczenie MVP — patrz README.md).
func guiAskPassword() (string, bool) {
	ps := `Add-Type -AssemblyName Microsoft.VisualBasic; ` +
		`[Microsoft.VisualBasic.Interaction]::InputBox('Haslo do pliku certyfikatu (pole nie maskuje znakow)', 'SzoCert', '')`
	out, err := exec.Command("powershell", "-NoProfile", "-Command", ps).Output()
	pass := strings.TrimRight(string(out), "\r\n")
	if err != nil || pass == "" {
		return "", false
	}
	return pass, true
}

func guiShowDialog(title, text string) {
	user32 := syscall.NewLazyDLL("user32.dll")
	proc := user32.NewProc("MessageBoxW")
	titlePtr, _ := syscall.UTF16PtrFromString(title)
	textPtr, _ := syscall.UTF16PtrFromString(text)
	proc.Call(0, uintptr(unsafe.Pointer(textPtr)), uintptr(unsafe.Pointer(titlePtr)), 0)
}
