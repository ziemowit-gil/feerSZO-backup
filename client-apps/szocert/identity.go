package main

import (
	"crypto/rsa"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"os"
	"path/filepath"
	"runtime"

	pkcs12 "software.sslmate.com/src/go-pkcs12"
)

type identity struct {
	Key  *rsa.PrivateKey
	Cert *x509.Certificate
}

func identityDir() (string, error) {
	if runtime.GOOS == "windows" {
		base := os.Getenv("APPDATA")
		if base == "" {
			return "", fmt.Errorf("brak zmiennej środowiskowej APPDATA")
		}
		return filepath.Join(base, "SzoCert"), nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	if runtime.GOOS == "darwin" {
		return filepath.Join(home, "Library", "Application Support", "SzoCert"), nil
	}
	return filepath.Join(home, ".szocert"), nil
}

func identityPath() (string, error) {
	dir, err := identityDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "identity.pem"), nil
}

// importP12 wczytuje plik .p12 wystawiony przez admin/x509_login.php (patrz
// includes/x509_login.php x509_generate_for_user) i zapisuje klucz prywatny +
// certyfikat LOKALNIE. Od tej chwili prywatny klucz nigdy nie opuszcza tego
// komputera — logowanie już tylko podpisuje wyzwania (patrz server.go).
func importP12(p12Path, password string) error {
	data, err := os.ReadFile(p12Path)
	if err != nil {
		return fmt.Errorf("nie mogę odczytać %s: %w", p12Path, err)
	}

	key, cert, err := pkcs12.Decode(data, password)
	if err != nil {
		return fmt.Errorf("błędne hasło albo uszkodzony plik .p12: %w", err)
	}
	rsaKey, ok := key.(*rsa.PrivateKey)
	if !ok {
		return fmt.Errorf("oczekiwano klucza RSA (tak generuje go SZO) — dostano inny typ")
	}

	keyDer := x509.MarshalPKCS1PrivateKey(rsaKey)
	keyBlock := &pem.Block{Type: "RSA PRIVATE KEY", Bytes: keyDer}
	certBlock := &pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw}

	dir, err := identityDir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("nie mogę utworzyć %s: %w", dir, err)
	}

	path, err := identityPath()
	if err != nil {
		return err
	}
	out := append(pem.EncodeToMemory(keyBlock), pem.EncodeToMemory(certBlock)...)
	if err := os.WriteFile(path, out, 0600); err != nil {
		return fmt.Errorf("nie mogę zapisać %s: %w", path, err)
	}
	return nil
}

func loadIdentity() (*identity, error) {
	path, err := identityPath()
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("brak zaimportowanego certyfikatu (%s) — uruchom najpierw: szocert import <plik.p12>", path)
	}

	var id identity
	rest := data
	for {
		var block *pem.Block
		block, rest = pem.Decode(rest)
		if block == nil {
			break
		}
		switch block.Type {
		case "RSA PRIVATE KEY":
			key, err := x509.ParsePKCS1PrivateKey(block.Bytes)
			if err != nil {
				return nil, fmt.Errorf("uszkodzony klucz w %s: %w", path, err)
			}
			id.Key = key
		case "CERTIFICATE":
			cert, err := x509.ParseCertificate(block.Bytes)
			if err != nil {
				return nil, fmt.Errorf("uszkodzony certyfikat w %s: %w", path, err)
			}
			id.Cert = cert
		}
	}
	if id.Key == nil || id.Cert == nil {
		return nil, fmt.Errorf("%s nie zawiera kompletu klucz+certyfikat — zaimportuj ponownie", path)
	}
	return &id, nil
}

func certPEM(cert *x509.Certificate) string {
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw}))
}
