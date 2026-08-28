package httpapi

import (
	"log/slog"
	"net/http"
	"time"
)

// NewRouter wires every endpoint and returns the handler main.go serves.
//
// Routing uses the standard library's method-aware patterns (Go 1.22+), so the
// service needs no third-party router. "GET /v1/proxy/next" matches the method
// and path in one line, and "{id}" is read back with r.PathValue.
//
// Health endpoints are deliberately left unauthenticated: an orchestrator has
// to be able to probe them without holding the shared secret, and they reveal
// nothing sensitive. Every endpoint that exposes proxy URLs is behind the key.
func NewRouter(s *Server, apiKey string, logger *slog.Logger) http.Handler {
	if logger == nil {
		logger = slog.Default()
	}

	mux := http.NewServeMux()

	// Public: liveness and readiness probes.
	mux.HandleFunc("GET /healthz", s.handleHealthz)
	mux.HandleFunc("GET /readyz", s.handleReadyz)

	// Protected: everything that can expose or mutate proxy state.
	protected := http.NewServeMux()
	protected.HandleFunc("GET /v1/proxy/next", s.handleNextProxy)
	protected.HandleFunc("POST /v1/proxy/{id}/report", s.handleReport)
	protected.HandleFunc("GET /v1/proxies", s.handleListProxies)

	mux.Handle("/v1/", RequireAPIKey(apiKey, logger, protected))

	return RequestLogger(logger, mux)
}

// RequestLogger records one structured line per request, including how long it
// took. Having latency on every line is what makes a slow proxy obvious in the
// logs without attaching a profiler.
func RequestLogger(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(recorder, r)

		logger.Info("request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", recorder.status,
			"duration_ms", time.Since(start).Milliseconds(),
		)
	})
}

// statusRecorder remembers the status code so the logger can report it.
// net/http gives no way to read back what a handler wrote, so we intercept it.
type statusRecorder struct {
	http.ResponseWriter
	status int
}

// WriteHeader captures the status code on its way to the client.
func (r *statusRecorder) WriteHeader(status int) {
	r.status = status
	r.ResponseWriter.WriteHeader(status)
}
