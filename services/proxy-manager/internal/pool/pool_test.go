package pool

import (
	"errors"
	"sync"
	"testing"
	"time"
)

// fixedClock returns a clock whose time can be moved forward by tests, so
// cooldown expiry can be verified without any real sleeping.
func fixedClock(start time.Time) (now func() time.Time, advance func(time.Duration)) {
	current := start
	var mu sync.Mutex

	now = func() time.Time {
		mu.Lock()
		defer mu.Unlock()
		return current
	}
	advance = func(d time.Duration) {
		mu.Lock()
		defer mu.Unlock()
		current = current.Add(d)
	}
	return now, advance
}

// testPool builds a pool with a controllable clock.
func testPool(t *testing.T, proxies []Proxy, threshold int, cooldown time.Duration) (*Pool, func(time.Duration)) {
	t.Helper()

	now, advance := fixedClock(time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC))
	p := New(proxies, Options{
		FailureThreshold: threshold,
		Cooldown:         cooldown,
		Now:              now,
	})
	return p, advance
}

func TestNextReturnsErrorWhenPoolIsEmpty(t *testing.T) {
	p, _ := testPool(t, nil, 3, time.Minute)

	_, err := p.Next()

	if !errors.Is(err, ErrNoProxyAvailable) {
		t.Fatalf("expected ErrNoProxyAvailable, got %v", err)
	}
}

func TestNextRotatesEvenlyAcrossEqualWeights(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 1},
		{ID: "c", URL: "http://c", Weight: 1},
	}, 3, time.Minute)

	counts := map[string]int{}
	for range 30 {
		proxy, err := p.Next()
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		counts[proxy.ID]++
	}

	for _, id := range []string{"a", "b", "c"} {
		if counts[id] != 10 {
			t.Errorf("expected proxy %q to be picked 10 times, got %d", id, counts[id])
		}
	}
}

func TestNextRespectsConfiguredWeights(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "heavy", URL: "http://heavy", Weight: 3},
		{ID: "light", URL: "http://light", Weight: 1},
	}, 3, time.Minute)

	counts := map[string]int{}
	for range 40 {
		proxy, _ := p.Next()
		counts[proxy.ID]++
	}

	// A 3:1 weighting over 40 picks means 30 heavy and 10 light.
	if counts["heavy"] != 30 {
		t.Errorf("expected heavy proxy 30 times, got %d", counts["heavy"])
	}
	if counts["light"] != 10 {
		t.Errorf("expected light proxy 10 times, got %d", counts["light"])
	}
}

func TestNextDoesNotReturnTheSameProxyTwiceInARow(t *testing.T) {
	// Smooth weighted round-robin should interleave picks rather than
	// exhausting one proxy's weight before moving on.
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 2},
		{ID: "b", URL: "http://b", Weight: 2},
	}, 3, time.Minute)

	previous := ""
	for i := range 10 {
		proxy, _ := p.Next()
		if proxy.ID == previous {
			t.Fatalf("pick %d repeated proxy %q back-to-back", i, proxy.ID)
		}
		previous = proxy.ID
	}
}

func TestReportUnknownProxyReturnsError(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 3, time.Minute)

	err := p.Report("does-not-exist", true, time.Millisecond)

	if !errors.Is(err, ErrUnknownProxy) {
		t.Fatalf("expected ErrUnknownProxy, got %v", err)
	}
}

func TestProxyEntersCooldownAfterConsecutiveFailures(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 1},
	}, 3, time.Minute)

	for range 3 {
		if err := p.Report("a", false, time.Millisecond); err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
	}

	if got := p.HealthyCount(); got != 1 {
		t.Fatalf("expected 1 healthy proxy after benching, got %d", got)
	}

	// Every subsequent pick must avoid the benched proxy.
	for range 5 {
		proxy, err := p.Next()
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		if proxy.ID == "a" {
			t.Fatal("benched proxy was handed out")
		}
	}
}

func TestFailuresBelowThresholdDoNotBenchProxy(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 3, time.Minute)

	p.Report("a", false, time.Millisecond)
	p.Report("a", false, time.Millisecond)

	if got := p.HealthyCount(); got != 1 {
		t.Fatalf("proxy should still be healthy after 2 of 3 failures, got %d healthy", got)
	}
}

func TestSuccessResetsTheFailureStreak(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 3, time.Minute)

	p.Report("a", false, time.Millisecond)
	p.Report("a", false, time.Millisecond)
	p.Report("a", true, time.Millisecond) // streak broken here
	p.Report("a", false, time.Millisecond)
	p.Report("a", false, time.Millisecond)

	if got := p.HealthyCount(); got != 1 {
		t.Fatal("a success between failures should have prevented the bench")
	}
}

func TestCooldownExpiresAfterTheConfiguredDuration(t *testing.T) {
	p, advance := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 2, time.Minute)

	p.Report("a", false, time.Millisecond)
	p.Report("a", false, time.Millisecond)

	if p.HealthyCount() != 0 {
		t.Fatal("expected the only proxy to be benched")
	}

	advance(61 * time.Second)

	if p.HealthyCount() != 1 {
		t.Fatal("expected the proxy to recover once the cooldown expired")
	}
}

