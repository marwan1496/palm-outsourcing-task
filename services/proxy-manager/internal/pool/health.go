package pool

import (
	"context"
	"log/slog"
	"net/http"
	"net/url"
	"time"
)

// Checker periodically probes every proxy so the pool learns a proxy has
// recovered without waiting for a real scrape to discover it.
//
// Why it exists: without it, a proxy pushed into cooldown would only be
// retried when the cooldown expires and a live scrape happens to pick it —
// meaning a real user request pays for the discovery. The checker moves that
// cost into the background.
type Checker struct {
	pool     *Pool
	probeURL string
	interval time.Duration
	timeout  time.Duration
	logger   *slog.Logger

	// newClient builds the HTTP client used for one probe. It is a field so
	// tests can substitute a client that does not touch the network.
	newClient func(proxyURL string, timeout time.Duration) (*http.Client, error)
}

// CheckerOptions configures a Checker.
type CheckerOptions struct {
	ProbeURL string
	Interval time.Duration
	Timeout  time.Duration
	Logger   *slog.Logger
}

// NewChecker builds a health checker for the given pool.
func NewChecker(p *Pool, opts CheckerOptions) *Checker {
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}

	return &Checker{
		pool:      p,
		probeURL:  opts.ProbeURL,
		interval:  opts.Interval,
		timeout:   opts.Timeout,
		logger:    opts.Logger,
		newClient: newProxyClient,
	}
}

// Run probes every proxy on a ticker until ctx is cancelled.
//
// It blocks, so callers run it in its own goroutine. Cancelling the context is
// the only way to stop it, which keeps shutdown handling in one place.
func (c *Checker) Run(ctx context.Context) {
	ticker := time.NewTicker(c.interval)
	defer ticker.Stop()

	// Probe once immediately so the pool has real health data from startup
	// rather than after a full interval of guessing.
	c.CheckAll(ctx)

	for {
		select {
		case <-ctx.Done():
			c.logger.Info("health checker stopped")
			return
		case <-ticker.C:
			c.CheckAll(ctx)
		}
	}
}

// CheckAll probes every proxy once, concurrently, and reports each outcome to
// the pool. It returns when every probe has finished or ctx is cancelled.
func (c *Checker) CheckAll(ctx context.Context) {
	proxies := c.pool.All()
	if len(proxies) == 0 {
		return
	}

	done := make(chan struct{}, len(proxies))

	for _, proxy := range proxies {
		go func(p Proxy) {
			defer func() { done <- struct{}{} }()

			ok, latency := c.probe(ctx, p)
			if err := c.pool.Report(p.ID, ok, latency); err != nil {
				c.logger.Warn("health report failed", "proxy", p.ID, "error", err)
			}

			c.logger.Debug("probed proxy",
				"proxy", p.ID, "healthy", ok, "latency_ms", latency.Milliseconds())
		}(proxy)
	}

	for range proxies {
		select {
		case <-done:
		case <-ctx.Done():
			return
		}
	}
}

// probe makes one request through the proxy and reports whether it worked,
// along with how long it took.
func (c *Checker) probe(ctx context.Context, p Proxy) (bool, time.Duration) {
	client, err := c.newClient(p.URL, c.timeout)
	if err != nil {
		c.logger.Warn("proxy has an unusable url", "proxy", p.ID, "error", err)
		return false, 0
	}

	ctx, cancel := context.WithTimeout(ctx, c.timeout)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodHead, c.probeURL, nil)
	if err != nil {
		return false, 0
	}

	start := time.Now()
	resp, err := client.Do(req)
	latency := time.Since(start)

	if err != nil {
		return false, latency
	}
	defer resp.Body.Close()

	// Any response below 500 proves the proxy itself forwarded our traffic.
	// A 403 from the target site is the target's opinion of us, not evidence
	// that the proxy is broken, so it still counts as healthy.
	return resp.StatusCode < http.StatusInternalServerError, latency
}

// newProxyClient builds an HTTP client that routes through the given proxy.
func newProxyClient(proxyURL string, timeout time.Duration) (*http.Client, error) {
	parsed, err := url.Parse(proxyURL)
	if err != nil {
		return nil, err
	}

	return &http.Client{
		Timeout:   timeout,
		Transport: &http.Transport{Proxy: http.ProxyURL(parsed)},
	}, nil
}
