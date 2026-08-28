// Package pool decides which proxy the scraper should use next.
//
// Why it exists: rotating proxies naively (plain round-robin) keeps handing out
// proxies that are already failing. This pool tracks the recent outcome of every
// proxy, biases selection towards the ones that are actually working, and
// benches a proxy entirely once it fails repeatedly.
//
// The whole type is safe for concurrent use: Laravel may ask for a proxy while
// the background health checker is reporting on another one.
package pool

import (
	"errors"
	"sync"
	"time"
)

// ErrNoProxyAvailable is returned when every proxy is in cooldown, or when the
// pool was configured with no proxies at all.
//
// This is an expected condition, not a crash: Laravel treats it as "scrape
// directly, without a proxy" rather than as a failure.
var ErrNoProxyAvailable = errors.New("no healthy proxy available")

// ErrUnknownProxy is returned when a report arrives for an ID we do not hold.
var ErrUnknownProxy = errors.New("unknown proxy id")

// recentWindow is how many outcomes we remember per proxy when computing its
// success rate. Small enough that a proxy recovers quickly once it starts
// working again, large enough that one unlucky request does not bench it.
const recentWindow = 20

// Proxy is a single upstream proxy plus the health state we track for it.
type Proxy struct {
	ID     string
	URL    string
	Weight int

	// recent is a ring buffer of the last `recentWindow` outcomes,
	// true meaning success. It gives us a *recent* success rate rather than
	// a lifetime average, so a proxy that recovers is trusted again quickly.
	recent    [recentWindow]bool
	recentLen int
	recentPos int

	consecutiveFailures int
	cooldownUntil       time.Time

	totalSuccesses int
	totalFailures  int
	lastLatency    time.Duration

	// currentWeight is the running score used by smooth weighted round-robin.
	currentWeight int
}

// Stats is the read-only snapshot of a proxy exposed over the HTTP API.
type Stats struct {
	ID             string  `json:"id"`
	URL            string  `json:"url"`
	Weight         int     `json:"weight"`
	Healthy        bool    `json:"healthy"`
	SuccessRate    float64 `json:"success_rate"`
	TotalSuccesses int     `json:"total_successes"`
	TotalFailures  int     `json:"total_failures"`
	LastLatencyMS  int64   `json:"last_latency_ms"`
	CooldownUntil  *string `json:"cooldown_until,omitempty"`
}

// Pool holds every configured proxy and the rotation cursor.
type Pool struct {
	mu      sync.RWMutex
	proxies []*Proxy

	failureThreshold int
	cooldown         time.Duration

	// now is injectable so tests can control cooldown expiry without sleeping.
	now func() time.Time
}

// Options configures a Pool.
type Options struct {
	FailureThreshold int
	Cooldown         time.Duration

	// Now may be nil, in which case time.Now is used.
	Now func() time.Time
}

// New builds a pool from a list of proxies.
func New(proxies []Proxy, opts Options) *Pool {
	if opts.Now == nil {
		opts.Now = time.Now
	}
	if opts.FailureThreshold < 1 {
		opts.FailureThreshold = 1
	}

	held := make([]*Proxy, 0, len(proxies))
	for _, p := range proxies {
		if p.Weight <= 0 {
			p.Weight = 1
		}
		held = append(held, &p)
	}

	return &Pool{
		proxies:          held,
		failureThreshold: opts.FailureThreshold,
		cooldown:         opts.Cooldown,
		now:              opts.Now,
	}
}

// Next returns the proxy that should be used for the next request.
//
// Selection uses smooth weighted round-robin (the algorithm nginx uses): each
// candidate accumulates its effective weight, the highest scorer is chosen, and
// that winner then pays back the total weight. The result spreads picks evenly
// instead of handing out the same heavy proxy several times in a row.
//
// Returns ErrNoProxyAvailable when every proxy is benched.
func (p *Pool) Next() (Proxy, error) {
	p.mu.Lock()
	defer p.mu.Unlock()

	now := p.now()
	var best *Proxy
	total := 0

	for _, proxy := range p.proxies {
		if proxy.inCooldown(now) {
			continue
		}

		weight := proxy.effectiveWeight()
		proxy.currentWeight += weight
		total += weight

		if best == nil || proxy.currentWeight > best.currentWeight {
			best = proxy
		}
	}

	if best == nil {
		return Proxy{}, ErrNoProxyAvailable
	}

	best.currentWeight -= total

	return best.snapshot(), nil
}

