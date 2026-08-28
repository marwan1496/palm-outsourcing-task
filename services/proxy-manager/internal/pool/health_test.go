package pool

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// quietLogger keeps test output readable.
func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

// stubTransport answers every request with a fixed status, or an error,
// without touching the network.
type stubTransport struct {
	status int
	err    error
}

func (s stubTransport) RoundTrip(r *http.Request) (*http.Response, error) {
	if s.err != nil {
		return nil, s.err
	}
	return &http.Response{
		StatusCode: s.status,
		Body:       io.NopCloser(io.MultiReader()),
		Request:    r,
	}, nil
}

// checkerWithTransport builds a Checker whose probes use the given transport.
func checkerWithTransport(p *Pool, tr http.RoundTripper) *Checker {
	c := NewChecker(p, CheckerOptions{
		ProbeURL: "https://example.test",
		Interval: time.Hour,
		Timeout:  time.Second,
		Logger:   quietLogger(),
	})
	c.newClient = func(string, time.Duration) (*http.Client, error) {
		return &http.Client{Transport: tr}, nil
	}
	return c
}

func TestCheckAllMarksReachableProxiesHealthy(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)

	// Bench the proxy first so we can prove the checker revives it.
	p.Report("a", false, time.Millisecond)
	if p.HealthyCount() != 0 {
		t.Fatal("setup failed: proxy should start benched")
	}

	checkerWithTransport(p, stubTransport{status: http.StatusOK}).CheckAll(context.Background())

	if p.HealthyCount() != 1 {
		t.Error("a successful probe should have returned the proxy to service")
	}
}

func TestCheckAllBenchesUnreachableProxies(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)

	checkerWithTransport(p, stubTransport{err: context.DeadlineExceeded}).
		CheckAll(context.Background())

	if p.HealthyCount() != 0 {
		t.Error("a failed probe at threshold 1 should have benched the proxy")
	}
}

func TestProbeTreatsClientErrorsFromTheTargetAsHealthy(t *testing.T) {
	// A 403 is the target site's opinion of us, not evidence the proxy is
	// broken, so the proxy must stay in rotation.
	tests := []struct {
		name        string
		status      int
		wantHealthy bool
	}{
		{"200 OK", http.StatusOK, true},
		{"301 redirect", http.StatusMovedPermanently, true},
		{"403 forbidden by target", http.StatusForbidden, true},
		{"404 not found", http.StatusNotFound, true},
		{"500 server error", http.StatusInternalServerError, false},
		{"502 bad gateway", http.StatusBadGateway, false},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)

			checkerWithTransport(p, stubTransport{status: tc.status}).
				CheckAll(context.Background())

			gotHealthy := p.HealthyCount() == 1
			if gotHealthy != tc.wantHealthy {
				t.Errorf("status %d: expected healthy=%v, got %v",
					tc.status, tc.wantHealthy, gotHealthy)
			}
		})
	}
}

func TestCheckAllProbesEveryProxy(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 1},
		{ID: "c", URL: "http://c", Weight: 1},
	}, 1, time.Minute)

	checkerWithTransport(p, stubTransport{status: http.StatusOK}).CheckAll(context.Background())

	for _, s := range p.Stats() {
		if s.TotalSuccesses != 1 {
			t.Errorf("proxy %s was not probed exactly once (successes=%d)", s.ID, s.TotalSuccesses)
		}
	}
}

func TestCheckAllIsANoOpForAnEmptyPool(t *testing.T) {
	p, _ := testPool(t, nil, 1, time.Minute)

	// The bare assertion here is that this returns rather than blocking.
	checkerWithTransport(p, stubTransport{status: http.StatusOK}).CheckAll(context.Background())
}

func TestCheckAllStopsWhenContextIsCancelled(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	done := make(chan struct{})
	go func() {
		checkerWithTransport(p, stubTransport{status: http.StatusOK}).CheckAll(ctx)
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("CheckAll did not return promptly after context cancellation")
	}
}

func TestRunProbesImmediatelyThenStopsOnCancel(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)
	p.Report("a", false, time.Millisecond) // bench it

	checker := checkerWithTransport(p, stubTransport{status: http.StatusOK})
	checker.interval = time.Hour // ensure only the startup probe can run

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		checker.Run(ctx)
		close(done)
	}()

	// The startup probe should revive the proxy well before the first tick.
	deadline := time.After(2 * time.Second)
	for p.HealthyCount() == 0 {
		select {
		case <-deadline:
			t.Fatal("Run did not probe on startup")
		case <-time.After(5 * time.Millisecond):
		}
	}

	cancel()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Run did not stop after context cancellation")
	}
}

func TestNewProxyClientRejectsAnUnparseableURL(t *testing.T) {
	_, err := newProxyClient("://not a url", time.Second)

	if err == nil {
		t.Fatal("expected an error for a malformed proxy url")
	}
}

func TestNewProxyClientBuildsAProxiedClient(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer upstream.Close()

	client, err := newProxyClient(upstream.URL, time.Second)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if client.Timeout != time.Second {
		t.Errorf("expected the timeout to be applied, got %s", client.Timeout)
	}
	if client.Transport == nil {
		t.Error("expected a transport configured with the proxy")
	}
}
