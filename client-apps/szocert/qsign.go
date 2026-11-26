// qsign.go — tryb „szocert podpis”: lokalny agent podpisu kwalifikowanego dla
// webmaila poczta.feer.org.pl (wtyczka Roundcube feer_qsign).
//
// Klucz kwalifikowany NIE jest dostępny dla tego programu. Użytkownik ma rSign
// (CenCert, klucz w zdalnym HSM) obsługiwany wyłącznie przez PEM-HEART
// Signature, który nie udostępnia PKCS#11 ani lokalnego API. Agent więc:
//
//  1. przyjmuje z przeglądarki część MIME do podpisu (POST /qsign/sign),
//  2. pokazuje okno potwierdzenia z podglądem odczytanym z SAMEGO MIME,
//  3. zapisuje plik lokalnie i otwiera go w PEM-HEART,
//  4. czeka, aż użytkownik zapisze obok podpis zewnętrzny CAdES (.sig/.p7s),
//  5. sprawdza, że to odłączony CMS dokładnie nad tymi bajtami, i oddaje go
//     przeglądarce (GET /qsign/job).
//
// PIN i potwierdzenie rSign obsługuje wyłącznie PEM-HEART.
package main

import (
	"bufio"
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/asn1"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net/http"
	"net/textproto"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/digitorus/pkcs7"
)

const (
	qsignListenAddr = "127.0.0.1:52118"
	qsignMaxBytes   = 60 << 20 // limit mimeshield/Roundcube to 50 MB + zapas na base64
	qsignJobTTL     = 15 * time.Minute
	qsignDataName   = "wiadomosc.eml"
)

// Domyślnie tylko webmail. SZOCERT_QSIGN_ORIGINS (lista po przecinku) służy do
// testów lokalnych — kto może ustawić zmienne środowiskowe agenta, i tak ma
// pełną kontrolę nad kontem.
func qsignAllowedOrigins() map[string]bool {
	m := map[string]bool{"https://poczta.feer.org.pl": true}
	for _, o := range strings.Split(os.Getenv("SZOCERT_QSIGN_ORIGINS"), ",") {
		if o = strings.TrimSpace(o); o != "" {
			m[o] = true
		}
	}
	return m
}

// Podmienialne w testach.
var (
	qsignConfirm = platformConfirm    // okno potwierdzenia (true = podpisz)
	qsignOpen    = platformOpenSigner // otwarcie pliku w PEM-HEART
	qsignBaseDir = defaultQsignDir
	qsignPoll    = time.Second
)

func defaultQsignDir() (string, error) {
	d, err := identityDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(d, "podpis"), nil
}

type qsignJob struct {
	ID      string
	Dir     string
	Data    []byte
	Created time.Time

	mu        sync.Mutex
	Status    string // pending | done | error | cancelled
	Err       string
	CMS       []byte
	Micalg    string
	Signer    string
	Qualified bool
}

func (j *qsignJob) snapshot() map[string]interface{} {
	j.mu.Lock()
	defer j.mu.Unlock()
	out := map[string]interface{}{"ok": j.Status != "error", "status": j.Status}
	switch j.Status {
	case "done":
		out["cms_b64"] = base64.StdEncoding.EncodeToString(j.CMS)
		out["micalg"] = j.Micalg
		out["signer"] = j.Signer
		out["qualified"] = j.Qualified
	case "error", "cancelled":
		out["error"] = j.Err
	}
	return out
}

func (j *qsignJob) finish(status, errMsg string) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.Status != "pending" {
		return
	}
	j.Status, j.Err = status, errMsg
}

type qsignServer struct {
	mu      sync.Mutex
	jobs    map[string]*qsignJob
	origins map[string]bool
}

func newQsignServer() *qsignServer {
	return &qsignServer{jobs: map[string]*qsignJob{}, origins: qsignAllowedOrigins()}
}

