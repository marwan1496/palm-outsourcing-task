// Command proxyd runs the proxy-manager microservice.
//
// It owns one job: decide which proxy the Laravel scraper should use next, and
// remember how each proxy has been performing. Keeping this out of Laravel
// means proxy state is shared across every worker and survives a PHP request,
// which a per-request PHP process cannot do on its own.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/marwanshokry/palm-task/proxy-manager/internal/config"
	"github.com/marwanshokry/palm-task/proxy-manager/internal/httpapi"
	"github.com/marwanshokry/palm-task/proxy-manager/internal/pool"
)

// Timeouts that protect the server from slow or stuck clients.
const (
	readHeaderTimeout = 5 * time.Second
	writeTimeout      = 15 * time.Second
	idleTimeout       = 60 * time.Second
	shutdownGrace     = 10 * time.Second
)

func main() {
	if err := run(); err != nil {
		slog.Error("fatal", "error", err)
		os.Exit(1)
	}
}

// run holds the real logic so that every exit path can return an error instead
// of calling os.Exit, which would skip deferred cleanup.
func run() error {
	logger := newLogger()
	slog.SetDefault(logger)

	configPath := envOr("PROXY_CONFIG", "proxies.yaml")
	cfg, err := config.Load(configPath)
	if err != nil {
		return err
	}

	if cfg.APIKey == "" {
		logger.Warn("PROXY_API_KEY is not set - the API is unauthenticated. " +
			"Set it before running anywhere other than local development.")
	}

	proxies := make([]pool.Proxy, 0, len(cfg.Proxies))
	for _, p := range cfg.Proxies {
		proxies = append(proxies, pool.Proxy{ID: p.ID, URL: p.URL, Weight: p.Weight})
	}

	proxyPool := pool.New(proxies, pool.Options{
		FailureThreshold: cfg.FailureThreshold,
		Cooldown:         cfg.Cooldown,
	})

	logger.Info("proxy pool loaded",
		"count", proxyPool.Size(), "config", configPath)

	// ctx is cancelled on Ctrl+C or SIGTERM, which stops the health checker and
	// begins a graceful shutdown of the HTTP server.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	// The health checker only earns its keep when there are proxies to probe.
	if proxyPool.Size() > 0 {
		checker := pool.NewChecker(proxyPool, pool.CheckerOptions{
			ProbeURL: cfg.ProbeURL,
			Interval: cfg.HealthCheckInterval,
			Timeout:  cfg.HealthCheckTimeout,
			Logger:   logger,
		})
		go checker.Run(ctx)
	}

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           httpapi.NewRouter(httpapi.NewServer(proxyPool, logger), cfg.APIKey, logger),
		ReadHeaderTimeout: readHeaderTimeout,
		WriteTimeout:      writeTimeout,
		IdleTimeout:       idleTimeout,
	}

	// Serve in the background so main can wait on the shutdown signal.
	serveErr := make(chan error, 1)
	go func() {
		logger.Info("proxy-manager listening", "addr", cfg.ListenAddr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			serveErr <- err
			return
		}
		serveErr <- nil
	}()

	select {
	case err := <-serveErr:
		return err
	case <-ctx.Done():
		logger.Info("shutdown signal received, draining connections")
	}

	// Give in-flight requests a moment to finish rather than cutting them off.
	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownGrace)
	defer cancel()

	if err := server.Shutdown(shutdownCtx); err != nil {
		return err
	}

	logger.Info("shutdown complete")
	return nil
}

// newLogger builds the structured logger. Text output is far easier to read
// during a live demo; set PROXY_LOG_FORMAT=json for machine-readable logs.
func newLogger() *slog.Logger {
	level := slog.LevelInfo
	if os.Getenv("PROXY_DEBUG") != "" {
		level = slog.LevelDebug
	}

	opts := &slog.HandlerOptions{Level: level}

	if os.Getenv("PROXY_LOG_FORMAT") == "json" {
		return slog.New(slog.NewJSONHandler(os.Stdout, opts))
	}
	return slog.New(slog.NewTextHandler(os.Stdout, opts))
}

// envOr reads an environment variable with a fallback.
func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
