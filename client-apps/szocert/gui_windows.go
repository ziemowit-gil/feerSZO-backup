//go:build gui && windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"unsafe"
)

const autostartRunName = "SzoCert"

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

// guiAskPassword — własne okno WinForms z zamaskowanym polem hasła
// (UseSystemPasswordChar), bo VisualBasic InputBox tego nie potrafi.
func guiAskPassword(prompt string) (string, bool) {
	escPrompt := strings.ReplaceAll(prompt, `'`, `''`)
	ps := fmt.Sprintf(`
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form = New-Object System.Windows.Forms.Form
$form.Text = 'SzoCert'
$form.Size = New-Object System.Drawing.Size(420,160)
$form.StartPosition = 'CenterScreen'
$form.TopMost = $true
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$form.MinimizeBox = $false

$label = New-Object System.Windows.Forms.Label
$label.Text = '%s'
$label.AutoSize = $false
$label.Size = New-Object System.Drawing.Size(380,40)
$label.Location = New-Object System.Drawing.Point(10,10)
$form.Controls.Add($label)

$textbox = New-Object System.Windows.Forms.TextBox
$textbox.UseSystemPasswordChar = $true
$textbox.Location = New-Object System.Drawing.Point(10,55)
$textbox.Size = New-Object System.Drawing.Size(380,20)
$form.Controls.Add($textbox)
$form.Add_Shown({$textbox.Focus()})

$okButton = New-Object System.Windows.Forms.Button
$okButton.Text = 'OK'
$okButton.Location = New-Object System.Drawing.Point(225,90)
$okButton.DialogResult = [System.Windows.Forms.DialogResult]::OK
$form.Controls.Add($okButton)
$form.AcceptButton = $okButton

$cancelButton = New-Object System.Windows.Forms.Button
$cancelButton.Text = 'Anuluj'
$cancelButton.Location = New-Object System.Drawing.Point(310,90)
$cancelButton.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
$form.Controls.Add($cancelButton)
$form.CancelButton = $cancelButton

$result = $form.ShowDialog()
if ($result -eq [System.Windows.Forms.DialogResult]::OK) {
    Write-Output $textbox.Text
}
`, escPrompt)
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

func guiAskYesNo(title, text string) bool {
	const mbYesNo = 0x00000004
	const idYes = 6
	user32 := syscall.NewLazyDLL("user32.dll")
	proc := user32.NewProc("MessageBoxW")
	titlePtr, _ := syscall.UTF16PtrFromString(title)
	textPtr, _ := syscall.UTF16PtrFromString(text)
	ret, _, _ := proc.Call(0, uintptr(unsafe.Pointer(textPtr)), uintptr(unsafe.Pointer(titlePtr)), mbYesNo)
	return ret == idYes
}

func guiAutostartInstalled() bool {
	ps := fmt.Sprintf(`(Get-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name '%s' -ErrorAction SilentlyContinue).'%s'`, autostartRunName, autostartRunName)
	out, err := exec.Command("powershell", "-NoProfile", "-Command", ps).Output()
	return err == nil && strings.TrimSpace(string(out)) != ""
}

// guiInstallAutostart dodaje wpis w rejestrze (HKCU Run), żeby SzoCert
// startował przy logowaniu — nie omija podania hasła zabezpieczającego.
func guiInstallAutostart() error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	ps := fmt.Sprintf(`New-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name '%s' -Value '"%s"' -PropertyType String -Force | Out-Null`, autostartRunName, exe)
	return exec.Command("powershell", "-NoProfile", "-Command", ps).Run()
}
