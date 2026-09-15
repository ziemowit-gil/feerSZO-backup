// szocert — aplikacja kliencka do logowania certyfikatem X.509 w feerSZO.
//
// Klucz prywatny importowanego certyfikatu (.p12 wystawionego przez
// administratora w admin/x509_login.php) zostaje WYŁĄCZNIE na tym
// komputerze. Logowanie na stronie feerSZO polega na podpisaniu
// jednorazowego wyzwania (challenge-response) — plik .p12/hasło nigdy
// więcej nie są przesyłane po imporcie.
//
// Użycie:
//   szocert import ścieżka/do/certyfikatu.p12
//   szocert serve
//
// To wariant CLI (terminal). Wariant GUI (dwuklik, natywne okna) jest w
// gui.go / gui_darwin.go / gui_windows.go — budowany z -tags gui.
//go:build !gui

package main

import (
	"fmt"
	"os"

	"golang.org/x/term"
)

func main() {
	if len(os.Args) < 2 {
		printUsage()
		os.Exit(1)
	}

	switch os.Args[1] {
	case "import":
		if len(os.Args) < 3 {
			fmt.Fprintln(os.Stderr, "Użycie: szocert import ścieżka/do/certyfikatu.p12")
			os.Exit(1)
		}
		if err := cmdImport(os.Args[2]); err != nil {
			fmt.Fprintln(os.Stderr, "Błąd:", err)
			os.Exit(1)
		}
	case "serve":
		if err := cmdServe(); err != nil {
			fmt.Fprintln(os.Stderr, "Błąd:", err)
			os.Exit(1)
		}
	case "-h", "--help", "help":
		printUsage()
	default:
		fmt.Fprintf(os.Stderr, "Nieznane polecenie: %s\n\n", os.Args[1])
		printUsage()
		os.Exit(1)
	}
}

func printUsage() {
	fmt.Println(`SzoCert — logowanie certyfikatem X.509 dla feerSZO.

Polecenia:
  szocert import ścieżka/do/certyfikatu.p12   Jednorazowy import certyfikatu
                                                (od admina, plik .p12/.pfx)
  szocert serve                                Uruchom nasłuch do logowania
                                                (zostaw okno otwarte)`)
}

func cmdImport(p12Path string) error {
	if _, err := os.Stat(p12Path); err != nil {
		return fmt.Errorf("nie znaleziono pliku: %s", p12Path)
	}

	path, _ := identityPath()
	if _, err := os.Stat(path); err == nil {
		fmt.Printf("Na tym komputerze jest już zaimportowany certyfikat (%s).\n", path)
		fmt.Print("Nadpisać nowym? [t/N]: ")
		var answer string
		fmt.Scanln(&answer)
		if answer != "t" && answer != "T" {
			fmt.Println("Przerwano.")
			return nil
		}
	}

	fmt.Print("Hasło do pliku certyfikatu (od administratora): ")
	p12PassBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
	fmt.Println()
	if err != nil {
		return fmt.Errorf("nie mogę odczytać hasła: %w", err)
	}

	fmt.Print("Ustaw hasło zabezpieczające SzoCert (będzie proszone przy KAŻDYM uruchomieniu): ")
	protectBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
	fmt.Println()
	if err != nil {
		return fmt.Errorf("nie mogę odczytać hasła: %w", err)
	}
	if len(protectBytes) < 4 {
		return fmt.Errorf("hasło zabezpieczające musi mieć co najmniej 4 znaki")
	}

	if err := importP12(p12Path, string(p12PassBytes), string(protectBytes)); err != nil {
		return err
	}

	id, err := loadIdentity(string(protectBytes))
	if err != nil {
		return fmt.Errorf("import się powiódł, ale odczyt się nie udał: %w", err)
	}
	fmt.Printf("Zaimportowano certyfikat: %s (ważny do %s)\n", id.Cert.Subject.CommonName, id.Cert.NotAfter.Format("2006-01-02"))
	fmt.Println("Gotowe — teraz uruchom: szocert serve (poprosi o hasło zabezpieczające)")
	return nil
}

func cmdServe() error {
	if !identityExists() {
		path, _ := identityPath()
		return fmt.Errorf("brak zaimportowanego certyfikatu (%s) — uruchom najpierw: szocert import <plik.p12>", path)
	}
	fmt.Print("Hasło zabezpieczające SzoCert: ")
	passBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
	fmt.Println()
	if err != nil {
		return fmt.Errorf("nie mogę odczytać hasła: %w", err)
	}

	id, err := loadIdentity(string(passBytes))
	if err != nil {
		return err
	}
	fmt.Printf("SzoCert %s — zalogowany jako: %s\n", appVersion, id.Cert.Subject.CommonName)
	fmt.Printf("Nasłuchuję na http://%s (zostaw to okno otwarte podczas logowania)\n", listenAddr)
	return serveIdentity(id)
}
