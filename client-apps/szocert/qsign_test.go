package main

import (
	"bytes"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/digitorus/pkcs7"
)

const testOrigin = "https://poczta.feer.org.pl"

var testEntity = []byte("Content-Type: multipart/mixed; boundary=\"=_b\"\r\n\r\n--=_b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nZa=C5=BC=C3=B3=C5=82=C4=87 g=C4=99=C5=9Bl=C4=85\r\n--=_b\r\nContent-Type: application/pdf; name=\"umowa.pdf\"\r\nContent-Disposition: attachment; filename=\"umowa.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--=_b--\r\n")

func testSigner(t *testing.T) (*rsa.PrivateKey, *x509.Certificate) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "TEST KWALIFIKOWANY"},
		Issuer: pkix.Name{Organization: []string{"Test QTSP"}}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour),
		KeyUsage: x509.KeyUsageContentCommitment}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	cert, _ := x509.ParseCertificate(der)
	return key, cert
}

func signCMS(t *testing.T, data []byte, detach bool) []byte {
	key, cert := testSigner(t)
	sd, err := pkcs7.NewSignedData(data)
	if err != nil {
		t.Fatal(err)
	}
	sd.SetDigestAlgorithm(pkcs7.OIDDigestAlgorithmSHA512)
	if err := sd.AddSigner(cert, key, pkcs7.SignerInfoConfig{}); err != nil {
		t.Fatal(err)
	}
	if detach {
		sd.Detach()
	}
	der, err := sd.Finish()
	if err != nil {
		t.Fatal(err)
	}
	return der
}

func TestVerifyDetachedCMS(t *testing.T) {
	der := signCMS(t, testEntity, true)
	for name, raw := range map[string][]byte{
		"der": der, "base64": []byte(base64.StdEncoding.EncodeToString(der)),
		"pem": []byte("-----BEGIN PKCS7-----\n" + base64.StdEncoding.EncodeToString(der) + "\n-----END PKCS7-----\n"),
	} {
		res, err := verifyDetachedCMS(raw, testEntity)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if res.Micalg != "sha-512" || !bytes.Equal(res.DER, der) {
			t.Fatalf("%s: micalg %s", name, res.Micalg)
		}
	}
	if _, err := verifyDetachedCMS(der, append([]byte("X"), testEntity...)); err == nil {
		t.Fatal("podpis innej treści przeszedł")
	}
	if _, err := verifyDetachedCMS(signCMS(t, testEntity, false), testEntity); err == nil || !strings.Contains(err.Error(), "wewnętrzny") {
		t.Fatalf("podpis dołączony: %v", err)
	}
	if _, err := verifyDetachedCMS([]byte("<?xml version=\"1.0\"?><ds:Signature/>"), testEntity); err == nil || !strings.Contains(err.Error(), "XAdES") {
		t.Fatalf("xades: %v", err)
	}
}

func TestSummarizeMIME(t *testing.T) {
	ms := summarizeMIME(testEntity)
	if !strings.Contains(ms.Text, "Zażółć gęślą") || len(ms.Attachments) != 1 || !strings.HasPrefix(ms.Attachments[0], "umowa.pdf") {
		t.Fatalf("%+v", ms)
	}
}

func do(t *testing.T, h http.Handler, method, url, origin string, body interface{}) map[string]interface{} {
	var rd *bytes.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	} else {
		rd = bytes.NewReader(nil)
	}
	req := httptest.NewRequest(method, url, rd)
	if origin != "" {
		req.Header.Set("Origin", origin)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	out := map[string]interface{}{"_code": float64(rec.Code)}
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	return out
}

func TestAgentFlow(t *testing.T) {
	tmp := t.TempDir()
	qsignBaseDir = func() (string, error) { return tmp, nil }
	qsignPoll = 20 * time.Millisecond
	var confirmed string
	qsignConfirm = func(text string) bool { confirmed = text; return true }
	sig := signCMS(t, testEntity, true)
	qsignOpen = func(path string) error {
		// „PEM-HEART” zapisuje podpis zewnętrzny obok pliku.
		go func() {
			time.Sleep(50 * time.Millisecond)
			_ = os.WriteFile(path+".sig", []byte(base64.StdEncoding.EncodeToString(sig)), 0600)
		}()
		return nil
	}
	h := newQsignServer().handler()

	if r := do(t, h, "GET", "/qsign/ping", "https://evil.example", nil); r["_code"] != float64(403) {
		t.Fatalf("obce źródło: %v", r)
	}
	if r := do(t, h, "GET", "/qsign/ping", "", nil); r["_code"] != float64(403) {
		t.Fatalf("brak Origin: %v", r)
	}
	r := do(t, h, "POST", "/qsign/sign", testOrigin, qsignRequest{DataB64: base64.StdEncoding.EncodeToString(testEntity), Subject: "Umowa", To: "a@b.pl"})
	id, _ := r["job_id"].(string)
	if id == "" {
		t.Fatalf("sign: %v", r)
	}
	if !strings.Contains(confirmed, "Temat: Umowa") || !strings.Contains(confirmed, "umowa.pdf") {
		t.Fatalf("okno: %s", confirmed)
	}
	if r := do(t, h, "POST", "/qsign/sign", testOrigin, qsignRequest{DataB64: "QQ=="}); r["_code"] != float64(409) {
		t.Fatalf("drugie zadanie naraz: %v", r)
	}
	var res map[string]interface{}
	for i := 0; i < 200; i++ {
		res = do(t, h, "GET", "/qsign/job?id="+id, testOrigin, nil)
		if res["status"] != "pending" {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if res["status"] != "done" || res["micalg"] != "sha-512" {
		t.Fatalf("job: %v", res)
	}
	got, _ := base64.StdEncoding.DecodeString(res["cms_b64"].(string))
	if !bytes.Equal(got, sig) {
		t.Fatal("inny CMS")
	}
	time.Sleep(100 * time.Millisecond)
	if entries, _ := os.ReadDir(tmp); len(entries) != 0 {
		t.Fatalf("katalog zadania nie został sprzątnięty: %v", filepath.Join(tmp, entries[0].Name()))
	}

	qsignConfirm = func(string) bool { return false }
	if r := do(t, h, "POST", "/qsign/sign", testOrigin, qsignRequest{DataB64: "QQ=="}); r["status"] != "cancelled" {
		t.Fatalf("odmowa: %v", r)
	}
}