func TestSuccessImmediatelyEndsAnActiveCooldown(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 2, time.Minute)

	p.Report("a", false, time.Millisecond)
	p.Report("a", false, time.Millisecond)

	if p.HealthyCount() != 0 {
		t.Fatal("expected the proxy to be benched")
	}

	// The background health checker proving the proxy works should return it
	// to service without waiting out the full cooldown.
	p.Report("a", true, time.Millisecond)

	if p.HealthyCount() != 1 {
		t.Fatal("a successful probe should have cleared the cooldown")
	}
}

func TestStatsReportsAccurateCounters(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 2}}, 5, time.Minute)

	p.Report("a", true, 120*time.Millisecond)
	p.Report("a", true, 80*time.Millisecond)
	p.Report("a", false, 40*time.Millisecond)

	stats := p.Stats()
	if len(stats) != 1 {
		t.Fatalf("expected stats for 1 proxy, got %d", len(stats))
	}

	s := stats[0]
	if s.TotalSuccesses != 2 {
		t.Errorf("expected 2 successes, got %d", s.TotalSuccesses)
	}
	if s.TotalFailures != 1 {
		t.Errorf("expected 1 failure, got %d", s.TotalFailures)
	}
	if s.LastLatencyMS != 40 {
		t.Errorf("expected last latency 40ms, got %dms", s.LastLatencyMS)
	}
	if !s.Healthy {
		t.Error("proxy should still be healthy below the failure threshold")
	}
	if s.SuccessRate < 0.66 || s.SuccessRate > 0.67 {
		t.Errorf("expected a success rate near 0.667, got %v", s.SuccessRate)
	}
}

func TestStatsIncludesCooldownTimestampWhenBenched(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 1, time.Minute)

	p.Report("a", false, time.Millisecond)

	stats := p.Stats()
	if stats[0].CooldownUntil == nil {
		t.Fatal("expected a cooldown timestamp on a benched proxy")
	}
	if stats[0].Healthy {
		t.Error("a benched proxy must not report as healthy")
	}
}

func TestNewAppliesDefaultWeightToInvalidValues(t *testing.T) {
	tests := []struct {
		name  string
		given int
	}{
		{"zero weight", 0},
		{"negative weight", -5},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: tc.given}}, 3, time.Minute)

			if got := p.Stats()[0].Weight; got != 1 {
				t.Errorf("expected weight to default to 1, got %d", got)
			}
		})
	}
}

func TestFlakyProxyIsPickedLessOftenThanAReliableOne(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "reliable", URL: "http://reliable", Weight: 4},
		{ID: "flaky", URL: "http://flaky", Weight: 4},
	}, 100, time.Minute)

	// Give the flaky proxy a poor recent record without benching it.
	for range 15 {
		p.Report("flaky", false, time.Millisecond)
	}
	for range 5 {
		p.Report("flaky", true, time.Millisecond)
	}
	for range 20 {
		p.Report("reliable", true, time.Millisecond)
	}

	counts := map[string]int{}
	for range 50 {
		proxy, _ := p.Next()
		counts[proxy.ID]++
	}

	if counts["flaky"] >= counts["reliable"] {
		t.Errorf("expected the flaky proxy to be picked less often; flaky=%d reliable=%d",
			counts["flaky"], counts["reliable"])
	}
	if counts["flaky"] == 0 {
		t.Error("a flaky proxy should still get occasional traffic so it can recover")
	}
}

func TestSizeAndHealthyCount(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 1},
	}, 1, time.Minute)

	if p.Size() != 2 {
		t.Errorf("expected size 2, got %d", p.Size())
	}
	if p.HealthyCount() != 2 {
		t.Errorf("expected 2 healthy, got %d", p.HealthyCount())
	}

	p.Report("a", false, time.Millisecond)

	if p.Size() != 2 {
		t.Errorf("size should not change when a proxy is benched, got %d", p.Size())
	}
	if p.HealthyCount() != 1 {
		t.Errorf("expected 1 healthy after benching, got %d", p.HealthyCount())
	}
}

func TestAllReturnsCopiesNotInternalPointers(t *testing.T) {
	p, _ := testPool(t, []Proxy{{ID: "a", URL: "http://a", Weight: 1}}, 3, time.Minute)

	snapshot := p.All()
	snapshot[0].ID = "mutated"

	if p.All()[0].ID != "a" {
		t.Fatal("mutating a snapshot must not affect pool state")
	}
}

// TestConcurrentAccessIsRaceFree is the reason the pool carries a mutex:
// Laravel requests proxies while the health checker reports on them.
// Run with `go test -race` to make this meaningful.
func TestConcurrentAccessIsRaceFree(t *testing.T) {
	p, _ := testPool(t, []Proxy{
		{ID: "a", URL: "http://a", Weight: 1},
		{ID: "b", URL: "http://b", Weight: 2},
	}, 3, time.Minute)

	var wg sync.WaitGroup
	for range 20 {
		wg.Add(3)

		go func() { defer wg.Done(); p.Next() }()
		go func() { defer wg.Done(); p.Report("a", true, time.Millisecond) }()
		go func() { defer wg.Done(); p.Stats() }()
	}

	wg.Wait()
}
