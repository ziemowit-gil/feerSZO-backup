package main

import (
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"
)

const appVersion = "1.0"
const listenAddr = "127.0.0.1:52117"

// isAllowedOrigin ogranicza CORS do domen tego wdrożenia (feerSZO/ngosystem) —
// serwer lokalny nie powinien odpowiadać dowolnej stronie w przeglądarce.
func isAllowedOrigin(origin string) bool {
	if origin == "" {
		return false
	}
	o := strings.TrimPrefix(strings.TrimPrefix(origin, "https://"), "http://")
	o = strings.SplitN(o, ":", 2)[0]
	return strings.HasSuffix(o, ".feer.org.pl") || o == "feer.org.pl" ||
		strings.HasSuffix(o, ".ngosystem.pl") || o == "ngosystem.pl" ||
		o == "localhost" || o == "127.0.0.1"
}

func withCORS(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		if isAllowedOrigin(origin) {
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
		}
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		h(w, r)
	}
}

func writeJSON(w http.ResponseWriter, code int, v interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func handlePing(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok": true, "app": "szocert", "version": appVersion,
	})
}

type signRequest struct {
	NonceB64 string `json:"nonce_b64"`
}

func handleSign(id *identity) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, http.StatusMethodNotAllowed, map[string]interface{}{"ok": false, "error": "POST wymagane"})
			return
		}
		var req signRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]interface{}{"ok": false, "error": "błędny JSON"})
			return
		}
		nonce, err := base64.StdEncoding.DecodeString(req.NonceB64)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]interface{}{"ok": false, "error": "błędne nonce_b64"})
			return
		}

		digest := sha256.Sum256(nonce)
		sig, err := rsa.SignPKCS1v15(rand.Reader, id.Key, crypto.SHA256, digest[:])
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]interface{}{"ok": false, "error": "błąd podpisu: " + err.Error()})
			return
		}

		writeJSON(w, http.StatusOK, map[string]interface{}{
			"ok":            true,
			"signature_b64": base64.StdEncoding.EncodeToString(sig),
			"cert_pem":      certPEM(id.Cert),
		})
	}
}

func runServe() error {
	id, err := loadIdentity()
	if err != nil {
		return err
	}
	fmt.Printf("SzoCert %s — zalogowany jako: %s\n", appVersion, id.Cert.Subject.CommonName)
	fmt.Printf("Nasłuchuję na http://%s (zostaw to okno otwarte podczas logowania)\n", listenAddr)
	return serveIdentity(id)
}

// serveIdentity uruchamia nasłuch dla już wczytanej tożsamości — używane też
// przez wariant GUI (gui.go), gdzie identity jest ładowane/importowane wcześniej.
func serveIdentity(id *identity) error {
	mux := http.NewServeMux()
	mux.HandleFunc("/ping", withCORS(handlePing))
	mux.HandleFunc("/sign", withCORS(handleSign(id)))

	log.SetFlags(0)
	return http.ListenAndServe(listenAddr, mux)
}