// guard: CORS tylko dla dozwolonego Origin i twarda odmowa wszystkim innym.
// Sam CORS nie blokuje wykonania żądania „prostego”, dlatego Origin jest
// sprawdzany też po stronie agenta. Brak Origin = nie przeglądarka → odmowa.
func (s *qsignServer) guard(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		if !s.origins[origin] {
			writeJSON(w, http.StatusForbidden, map[string]interface{}{"ok": false, "error": "niedozwolone źródło"})
			return
		}
		w.Header().Set("Access-Control-Allow-Origin", origin)
		w.Header().Set("Vary", "Origin")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
		// Chrome: dostęp strony publicznej do sieci lokalnej (Private Network Access).
		w.Header().Set("Access-Control-Allow-Private-Network", "true")
		w.Header().Set("Cache-Control", "no-store")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		h(w, r)
	}
}

func (s *qsignServer) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/qsign/ping", s.guard(s.handlePing))
	mux.HandleFunc("/qsign/sign", s.guard(s.handleSign))
	mux.HandleFunc("/qsign/job", s.guard(s.handleJob))
	mux.HandleFunc("/qsign/cancel", s.guard(s.handleCancel))
	return mux
}

func (s *qsignServer) handlePing(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok": true, "app": "szocert-podpis", "version": appVersion,
		"signer": "PEM-HEART Signature", "signer_installed": platformSignerInstalled(),
	})
}

type qsignRequest struct {
	DataB64 string `json:"data_b64"`
	From    string `json:"from"`
	To      string `json:"to"`
	Subject string `json:"subject"`
}

func (s *qsignServer) activeJob() *qsignJob {
	for _, j := range s.jobs {
		j.mu.Lock()
		st := j.Status
		j.mu.Unlock()
		if st == "pending" {
			return j
		}
	}
	return nil
}

func (s *qsignServer) handleSign(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]interface{}{"ok": false, "error": "POST wymagane"})
		return
	}
	var req qsignRequest
	if err := json.NewDecoder(io.LimitReader(r.Body, qsignMaxBytes)).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{"ok": false, "error": "błędny JSON albo za duża wiadomość"})
		return
	}
	data, err := base64.StdEncoding.DecodeString(req.DataB64)
	if err != nil || len(data) == 0 {
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{"ok": false, "error": "błędne data_b64"})
		return
	}

	s.mu.Lock()
	busy := s.activeJob() != nil
	s.mu.Unlock()
	if busy {
		writeJSON(w, http.StatusConflict, map[string]interface{}{"ok": false, "error": "Trwa już inne podpisywanie. Dokończ je w PEM-HEART albo anuluj."})
		return
	}

	if !qsignConfirm(confirmText(req, data)) {
		writeJSON(w, http.StatusOK, map[string]interface{}{"ok": false, "status": "cancelled", "error": "Podpis anulowany na komputerze."})
		return
	}

	job, err := s.startJob(data)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]interface{}{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{"ok": true, "status": "pending", "job_id": job.ID})
}

func (s *qsignServer) startJob(data []byte) (*qsignJob, error) {
	base, err := qsignBaseDir()
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(base, 0700); err != nil {
		return nil, err
	}
	idb := make([]byte, 12)
	if _, err := rand.Read(idb); err != nil {
		return nil, err
	}
	id := hex.EncodeToString(idb)
	dir := filepath.Join(base, time.Now().Format("20060102-150405")+"-"+id[:6])
	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, err
	}
	path := filepath.Join(dir, qsignDataName)
	if err := os.WriteFile(path, data, 0600); err != nil {
		return nil, err
	}
	job := &qsignJob{ID: id, Dir: dir, Data: data, Created: time.Now(), Status: "pending"}

	s.mu.Lock()
	s.jobs[id] = job
	s.mu.Unlock()

	if err := qsignOpen(path); err != nil {
		job.finish("error", "Nie udało się otworzyć PEM-HEART: "+err.Error())
		_ = os.RemoveAll(dir)
		return job, nil
	}
	go s.watch(job)
	return job, nil
}