// Report records the outcome of a request made through the given proxy.
//
// Laravel calls this after every scrape. Consecutive failures push the proxy
// into cooldown; any success clears the failure streak immediately.
func (p *Pool) Report(id string, success bool, latency time.Duration) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	proxy := p.find(id)
	if proxy == nil {
		return ErrUnknownProxy
	}

	proxy.recordOutcome(success)
	proxy.lastLatency = latency

	if success {
		proxy.totalSuccesses++
		proxy.consecutiveFailures = 0
		// A success also ends any active cooldown: the proxy has proven it
		// works, so there is no reason to keep it benched.
		proxy.cooldownUntil = time.Time{}
		return nil
	}

	proxy.totalFailures++
	proxy.consecutiveFailures++
	if proxy.consecutiveFailures >= p.failureThreshold {
		proxy.cooldownUntil = p.now().Add(p.cooldown)
	}

	return nil
}

// Stats returns a snapshot of every proxy, for the /v1/proxies endpoint.
func (p *Pool) Stats() []Stats {
	p.mu.RLock()
	defer p.mu.RUnlock()

	now := p.now()
	out := make([]Stats, 0, len(p.proxies))

	for _, proxy := range p.proxies {
		stat := Stats{
			ID:             proxy.ID,
			URL:            proxy.URL,
			Weight:         proxy.Weight,
			Healthy:        !proxy.inCooldown(now),
			SuccessRate:    proxy.successRate(),
			TotalSuccesses: proxy.totalSuccesses,
			TotalFailures:  proxy.totalFailures,
			LastLatencyMS:  proxy.lastLatency.Milliseconds(),
		}
		if proxy.inCooldown(now) {
			until := proxy.cooldownUntil.UTC().Format(time.RFC3339)
			stat.CooldownUntil = &until
		}
		out = append(out, stat)
	}

	return out
}

// HealthyCount reports how many proxies are currently eligible for selection.
func (p *Pool) HealthyCount() int {
	p.mu.RLock()
	defer p.mu.RUnlock()

	now := p.now()
	count := 0
	for _, proxy := range p.proxies {
		if !proxy.inCooldown(now) {
			count++
		}
	}
	return count
}

// Size reports how many proxies are configured, healthy or not.
func (p *Pool) Size() int {
	p.mu.RLock()
	defer p.mu.RUnlock()
	return len(p.proxies)
}

// All returns a snapshot of every proxy, used by the health checker so it can
// probe each one without holding the pool lock during slow network calls.
func (p *Pool) All() []Proxy {
	p.mu.RLock()
	defer p.mu.RUnlock()

	out := make([]Proxy, 0, len(p.proxies))
	for _, proxy := range p.proxies {
		out = append(out, proxy.snapshot())
	}
	return out
}

// find locates a proxy by ID. Callers must already hold the lock.
func (p *Pool) find(id string) *Proxy {
	for _, proxy := range p.proxies {
		if proxy.ID == id {
			return proxy
		}
	}
	return nil
}

// inCooldown reports whether the proxy is currently benched.
func (p *Proxy) inCooldown(now time.Time) bool {
	return now.Before(p.cooldownUntil)
}

// effectiveWeight scales the configured weight by the proxy's recent success
// rate, so a flaky-but-not-yet-benched proxy is picked less often.
//
// The result is floored at 1 so a struggling proxy still gets occasional
// traffic and therefore a chance to prove it has recovered.
func (p *Proxy) effectiveWeight() int {
	if p.recentLen == 0 {
		return p.Weight
	}

	scaled := int(float64(p.Weight)*p.successRate() + 0.5)
	if scaled < 1 {
		return 1
	}
	return scaled
}

// successRate is the share of recent requests that succeeded, in [0,1].
// A proxy with no recorded history is optimistically treated as perfect.
func (p *Proxy) successRate() float64 {
	if p.recentLen == 0 {
		return 1
	}

	successes := 0
	for i := range p.recentLen {
		if p.recent[i] {
			successes++
		}
	}
	return float64(successes) / float64(p.recentLen)
}

// recordOutcome appends a result to the ring buffer, overwriting the oldest
// entry once the window is full.
func (p *Proxy) recordOutcome(success bool) {
	p.recent[p.recentPos] = success
	p.recentPos = (p.recentPos + 1) % recentWindow
	if p.recentLen < recentWindow {
		p.recentLen++
	}
}

// snapshot copies the fields that are safe to hand outside the lock.
func (p *Proxy) snapshot() Proxy {
	return Proxy{ID: p.ID, URL: p.URL, Weight: p.Weight}
}
