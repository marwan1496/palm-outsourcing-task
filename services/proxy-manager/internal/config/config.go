// Package config loads the proxy-manager's settings from a YAML file and the
// environment.
//
// Why it exists: the proxy list changes far more often than the code does, so
// it lives in a YAML file that an operator can edit and comment. Everything
// that differs between machines (ports, secrets) comes from the environment,
// because secrets must never be committed to the repository.
package config

import (
	"fmt"
	"os"
	"strconv"
	"time"

	"gopkg.in/yaml.v3"
)

// Default values used whenever the environment does not override them.
// They are deliberately conservative: slow enough to be polite to upstream
// proxies, fast enough that a dead proxy is noticed within a minute.
const (
	DefaultListenAddr          = ":8081"
	DefaultHealthCheckInterval = 30 * time.Second
	DefaultHealthCheckTimeout  = 5 * time.Second
	DefaultFailureThreshold    = 3
	DefaultCooldown            = 60 * time.Second
	DefaultProbeURL            = "https://www.jumia.com.eg"
)

// Proxy is one entry from the YAML proxy list.
type Proxy struct {
	// ID is a stable, human-readable name. Laravel sends this back when it
	// reports whether a scrape through this proxy succeeded, so it must not
	// change between restarts.
	ID string `yaml:"id"`

	// URL is the full proxy address, e.g. "http://user:pass@1.2.3.4:8080".
	URL string `yaml:"url"`

	// Weight biases selection towards better proxies. A proxy with weight 3
	// is handed out roughly three times as often as one with weight 1.
	Weight int `yaml:"weight"`
}

// Config is the fully resolved configuration the service runs with.
type Config struct {
	ListenAddr          string
	APIKey              string
	ProbeURL            string
	HealthCheckInterval time.Duration
	HealthCheckTimeout  time.Duration
	FailureThreshold    int
	Cooldown            time.Duration
	Proxies             []Proxy
}

// proxyFile mirrors the on-disk YAML structure.
type proxyFile struct {
	Proxies []Proxy `yaml:"proxies"`
}

// Load reads the proxy list from path and layers environment overrides on top.
//
// An empty path is allowed and yields a config with no proxies: the service
// still starts and simply reports that it has nothing to hand out, which lets
// Laravel fall back to direct connections instead of failing outright.
func Load(path string) (Config, error) {
	cfg := Config{
		ListenAddr:          envString("PROXY_LISTEN_ADDR", DefaultListenAddr),
		APIKey:              os.Getenv("PROXY_API_KEY"),
		ProbeURL:            envString("PROXY_PROBE_URL", DefaultProbeURL),
		HealthCheckInterval: envDuration("PROXY_HEALTHCHECK_INTERVAL", DefaultHealthCheckInterval),
		HealthCheckTimeout:  envDuration("PROXY_HEALTHCHECK_TIMEOUT", DefaultHealthCheckTimeout),
		FailureThreshold:    envInt("PROXY_FAILURE_THRESHOLD", DefaultFailureThreshold),
		Cooldown:            envDuration("PROXY_COOLDOWN", DefaultCooldown),
	}

	if path != "" {
		proxies, err := loadProxies(path)
		if err != nil {
			return Config{}, err
		}
		cfg.Proxies = proxies
	}

	return cfg, cfg.Validate()
}

// loadProxies parses the YAML proxy list and applies per-entry defaults.
func loadProxies(path string) ([]Proxy, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read proxy file %q: %w", path, err)
	}

	var file proxyFile
	if err := yaml.Unmarshal(raw, &file); err != nil {
		return nil, fmt.Errorf("parse proxy file %q: %w", path, err)
	}

	for i := range file.Proxies {
		// A missing or nonsensical weight is treated as 1 rather than an
		// error: one typo in the list should not stop the service booting.
		if file.Proxies[i].Weight <= 0 {
			file.Proxies[i].Weight = 1
		}
	}

	return file.Proxies, nil
}

// Validate rejects configurations that would misbehave at runtime.
func (c Config) Validate() error {
	if c.FailureThreshold < 1 {
		return fmt.Errorf("failure threshold must be at least 1, got %d", c.FailureThreshold)
	}
	if c.HealthCheckInterval <= 0 {
		return fmt.Errorf("health check interval must be positive, got %s", c.HealthCheckInterval)
	}
	if c.HealthCheckTimeout <= 0 {
		return fmt.Errorf("health check timeout must be positive, got %s", c.HealthCheckTimeout)
	}

	seen := make(map[string]bool, len(c.Proxies))
	for _, p := range c.Proxies {
		if p.ID == "" {
			return fmt.Errorf("every proxy needs an id (found one with url %q)", p.URL)
		}
		if p.URL == "" {
			return fmt.Errorf("proxy %q has no url", p.ID)
		}
		if seen[p.ID] {
			return fmt.Errorf("duplicate proxy id %q", p.ID)
		}
		seen[p.ID] = true
	}

	return nil
}

// envString returns the environment variable, or fallback when it is unset.
func envString(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// envInt reads an integer environment variable, falling back on absence or
// unparseable input. Bad input should not prevent the service from starting.
func envInt(key string, fallback int) int {
	v, err := strconv.Atoi(os.Getenv(key))
	if err != nil {
		return fallback
	}
	return v
}

// envDuration reads a Go duration string such as "30s" or "2m".
func envDuration(key string, fallback time.Duration) time.Duration {
	d, err := time.ParseDuration(os.Getenv(key))
	if err != nil {
		return fallback
	}
	return d
}