// watch czeka na plik podpisu w katalogu zadania.
func (s *qsignServer) watch(job *qsignJob) {
	defer func() {
		_ = os.RemoveAll(job.Dir)
		// Zakończone zadanie trzymamy chwilę, żeby przeglądarka zdążyła odebrać wynik.
		time.AfterFunc(5*time.Minute, func() {
			s.mu.Lock()
			delete(s.jobs, job.ID)
			s.mu.Unlock()
		})
	}()
	deadline := job.Created.Add(qsignJobTTL)
	lastSize := map[string]int64{}
	fails := map[string]int{}
	for time.Now().Before(deadline) {
		job.mu.Lock()
		st := job.Status
		job.mu.Unlock()
		if st != "pending" {
			return
		}
		entries, _ := os.ReadDir(job.Dir)
		for _, e := range entries {
			name := e.Name()
			if e.IsDir() || name == qsignDataName || strings.HasPrefix(name, ".") {
				continue
			}
			info, err := e.Info()
			if err != nil || info.Size() == 0 {
				continue
			}
			// Plik jeszcze się zapisuje — poczekaj, aż rozmiar się ustabilizuje.
			if lastSize[name] != info.Size() {
				lastSize[name] = info.Size()
				continue
			}
			raw, err := os.ReadFile(filepath.Join(job.Dir, name))
			if err != nil {
				continue
			}
			res, err := verifyDetachedCMS(raw, job.Data)
			if err != nil {
				var fatal *qsignFatal
				if errors.As(err, &fatal) {
					job.finish("error", fatal.msg)
					return
				}
				if fails[name]++; fails[name] >= 3 {
					job.finish("error", "Plik "+name+" nie jest poprawnym podpisem CAdES tej wiadomości: "+err.Error())
					return
				}
				continue
			}
			job.mu.Lock()
			if job.Status == "pending" {
				job.Status, job.CMS, job.Micalg, job.Signer, job.Qualified = "done", res.DER, res.Micalg, res.Signer, res.Qualified
			}
			job.mu.Unlock()
			log.Printf("podpisano: %s (%s)", res.Signer, res.Micalg)
			return
		}
		time.Sleep(qsignPoll)
	}
	job.finish("error", "Minął czas na podpis w PEM-HEART (15 min).")
}

func (s *qsignServer) lookup(r *http.Request) *qsignJob {
	id := r.URL.Query().Get("id")
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.jobs[id]
}

func (s *qsignServer) handleJob(w http.ResponseWriter, r *http.Request) {
	job := s.lookup(r)
	if job == nil {
		writeJSON(w, http.StatusNotFound, map[string]interface{}{"ok": false, "status": "error", "error": "Nie ma takiego zadania podpisu (agent mógł zostać uruchomiony ponownie)."})
		return
	}
	writeJSON(w, http.StatusOK, job.snapshot())
}

func (s *qsignServer) handleCancel(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]interface{}{"ok": false, "error": "POST wymagane"})
		return
	}
	if job := s.lookup(r); job != nil {
		job.finish("cancelled", "Anulowano w webmailu.")
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{"ok": true})
}

// ── Weryfikacja wyniku z PEM-HEART ──────────────────────────────────────────

type qsignFatal struct{ msg string }

func (e *qsignFatal) Error() string { return e.msg }

type cmsResult struct {
	DER       []byte
	Micalg    string
	Signer    string
	Qualified bool
}

var oidQcStatements = asn1.ObjectIdentifier{1, 3, 6, 1, 5, 5, 7, 1, 3}

// decodeSignatureFile przyjmuje DER, PEM albo czysty Base64.
func decodeSignatureFile(raw []byte) ([]byte, error) {
	t := bytes.TrimSpace(raw)
	if bytes.HasPrefix(t, []byte("<")) {
		return nil, &qsignFatal{"PEM-HEART zapisał podpis XAdES (XML). Podpisz jeszcze raz i wybierz format CAdES, podpis zewnętrzny (.sig)."}
	}
	if len(t) > 0 && t[0] == 0x30 {
		return raw, nil
	}
	if bytes.HasPrefix(t, []byte("-----BEGIN")) {
		b, _ := pem.Decode(t)
		if b == nil {
			return nil, errors.New("błędny PEM")
		}
		return b.Bytes, nil
	}
	clean := regexp.MustCompile(`\s+`).ReplaceAll(t, nil)
	der, err := base64.StdEncoding.DecodeString(string(clean))
	if err != nil {
		return nil, errors.New("nieznany format pliku (ani DER, ani Base64)")
	}
	return der, nil
}

