// Command fakeproxy is a tiny local HTTP proxy for DEMOS AND DEVELOPMENT.
//
// WHY THIS EXISTS
//
// Real rotating proxies cost money, which makes the proxy-manager awkward to
// demonstrate: with an empty pool it always answers "no proxy available", and
// you have to take the rotation logic on trust.
//
// This lets you run several proxies locally on different ports. They are all
// the same machine, so they do NOT hide your IP and are useless for real
// scraping - but from the proxy-manager's point of view they are indistinguish-
// able from real ones. That is enough to watch rotation, health checking,
// benching and recovery actually happen.
//
// Each instance logs every request it handles, so you can SEE traffic being
// spread across the pool rather than being told it is.
//
//	go run ./cmd/fakeproxy -port 3128 -name proxy-a
//	go run ./cmd/fakeproxy -port 3129 -name proxy-b
//	go run ./cmd/fakeproxy -port 3130 -name proxy-c -fail   # simulates a broken one
//
// NOT FOR PRODUCTION. It binds to localhost only, has no authentication, and
// no limits.
package main

import (
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"sync/atomic"
	"time"
)

func main() {
	port := flag.Int("port", 3128, "port to listen on")
	name := flag.String("name", "fakeproxy", "label shown in this proxy's logs")
	fail := flag.Bool("fail", false, "reject every request, to simulate a broken proxy")
	flag.Parse()

	p := &proxy{name: *name, alwaysFail: *fail}

	addr := fmt.Sprintf("127.0.0.1:%d", *port)

	status := "healthy"
	if *fail {
		status = "FAILING (every request will be rejected)"
	}

	// Timestamps only, no date - keeps the demo output narrow and readable.
	log.SetFlags(log.Ltime)
	log.Printf("[%s] listening on %s - %s", p.name, addr, status)
	log.Printf("[%s] add to proxies.yaml as:  url: http://%s", p.name, addr)

	// Bind to 127.0.0.1 explicitly rather than :port, so this never becomes an
	// open relay reachable from the network.
	server := &http.Server{
		Addr:              addr,
		Handler:           p,
		ReadHeaderTimeout: 10 * time.Second,
	}

	if err := server.ListenAndServe(); err != nil {
		log.Printf("[%s] stopped: %v", p.name, err)
		os.Exit(1)
	}
}

// proxy forwards requests and counts how many it has handled.
type proxy struct {
	name       string
	alwaysFail bool
	handled    atomic.Int64
}

// ServeHTTP handles both proxy styles a client may use:
//
//	CONNECT   for https:// - open a raw tunnel and copy bytes both ways
//	GET/POST  for http://  - fetch the absolute URL and copy the response back
func (p *proxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	n := p.handled.Add(1)

	if p.alwaysFail {
		log.Printf("[%s] #%d REJECTED %s %s", p.name, n, r.Method, r.Host)
		http.Error(w, "this proxy is simulating a failure", http.StatusBadGateway)
		return
	}

	log.Printf("[%s] #%d %s %s", p.name, n, r.Method, r.Host)

	if r.Method == http.MethodConnect {
		p.tunnel(w, r)
		return
	}

	p.forward(w, r)
}

// tunnel handles CONNECT, which is how a client asks a proxy to relay HTTPS.
//
// The proxy cannot read the traffic - it is encrypted end to end between the
// client and the target - so it just opens a TCP connection and shovels bytes
// in both directions.
func (p *proxy) tunnel(w http.ResponseWriter, r *http.Request) {
	upstream, err := net.DialTimeout("tcp", r.Host, 15*time.Second)
	if err != nil {
		log.Printf("[%s] could not reach %s: %v", p.name, r.Host, err)
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer upstream.Close()

	// Take over the raw connection from net/http so we can write bytes directly.
	hijacker, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "this server cannot hijack connections", http.StatusInternalServerError)
		return
	}

	client, _, err := hijacker.Hijack()
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer client.Close()

	// Tell the client the tunnel is open; everything after this is raw TLS.
	if _, err := client.Write([]byte("HTTP/1.1 200 Connection Established\r\n\r\n")); err != nil {
		return
	}

	// Copy in both directions until either side closes.
	done := make(chan struct{}, 2)
	go func() { io.Copy(upstream, client); done <- struct{}{} }()
	go func() { io.Copy(client, upstream); done <- struct{}{} }()
	<-done
}

// forward handles plain http:// requests, where the client sends the full URL.
func (p *proxy) forward(w http.ResponseWriter, r *http.Request) {
	if !r.URL.IsAbs() {
		http.Error(w, "expected an absolute URL - is this client configured to use a proxy?", http.StatusBadRequest)
		return
	}

	outbound, err := http.NewRequestWithContext(r.Context(), r.Method, r.URL.String(), r.Body)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	outbound.Header = r.Header.Clone()

	resp, err := http.DefaultTransport.RoundTrip(outbound)
	if err != nil {
		log.Printf("[%s] upstream error for %s: %v", p.name, r.URL, err)
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	for key, values := range resp.Header {
		for _, v := range values {
			w.Header().Add(key, v)
		}
	}

	w.WriteHeader(resp.StatusCode)
	io.Copy(w, resp.Body)
}
