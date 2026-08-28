package httpapi

import (
	"crypto/subtle"
	"log/slog"
	"net/http"
)

// HeaderAPIKey is the header Laravel sends on every authenticated call.
const HeaderAPIKey = "X-Proxy-Key"

// RequireAPIKey rejects requests that do not carry the shared secret.
//
// Why it exists: the proxy list contains credentials, and /v1/proxy/next hands
// out working proxies. Anything on the network that can reach this service
// could otherwise use it as a free, authenticated relay.
//
// When apiKey is empty the middleware allows every request. That is a
// deliberate local-development convenience — main.go logs a loud warning at
// startup so an unsecured service can never be deployed unnoticed.
func RequireAPIKey(apiKey string, logger *slog.Logger, next http.Handler) http.Handler {
	if apiKey == "" {
		return next
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		provided := r.Header.Get(HeaderAPIKey)

		// subtle.ConstantTimeCompare avoids leaking how much of the key was
		// correct through response timing. Comparing lengths first is safe:
		// the length of the expected key is not the secret.
		valid := len(provided) == len(apiKey) &&
			subtle.ConstantTimeCompare([]byte(provided), []byte(apiKey)) == 1

		if !valid {
			logger.Warn("rejected request with invalid api key",
				"path", r.URL.Path, "remote", r.RemoteAddr)
			writeError(w, http.StatusUnauthorized, "invalid or missing api key")
			return
		}

		next.ServeHTTP(w, r)
	})
}
