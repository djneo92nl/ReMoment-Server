package main

import (
	"context"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/hashicorp/mdns"
)

// mDNS discovery of the server (docs/architecture/discovery-for-clients.md): the server advertises _remoment._tcp with
// TXT path=/api and api_version=1. The node only browses when no server is configured.

const (
	mdnsService      = "_remoment._tcp"
	supportedAPIPath = "/api" // the node's REST calls are written against this base
	supportedAPIVer  = "1"
	browseWindow     = 5 * time.Second
)

// serviceEntry is one answer of a browse: the part of an mDNS service the node cares about.
type serviceEntry struct {
	Name  string
	Host  string
	IPv4  net.IP
	IPv6  net.IP
	Port  int
	Texts []string
}

// browser is the seam: the real one speaks mDNS, the tests answer from a list.
type browser interface {
	Browse(ctx context.Context, service string) ([]serviceEntry, error)
}

type mdnsBrowser struct{}

func (mdnsBrowser) Browse(ctx context.Context, service string) ([]serviceEntry, error) {
	ch := make(chan *mdns.ServiceEntry, 16)
	var out []serviceEntry
	done := make(chan struct{})
	go func() {
		for e := range ch {
			out = append(out, serviceEntry{Name: e.Name, Host: e.Host, IPv4: e.AddrV4, IPv6: e.AddrV6, Port: e.Port, Texts: e.InfoFields})
		}
		close(done)
	}()
	timeout := browseWindow
	if dl, ok := ctx.Deadline(); ok {
		timeout = time.Until(dl)
	}
	err := mdns.Query(&mdns.QueryParam{Service: service, Timeout: timeout, Entries: ch, DisableIPv6: true})
	close(ch)
	<-done
	return out, err
}

func txtValue(texts []string, key string) string {
	for _, t := range texts {
		if k, v, ok := strings.Cut(t, "="); ok && strings.EqualFold(k, key) {
			return v
		}
	}
	return ""
}

// pickServer chooses one server among the answers: the TXT must say path=/api and api_version=1 (a record without TXT
// is skipped, the node cannot know what it talks to); several matches resolve by instance name so the choice is
// stable. It returns the base URL, with the IPv4 address when there is one (the Pi may lack nss-mdns for .local).
func pickServer(entries []serviceEntry) (string, bool) {
	var ok []serviceEntry
	for _, e := range entries {
		if e.Port == 0 || txtValue(e.Texts, "path") != supportedAPIPath || txtValue(e.Texts, "api_version") != supportedAPIVer {
			continue
		}
		if e.IPv4 == nil && e.IPv6 == nil && e.Host == "" {
			continue
		}
		ok = append(ok, e)
	}
	if len(ok) == 0 {
		return "", false
	}
	sort.Slice(ok, func(i, j int) bool { return ok[i].Name < ok[j].Name })
	return serviceURL(ok[0]), true
}

func serviceURL(e serviceEntry) string {
	host := strings.TrimSuffix(e.Host, ".")
	switch {
	case e.IPv4 != nil:
		host = e.IPv4.String()
	case e.IPv6 != nil:
		host = "[" + e.IPv6.String() + "]"
	}
	if e.Port == 80 {
		return "http://" + host
	}
	return "http://" + net.JoinHostPort(strings.Trim(host, "[]"), strconv.Itoa(e.Port))
}

func serverFile(stateDir string) string { return filepath.Join(stateDir, "server") }

func loadSavedServer(stateDir string) string {
	b, err := os.ReadFile(serverFile(stateDir))
	if err != nil {
		return ""
	}
	return strings.TrimRight(strings.TrimSpace(string(b)), "/")
}

func saveServer(stateDir, server string) error {
	if err := os.MkdirAll(stateDir, 0o700); err != nil {
		return err
	}
	return os.WriteFile(serverFile(stateDir), []byte(server+"\n"), 0o600)
}

// resolveServer returns the server address: the configured one when there is one; otherwise a fresh mDNS browse (saved
// to <state_dir>/server when it found something); otherwise the address saved by an earlier run. It retries with
// backoff until it has an address or done closes (then it returns "").
func resolveServer(cfg *Config, b browser, logf func(string, ...any), wait func(time.Duration) bool) string {
	if cfg.Server != "" {
		return cfg.Server
	}
	backoff := 5 * time.Second
	for {
		ctx, cancel := context.WithTimeout(context.Background(), browseWindow)
		entries, err := b.Browse(ctx, mdnsService)
		cancel()
		if err != nil {
			logf("mDNS browse failed: %v", err)
		}
		if s, ok := pickServer(entries); ok {
			logf("found the server by mDNS: %s", s)
			if err := saveServer(cfg.StateDir, s); err != nil {
				logf("could not save the server address: %v", err)
			}
			return s
		}
		if s := loadSavedServer(cfg.StateDir); s != "" {
			logf("no server answered mDNS; using the saved address %s", s)
			return s
		}
		logf("no server configured and none found by mDNS; trying again in %s (set \"server\" in the config to skip discovery)", backoff)
		if !wait(backoff) {
			return ""
		}
		if backoff < 60*time.Second {
			backoff *= 2
		}
	}
}
