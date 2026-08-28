package httpapi

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/marwanshokry/palm-task/proxy-manager/internal/pool"
)

// quietLogger keeps test output readable by discarding log lines.
func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

// newTestServer builds a router over a pool containing the given proxies.
func newTestServer(t *testing.T, proxies []pool.Proxy, apiKey string) http.Handler {
	t.Helper()

	p := pool.New(proxies, pool.Options{FailureThreshold: 3, Cooldown: time.Minute})
	return NewRouter(NewServer(p, quietLogger()), apiKey, quietLogger())
}

// do issues a request against the handler and returns the recorded response.
func do(t *testing.T, h http.Handler, method, path, body string, headers map[string]string) *httptest.ResponseRecorder {
	t.Helper()

	var reader io.Reader
	if body != "" {
		reader = strings.NewReader(body)
	}

	req := httptest.NewRequest(method, path, reader)
	for k, v := range headers {
		req.Header.Set(k, v)
	}

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	return rec
}

// decode unmarshals a JSON response body into a map.
func decode(t *testing.T, rec *httptest.ResponseRecorder) map[string]any {
	t.Helper()

	var out map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("response was not valid json: %v (body: %s)", err, rec.Body.String())
	}
	return out
}

func TestHealthzAlwaysReportsOK(t *testing.T) {
	// Even with no proxies at all, the process itself is alive.
	h := newTestServer(t, nil, "")

	rec := do(t, h, http.MethodGet, "/healthz", "", nil)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}
	if decode(t, rec)["status"] != "ok" {
		t.Errorf("unexpected body: %s", rec.Body.String())
	}
}

func TestReadyzReportsUnavailableWhenNoProxiesAreHealthy(t *testing.T) {
	h := newTestServer(t, nil, "")

	rec := do(t, h, http.MethodGet, "/readyz", "", nil)

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503 with an empty pool, got %d", rec.Code)
	}
	if decode(t, rec)["ready"] != false {
		t.Error("expected ready:false")
	}
}

func TestReadyzReportsOKWhenAProxyIsAvailable(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "a", URL: "http://a", Weight: 1}}, "")

	rec := do(t, h, http.MethodGet, "/readyz", "", nil)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}
}

func TestHealthEndpointsDoNotRequireTheAPIKey(t *testing.T) {
	// An orchestrator must be able to probe without holding the secret.
	h := newTestServer(t, []pool.Proxy{{ID: "a", URL: "http://a", Weight: 1}}, "secret")

	for _, path := range []string{"/healthz", "/readyz"} {
		rec := do(t, h, http.MethodGet, path, "", nil)

		if rec.Code == http.StatusUnauthorized {
			t.Errorf("%s must not require authentication", path)
		}
	}
}

func TestNextProxyReturnsAProxy(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "alpha", URL: "http://alpha:8080", Weight: 2}}, "")

	rec := do(t, h, http.MethodGet, "/v1/proxy/next", "", nil)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d (body: %s)", rec.Code, rec.Body.String())
	}

	body := decode(t, rec)
	if body["id"] != "alpha" {
		t.Errorf("expected proxy id alpha, got %v", body["id"])
	}
	if body["url"] != "http://alpha:8080" {
		t.Errorf("expected the proxy url, got %v", body["url"])
	}
}

func TestNextProxyReturns503WhenPoolIsEmpty(t *testing.T) {
	// 503 is the contract that tells Laravel to scrape directly instead.
	h := newTestServer(t, nil, "")

	rec := do(t, h, http.MethodGet, "/v1/proxy/next", "", nil)

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503, got %d", rec.Code)
	}
	if decode(t, rec)["error"] == nil {
		t.Error("expected an error message in the body")
	}
}

func TestReportRecordsOutcomeAndAffectsHealth(t *testing.T) {
	p := pool.New([]pool.Proxy{{ID: "alpha", URL: "http://alpha", Weight: 1}},
		pool.Options{FailureThreshold: 2, Cooldown: time.Minute})
	h := NewRouter(NewServer(p, quietLogger()), "", quietLogger())

	for range 2 {
		rec := do(t, h, http.MethodPost, "/v1/proxy/alpha/report",
			`{"success":false,"latency_ms":10}`, nil)

		if rec.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", rec.Code, rec.Body.String())
		}
	}

	if p.HealthyCount() != 0 {
		t.Error("two failures at threshold 2 should have benched the proxy")
	}
}

func TestReportReturns404ForUnknownProxy(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "alpha", URL: "http://alpha", Weight: 1}}, "")

	rec := do(t, h, http.MethodPost, "/v1/proxy/ghost/report", `{"success":true}`, nil)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("expected 404, got %d", rec.Code)
	}
}

func TestReportRejectsInvalidBodies(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "alpha", URL: "http://alpha", Weight: 1}}, "")

	tests := []struct {
		name string
		body string
	}{
		{"malformed json", `{"success":`},
		{"unknown field", `{"success":true,"unexpected":1}`},
		{"wrong type", `{"success":"yes"}`},
		{"negative latency", `{"success":true,"latency_ms":-5}`},
		{"empty body", ``},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			rec := do(t, h, http.MethodPost, "/v1/proxy/alpha/report", tc.body, nil)

			if rec.Code != http.StatusBadRequest {
				t.Errorf("expected 400, got %d (body: %s)", rec.Code, rec.Body.String())
			}
		})
	}
}

func TestListProxiesReturnsStats(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 2},
	}, "")

	rec := do(t, h, http.MethodGet, "/v1/proxies", "", nil)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}

	body := decode(t, rec)
	if body["total"] != float64(2) {
		t.Errorf("expected total 2, got %v", body["total"])
	}
	if body["healthy"] != float64(2) {
		t.Errorf("expected healthy 2, got %v", body["healthy"])
	}

	proxies, ok := body["proxies"].([]any)
	if !ok || len(proxies) != 2 {
		t.Fatalf("expected a list of 2 proxies, got %v", body["proxies"])
	}
}

func TestWrongMethodIsRejected(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "a", URL: "http://a", Weight: 1}}, "")

	tests := []struct {
		method string
		path   string
	}{
		{http.MethodPost, "/v1/proxy/next"},
		{http.MethodGet, "/v1/proxy/a/report"},
		{http.MethodDelete, "/v1/proxies"},
	}

	for _, tc := range tests {
		t.Run(tc.method+" "+tc.path, func(t *testing.T) {
			rec := do(t, h, tc.method, tc.path, "", nil)

			if rec.Code == http.StatusOK {
				t.Errorf("expected %s %s to be rejected, got 200", tc.method, tc.path)
			}
		})
	}
}

func TestResponsesAreJSONWithNosniff(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "a", URL: "http://a", Weight: 1}}, "")

	rec := do(t, h, http.MethodGet, "/v1/proxies", "", nil)

	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("expected a JSON content type, got %q", ct)
	}
	if rec.Header().Get("X-Content-Type-Options") != "nosniff" {
		t.Error("expected the nosniff header on API responses")
	}
}
