package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// writeTempFile creates a config file for one test and returns its path.
func writeTempFile(t *testing.T, contents string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "proxies.yaml")
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatalf("could not write temp config: %v", err)
	}
	return path
}

func TestLoadUsesDefaultsWhenNoEnvironmentIsSet(t *testing.T) {
	cfg, err := Load("")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if cfg.ListenAddr != DefaultListenAddr {
		t.Errorf("expected listen addr %q, got %q", DefaultListenAddr, cfg.ListenAddr)
	}
	if cfg.FailureThreshold != DefaultFailureThreshold {
		t.Errorf("expected threshold %d, got %d", DefaultFailureThreshold, cfg.FailureThreshold)
	}
	if cfg.Cooldown != DefaultCooldown {
		t.Errorf("expected cooldown %s, got %s", DefaultCooldown, cfg.Cooldown)
	}
	if len(cfg.Proxies) != 0 {
		t.Errorf("expected no proxies when no file is given, got %d", len(cfg.Proxies))
	}
}

func TestLoadReadsEnvironmentOverrides(t *testing.T) {
	t.Setenv("PROXY_LISTEN_ADDR", ":9999")
	t.Setenv("PROXY_API_KEY", "s3cret")
	t.Setenv("PROXY_FAILURE_THRESHOLD", "7")
	t.Setenv("PROXY_COOLDOWN", "2m")
	t.Setenv("PROXY_HEALTHCHECK_INTERVAL", "45s")

	cfg, err := Load("")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if cfg.ListenAddr != ":9999" {
		t.Errorf("expected listen addr :9999, got %q", cfg.ListenAddr)
	}
	if cfg.APIKey != "s3cret" {
		t.Errorf("expected api key to be read from the environment, got %q", cfg.APIKey)
	}
	if cfg.FailureThreshold != 7 {
		t.Errorf("expected threshold 7, got %d", cfg.FailureThreshold)
	}
	if cfg.Cooldown != 2*time.Minute {
		t.Errorf("expected cooldown 2m, got %s", cfg.Cooldown)
	}
	if cfg.HealthCheckInterval != 45*time.Second {
		t.Errorf("expected interval 45s, got %s", cfg.HealthCheckInterval)
	}
}

func TestLoadFallsBackWhenEnvironmentValuesAreUnparseable(t *testing.T) {
	// A typo in an env var should not stop the service booting.
	t.Setenv("PROXY_FAILURE_THRESHOLD", "not-a-number")
	t.Setenv("PROXY_COOLDOWN", "banana")

	cfg, err := Load("")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if cfg.FailureThreshold != DefaultFailureThreshold {
		t.Errorf("expected fallback threshold, got %d", cfg.FailureThreshold)
	}
	if cfg.Cooldown != DefaultCooldown {
		t.Errorf("expected fallback cooldown, got %s", cfg.Cooldown)
	}
}

func TestLoadParsesProxyList(t *testing.T) {
	path := writeTempFile(t, `
proxies:
  - id: alpha
    url: http://alpha.example:8080
    weight: 3
  - id: beta
    url: http://beta.example:8080
    weight: 1
`)

	cfg, err := Load(path)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(cfg.Proxies) != 2 {
		t.Fatalf("expected 2 proxies, got %d", len(cfg.Proxies))
	}
	if cfg.Proxies[0].ID != "alpha" || cfg.Proxies[0].Weight != 3 {
		t.Errorf("first proxy parsed incorrectly: %+v", cfg.Proxies[0])
	}
}

func TestLoadDefaultsMissingWeightToOne(t *testing.T) {
	path := writeTempFile(t, `
proxies:
  - id: alpha
    url: http://alpha.example:8080
`)

	cfg, err := Load(path)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if cfg.Proxies[0].Weight != 1 {
		t.Errorf("expected weight to default to 1, got %d", cfg.Proxies[0].Weight)
	}
}

func TestLoadReturnsErrorForMissingFile(t *testing.T) {
	_, err := Load(filepath.Join(t.TempDir(), "nope.yaml"))

	if err == nil {
		t.Fatal("expected an error for a missing config file")
	}
}

func TestLoadReturnsErrorForMalformedYAML(t *testing.T) {
	path := writeTempFile(t, "proxies:\n  - id: alpha\n   url: broken indentation\n")

	_, err := Load(path)

	if err == nil {
		t.Fatal("expected an error for malformed yaml")
	}
	if !strings.Contains(err.Error(), "parse proxy file") {
		t.Errorf("expected a parse error, got %v", err)
	}
}

func TestValidateRejectsInvalidConfigurations(t *testing.T) {
	base := func() Config {
		return Config{
			FailureThreshold:    3,
			HealthCheckInterval: time.Second,
			HealthCheckTimeout:  time.Second,
		}
	}

	tests := []struct {
		name    string
		mutate  func(*Config)
		wantErr string
	}{
		{
			name:    "zero failure threshold",
			mutate:  func(c *Config) { c.FailureThreshold = 0 },
			wantErr: "failure threshold",
		},
		{
			name:    "non-positive health check interval",
			mutate:  func(c *Config) { c.HealthCheckInterval = 0 },
			wantErr: "interval",
		},
		{
			name:    "non-positive health check timeout",
			mutate:  func(c *Config) { c.HealthCheckTimeout = -time.Second },
			wantErr: "timeout",
		},
		{
			name:    "proxy without an id",
			mutate:  func(c *Config) { c.Proxies = []Proxy{{URL: "http://a"}} },
			wantErr: "needs an id",
		},
		{
			name:    "proxy without a url",
			mutate:  func(c *Config) { c.Proxies = []Proxy{{ID: "a"}} },
			wantErr: "has no url",
		},
		{
			name: "duplicate proxy ids",
			mutate: func(c *Config) {
				c.Proxies = []Proxy{
					{ID: "same", URL: "http://a"},
					{ID: "same", URL: "http://b"},
				}
			},
			wantErr: "duplicate proxy id",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			cfg := base()
			tc.mutate(&cfg)

			err := cfg.Validate()

			if err == nil {
				t.Fatalf("expected an error mentioning %q", tc.wantErr)
			}
			if !strings.Contains(err.Error(), tc.wantErr) {
				t.Errorf("expected error mentioning %q, got %v", tc.wantErr, err)
			}
		})
	}
}

func TestValidateAcceptsAWellFormedConfig(t *testing.T) {
	cfg := Config{
		FailureThreshold:    3,
		HealthCheckInterval: 30 * time.Second,
		HealthCheckTimeout:  5 * time.Second,
		Proxies: []Proxy{
			{ID: "a", URL: "http://a", Weight: 1},
			{ID: "b", URL: "http://b", Weight: 2},
		},
	}

	if err := cfg.Validate(); err != nil {
		t.Fatalf("expected a valid config to pass, got %v", err)
	}
}
