package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"

	"golang.org/x/crypto/scrypt"
	pkcs12 "software.sslmate.com/src/go-pkcs12"
)

type identity struct {
	Key  *rsa.PrivateKey
	Cert *x509.Certificate
}

// Parametry scrypt dobrane pod jednorazowe odblokowanie przy starcie (nie
// przy każdym podpisie) — ~kilkaset ms na zwykłym laptopie, wystarczająco
// kosztowne dla ataku brute-force offline na skradziony plik tożsamości.
const (
	scryptN = 1 << 15
	scryptR = 8
	scryptP = 1
	keyLen  = 32
	saltLen = 16
)

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

func deriveKey(password string, salt []byte) ([]byte, error) {
	return scrypt.Key([]byte(password), salt, scryptN, scryptR, scryptP, keyLen)
}

// encryptKeyDER szyfruje DER klucza prywatnego hasłem (scrypt → AES-256-GCM).
// Układ wyjścia: salt(16) | nonce(12) | ciphertext+tag.
func encryptKeyDER(keyDer []byte, password string) ([]byte, error) {
	salt := make([]byte, saltLen)
	if _, err := io.ReadFull(rand.Reader, salt); err != nil {
		return nil, err
	}
	derived, err := deriveKey(password, salt)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(derived)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return nil, err
	}
	ciphertext := gcm.Seal(nil, nonce, keyDer, nil)
	out := make([]byte, 0, len(salt)+len(nonce)+len(ciphertext))
	out = append(out, salt...)
	out = append(out, nonce...)
	out = append(out, ciphertext...)
	return out, nil
}

func decryptKeyDER(blob []byte, password string) ([]byte, error) {
	if len(blob) < saltLen+12+16 {
		return nil, fmt.Errorf("uszkodzony plik tożsamości")
	}
	salt := blob[:saltLen]
	derived, err := deriveKey(password, salt)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(derived)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	nonceSize := gcm.NonceSize()
	rest := blob[saltLen:]
	if len(rest) < nonceSize {
		return nil, fmt.Errorf("uszkodzony plik tożsamości")
	}
	nonce, ciphertext := rest[:nonceSize], rest[nonceSize:]
	plain, err := gcm.Open(nil, nonce, ciphertext, nil)
	if err != nil {
		return nil, fmt.Errorf("błędne hasło")
	}
	return plain, nil
}

// importP12 wczytuje plik .p12 wystawiony przez admin/x509_login.php (patrz
// includes/x509_login.php x509_generate_for_user), rozszyfrowuje go hasłem
// p12Password (to, które dostał od admina), i zapisuje klucz prywatny LOKALNIE
// zaszyfrowany hasłem protectPassword (to, które użytkownik będzie podawał
// przy każdym uruchomieniu SzoCert — patrz loadIdentity). Certyfikat (dane
// publiczne) zapisywany jest jawnie.
func importP12(p12Path, p12Password, protectPassword string) error {
	data, err := os.ReadFile(p12Path)
	if err != nil {
		return fmt.Errorf("nie mogę odczytać %s: %w", p12Path, err)
	}

	key, cert, err := pkcs12.Decode(data, p12Password)
	if err != nil {
		return fmt.Errorf("błędne hasło pliku .p12 albo uszkodzony plik: %w", err)
	}
	rsaKey, ok := key.(*rsa.PrivateKey)
	if !ok {
		return fmt.Errorf("oczekiwano klucza RSA (tak generuje go SZO) — dostano inny typ")
	}
	if protectPassword == "" {
		return fmt.Errorf("hasło zabezpieczające nie może być puste")
	}

	keyDer := x509.MarshalPKCS1PrivateKey(rsaKey)
	encKey, err := encryptKeyDER(keyDer, protectPassword)
	if err != nil {
		return fmt.Errorf("nie mogę zaszyfrować klucza: %w", err)
	}

	keyBlock := &pem.Block{Type: "SZOCERT ENCRYPTED KEY", Bytes: encKey}
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

// loadIdentity odczytuje i odszyfrowuje lokalną tożsamość. Wymaga hasła
// zabezpieczającego ustawionego przy imporcie (importP12/protectPassword) —
// bez niego plik na dysku jest bezużyteczny (klucz jest zaszyfrowany).
func loadIdentity(protectPassword string) (*identity, error) {
	path, err := identityPath()
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("brak zaimportowanego certyfikatu (%s) — uruchom najpierw: szocert import <plik.p12>", path)
	}

	var id identity
	var encKeyBlob []byte
	rest := data
	for {
		var block *pem.Block
		block, rest = pem.Decode(rest)
		if block == nil {
			break
		}
		switch block.Type {
		case "SZOCERT ENCRYPTED KEY":
			encKeyBlob = block.Bytes
		case "CERTIFICATE":
			cert, err := x509.ParseCertificate(block.Bytes)
			if err != nil {
				return nil, fmt.Errorf("uszkodzony certyfikat w %s: %w", path, err)
			}
			id.Cert = cert
		}
	}
	if encKeyBlob == nil || id.Cert == nil {
		return nil, fmt.Errorf("%s nie zawiera kompletu klucz+certyfikat — zaimportuj ponownie", path)
	}

	keyDer, err := decryptKeyDER(encKeyBlob, protectPassword)
	if err != nil {
		return nil, err
	}
	key, err := x509.ParsePKCS1PrivateKey(keyDer)
	if err != nil {
		return nil, fmt.Errorf("uszkodzony klucz w %s: %w", path, err)
	}
	id.Key = key
	return &id, nil
}

// identityExists — czy jest już co odblokowywać (bez podawania hasła).
func identityExists() bool {
	path, err := identityPath()
	if err != nil {
		return false
	}
	_, err = os.Stat(path)
	return err == nil
}

func certPEM(cert *x509.Certificate) string {
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw}))
}