func verifyDetachedCMS(raw, data []byte) (*cmsResult, error) {
	der, err := decodeSignatureFile(raw)
	if err != nil {
		return nil, err
	}
	p7, err := pkcs7.Parse(der)
	if err != nil {
		return nil, fmt.Errorf("to nie jest CMS/CAdES: %w", err)
	}
	if len(p7.Content) > 0 {
		return nil, &qsignFatal{"PEM-HEART zapisał podpis wewnętrzny (z treścią w środku). Podpisz jeszcze raz jako podpis zewnętrzny CAdES."}
	}
	if len(p7.Signers) != 1 {
		return nil, &qsignFatal{fmt.Sprintf("podpis ma %d podpisujących, oczekiwano jednego", len(p7.Signers))}
	}
	p7.Content = data
	if err := p7.Verify(); err != nil {
		return nil, &qsignFatal{"Podpis nie zgadza się z treścią wiadomości (podpisano inny plik?): " + err.Error()}
	}
	cert := p7.GetOnlySigner()
	if cert == nil {
		return nil, &qsignFatal{"w podpisie brak certyfikatu podpisującego"}
	}
	micalg, err := micalgFor(p7.Signers[0].DigestAlgorithm.Algorithm)
	if err != nil {
		return nil, &qsignFatal{err.Error()}
	}
	return &cmsResult{DER: der, Micalg: micalg, Signer: signerName(cert), Qualified: hasExt(cert, oidQcStatements)}, nil
}

func micalgFor(oid asn1.ObjectIdentifier) (string, error) {
	switch {
	case oid.Equal(pkcs7.OIDDigestAlgorithmSHA256):
		return "sha-256", nil
	case oid.Equal(pkcs7.OIDDigestAlgorithmSHA384):
		return "sha-384", nil
	case oid.Equal(pkcs7.OIDDigestAlgorithmSHA512):
		return "sha-512", nil
	}
	return "", fmt.Errorf("nieobsługiwany skrót w podpisie: %s", oid)
}

func hasExt(c *x509.Certificate, oid asn1.ObjectIdentifier) bool {
	for _, e := range c.Extensions {
		if e.Id.Equal(oid) {
			return true
		}
	}
	return false
}

func signerName(c *x509.Certificate) string {
	n := c.Subject.CommonName
	if len(c.Issuer.Organization) > 0 {
		n += " (" + c.Issuer.Organization[0] + ")"
	} else if c.Issuer.CommonName != "" {
		n += " (" + c.Issuer.CommonName + ")"
	}
	return n
}

// ── Podgląd do okna potwierdzenia ───────────────────────────────────────────

type mimeSummary struct {
	Text        string
	Attachments []string
}

func confirmText(req qsignRequest, data []byte) string {
	sum := sha256.Sum256(data)
	ms := summarizeMIME(data)
	var b strings.Builder
	b.WriteString("Webmail poczta.feer.org.pl prosi o podpis kwalifikowany wiadomości.\n\n")
	if req.From != "" {
		b.WriteString("Od: " + clip(req.From, 120) + "\n")
	}
	if req.To != "" {
		b.WriteString("Do: " + clip(req.To, 200) + "\n")
	}
	if req.Subject != "" {
		b.WriteString("Temat: " + clip(req.Subject, 160) + "\n")
	}
	b.WriteString("\nPodpisywana treść (odczytana z samej wiadomości):\n")
	if ms.Text != "" {
		b.WriteString("„" + clip(ms.Text, 400) + "”\n")
	} else {
		b.WriteString("(brak tekstu)\n")
	}
	if len(ms.Attachments) > 0 {
		b.WriteString("Załączniki: " + clip(strings.Join(ms.Attachments, ", "), 300) + "\n")
	}
	b.WriteString(fmt.Sprintf("\nRozmiar: %d B, SHA-256: %s…\n", len(data), hex.EncodeToString(sum[:])[:16]))
	b.WriteString("\nPo kliknięciu „Podpisz w PEM-HEART” plik otworzy się w PEM-HEART. Wybierz podpis zewnętrzny w formacie CAdES i zapisz go w tym samym folderze.")
	return b.String()
}

