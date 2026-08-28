// Package httpapi exposes the proxy pool over HTTP so the Laravel scraper can
// ask for a proxy and report back how it performed.
//
// The API is deliberately tiny — four endpoints, all JSON — because it sits on
// the hot path of every scrape and has to be trivially easy to reason about.
package httpapi

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"time"

	"github.com/marwanshokry/palm-task/proxy-manager/internal/pool"
)

// maxBodyBytes caps report payloads. They are a handful of bytes in practice,
// so anything larger is either a bug or an attempt to exhaust memory.
const maxBodyBytes = 4 << 10 // 4 KiB

// Server holds the dependencies every handler needs.
type Server struct {
	pool   *pool.Pool
	logger *slog.Logger
}

// NewServer builds the HTTP layer around a pool.
func NewServer(p *pool.Pool, logger *slog.Logger) *Server {
	if logger == nil {
		logger = slog.Default()
	}
	return &Server{pool: p, logger: logger}
}

// nextProxyResponse is returned by GET /v1/proxy/next.
type nextProxyResponse struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	Weight int    `json:"weight"`
}

// reportRequest is the body of POST /v1/proxy/{id}/report.
type reportRequest struct {
	Success   bool  `json:"success"`
	LatencyMS int64 `json:"latency_ms"`
}

// handleHealthz answers liveness probes. It reports only that the process is
// running, never whether proxies are healthy — a pool with every proxy benched
// is still a correctly functioning service.
func (s *Server) handleHealthz(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// handleReadyz reports whether the service can actually hand out a proxy.
//
// It returns 503 when nothing is available so an orchestrator stops routing
// traffic here, while /healthz stays 200 so the process is not killed.
func (s *Server) handleReadyz(w http.ResponseWriter, _ *http.Request) {
	healthy := s.pool.HealthyCount()

	status := http.StatusOK
	if healthy == 0 {
		status = http.StatusServiceUnavailable
	}

	writeJSON(w, status, map[string]any{
		"ready":   healthy > 0,
		"healthy": healthy,
		"total":   s.pool.Size(),
	})
}

// handleNextProxy hands out the proxy that should serve the next scrape.
//
// A 503 here is routine, not exceptional: Laravel reads it as "no proxy
// available, scrape directly" and carries on.
func (s *Server) handleNextProxy(w http.ResponseWriter, _ *http.Request) {
	proxy, err := s.pool.Next()
	if errors.Is(err, pool.ErrNoProxyAvailable) {
		writeError(w, http.StatusServiceUnavailable, "no healthy proxy available")
		return
	}
	if err != nil {
		s.logger.Error("failed to select a proxy", "error", err)
		writeError(w, http.StatusInternalServerError, "could not select a proxy")
		return
	}

	writeJSON(w, http.StatusOK, nextProxyResponse{
		ID:     proxy.ID,
		URL:    proxy.URL,
		Weight: proxy.Weight,
	})
}

// handleReport records how a scrape through a given proxy went. This feedback
// is what lets the pool bench failing proxies before a user notices them.
func (s *Server) handleReport(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		writeError(w, http.StatusBadRequest, "proxy id is required")
		return
	}

	var body reportRequest
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxBodyBytes))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid json body")
		return
	}

	if body.LatencyMS < 0 {
		writeError(w, http.StatusBadRequest, "latency_ms cannot be negative")
		return
	}

	err := s.pool.Report(id, body.Success, time.Duration(body.LatencyMS)*time.Millisecond)
	if errors.Is(err, pool.ErrUnknownProxy) {
		writeError(w, http.StatusNotFound, "unknown proxy id")
		return
	}
	if err != nil {
		s.logger.Error("failed to record report", "proxy", id, "error", err)
		writeError(w, http.StatusInternalServerError, "could not record report")
		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "recorded"})
}

// handleListProxies exposes pool statistics, for dashboards and for showing
// rotation actually working during a demo.
func (s *Server) handleListProxies(w http.ResponseWriter, _ *http.Request) {
	stats := s.pool.Stats()

	writeJSON(w, http.StatusOK, map[string]any{
		"proxies": stats,
		"healthy": s.pool.HealthyCount(),
		"total":   len(stats),
	})
}

// writeJSON serialises v as the response body with the given status code.
func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)

	// The header is already sent by this point, so a failed encode can only be
	// logged, not turned into an error response.
	if err := json.NewEncoder(w).Encode(v); err != nil {
		slog.Default().Error("failed to encode response", "error", err)
	}
}

// writeError returns a JSON error body in one consistent shape, so Laravel only
// ever has to parse one error format.
func writeError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]string{"error": message})
}
