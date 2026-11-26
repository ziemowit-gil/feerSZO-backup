//go:build !darwin

package main

import (
	"errors"
	"os/exec"
)

// Poza macOS agent na razie nie ma okna potwierdzenia — odmawia każdego podpisu.
func platformConfirm(text string) bool { return false }

func platformSignerInstalled() bool { return false }

func platformOpenSigner(path string) error {
	return exec.Command("cmd", "/c", "start", "", path).Run()
}

func qsignInstall() error {
	return errors.New("autostart agenta podpisu jest na razie tylko dla macOS")
}
func qsignUninstall() error { return qsignInstall() }