func clip(s string, n int) string {
	s = strings.Join(strings.Fields(s), " ")
	r := []rune(s)
	if len(r) > n {
		return string(r[:n]) + "…"
	}
	return s
}

func summarizeMIME(data []byte) mimeSummary {
	var ms mimeSummary
	walkMIME(data, &ms, 0)
	return ms
}

var tagRe = regexp.MustCompile(`(?s)<style.*?</style>|<script.*?</script>|<[^>]+>`)

func walkMIME(entity []byte, ms *mimeSummary, depth int) {
	if depth > 8 {
		return
	}
	tp := textproto.NewReader(bufio.NewReader(bytes.NewReader(entity)))
	hdr, err := tp.ReadMIMEHeader()
	if err != nil && len(hdr) == 0 {
		return
	}
	body, _ := io.ReadAll(tp.R)
	ct := hdr.Get("Content-Type")
	if ct == "" {
		ct = "text/plain"
	}
	mt, params, _ := mime.ParseMediaType(ct)
	if strings.HasPrefix(mt, "multipart/") && params["boundary"] != "" {
		mr := multipart.NewReader(bytes.NewReader(body), params["boundary"])
		for {
			p, err := mr.NextRawPart()
			if err != nil {
				break
			}
			var sub bytes.Buffer
			for k, vs := range p.Header {
				for _, v := range vs {
					sub.WriteString(k + ": " + v + "\r\n")
				}
			}
			sub.WriteString("\r\n")
			_, _ = io.Copy(&sub, p)
			walkMIME(sub.Bytes(), ms, depth+1)
		}
		return
	}
	disp, dparams, _ := mime.ParseMediaType(hdr.Get("Content-Disposition"))
	name := dparams["filename"]
	if name == "" {
		name = params["name"]
	}
	if dec, err := new(mime.WordDecoder).DecodeHeader(name); err == nil {
		name = dec
	}
	decoded := decodeCTE(hdr.Get("Content-Transfer-Encoding"), body)
	if disp == "attachment" || (name != "" && !strings.HasPrefix(mt, "text/")) {
		ms.Attachments = append(ms.Attachments, fmt.Sprintf("%s (%d kB)", name, (len(decoded)+1023)/1024))
		return
	}
	if ms.Text == "" && mt == "text/plain" {
		ms.Text = string(decoded)
	} else if ms.Text == "" && mt == "text/html" {
		ms.Text = tagRe.ReplaceAllString(string(decoded), " ")
	}
}

func decodeCTE(cte string, body []byte) []byte {
	switch strings.ToLower(strings.TrimSpace(cte)) {
	case "base64":
		clean := regexp.MustCompile(`\s+`).ReplaceAll(body, nil)
		out, err := base64.StdEncoding.DecodeString(string(clean))
		if err == nil {
			return out
		}
	case "quoted-printable":
		out, err := io.ReadAll(quotedprintable.NewReader(bytes.NewReader(body)))
		if err == nil {
			return out
		}
	}
	return body
}

// ── Uruchomienie ────────────────────────────────────────────────────────────

func serveQsign() error {
	s := newQsignServer()
	log.SetFlags(log.LstdFlags)
	log.Printf("SzoCert %s — agent podpisu kwalifikowanego na http://%s", appVersion, qsignListenAddr)
	srv := &http.Server{Addr: qsignListenAddr, Handler: s.handler(), ReadHeaderTimeout: 10 * time.Second}
	return srv.ListenAndServe()
}
