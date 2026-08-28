package httpapi

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/marwanshokry/palm-task/proxy-manager/internal/pool"
)

func TestRequireAPIKeyAllowsEveryRequestWhenNoKeyIsConfigured(t *testing.T) {
	// Local development convenience: main.go logs a warning when this happens.
	called := false
	next := http.HandlerFunc(func(http.ResponseWriter, *http.Request) { called = true })

	handler := RequireAPIKey("", quietLogger(), next)
	handler.ServeHTTP(httptest.NewRecorder(), httptest.NewRequest(http.MethodGet, "/v1/proxies", nil))

	if !called {
		t.Fatal("expected the request to pass through when no key is configured")
	}
}

func TestRequireAPIKeyRejectsBadKeys(t *testing.T) {
	tests := []struct {
		name     string
		provided string
	}{
		{"no header at all", ""},
		{"wrong key", "wrong-key"},
		{"correct prefix but truncated", "s3cr"},
		{"correct key with extra characters", "s3cret-extra"},
		{"case mismatch", "S3CRET"},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			called := false
			next := http.HandlerFunc(func(http.ResponseWriter, *http.Request) { called = true })
			handler := RequireAPIKey("s3cret", quietLogger(), next)

			req := httptest.NewRequest(http.MethodGet, "/v1/proxies", nil)
			if tc.provided != "" {
				req.Header.Set(HeaderAPIKey, tc.provided)
			}
			rec := httptest.NewRecorder()

			handler.ServeHTTP(rec, req)

			if rec.Code != http.StatusUnauthorized {
				t.Errorf("expected 401, got %d", rec.Code)
			}
			if called {
				t.Error("the protected handler must not run for a bad key")
			}
		})
	}
}

func TestRequireAPIKeyAcceptsTheCorrectKey(t *testing.T) {
	called := false
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		called = true
		w.WriteHeader(http.StatusOK)
	})
	handler := RequireAPIKey("s3cret", quietLogger(), next)

	req := httptest.NewRequest(http.MethodGet, "/v1/proxies", nil)
	req.Header.Set(HeaderAPIKey, "s3cret")
	rec := httptest.NewRecorder()

	handler.ServeHTTP(rec, req)

	if !called {
		t.Fatal("expected the protected handler to run")
	}
	if rec.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", rec.Code)
	}
}

func TestProtectedRoutesRequireTheAPIKeyEndToEnd(t *testing.T) {
	h := newTestServer(t, []pool.Proxy{{ID: "a", URL: "http://a", Weight: 1}}, "s3cret")

	tests := []struct {
		method string
		path   string
		body   string
	}{
		{http.MethodGet, "/v1/proxy/next", ""},
		{http.MethodPost, "/v1/proxy/a/report", `{"success":true}`},
		{http.MethodGet, "/v1/proxies", ""},
	}

	for _, tc := range tests {
		t.Run(tc.path, func(t *testing.T) {
			// Without the key.
			rec := do(t, h, tc.method, tc.path, tc.body, nil)
			if rec.Code != http.StatusUnauthorized {
				t.Errorf("expected 401 without a key, got %d", rec.Code)
			}

			// With the key.
			rec = do(t, h, tc.method, tc.path, tc.body,
				map[string]string{HeaderAPIKey: "s3cret"})
			if rec.Code == http.StatusUnauthorized {
				t.Error("expected the request to be authorised with the correct key")
			}
		})
	}
}

func TestRequestLoggerPassesTheStatusThrough(t *testing.T) {
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusTeapot)
	})

	handler := RequestLogger(quietLogger(), next)
	rec := httptest.NewRecorder()

	handler.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/anything", nil))

	if rec.Code != http.StatusTeapot {
		t.Errorf("expected the logger to preserve status 418, got %d", rec.Code)
	}
}

func TestPoolIsUsableThroughTheRouterAfterReports(t *testing.T) {
	// A small end-to-end check that the router, auth, handlers and pool all
	// agree on the proxy id passed through the URL path.
	p := pool.New([]pool.Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 1},
	}, pool.Options{FailureThreshold: 1, Cooldown: time.Minute})

	h := NewRouter(NewServer(p, quietLogger()), "", quietLogger())

	do(t, h, http.MethodPost, "/v1/proxy/a/report", `{"success":false}`, nil)

	for range 4 {
		rec := do(t, h, http.MethodGet, "/v1/proxy/next", "", nil)
		if decode(t, rec)["id"] == "a" {
			t.Fatal("the benched proxy was handed out through the API")
		}
	}
}
