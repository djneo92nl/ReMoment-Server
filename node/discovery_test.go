package main

import (
	"context"
	"errors"
	"net"
	"os"
	"testing"
	"time"
)

type fakeBrowser struct {
	results [][]serviceEntry // one answer per call, the last repeats
	err     error
	calls   int
}

func (f *fakeBrowser) Browse(ctx context.Context, service string) ([]serviceEntry, error) {
	if service != "_remoment._tcp" {
		return nil, errors.New("wrong service " + service)
	}
	i := f.calls
	if i >= len(f.results) {
		i = len(f.results) - 1
	}
	f.calls++
	return f.results[i], f.err
}

func good(name string, ip string, port int) serviceEntry {
	return serviceEntry{Name: name, Host: "box.local.", IPv4: net.ParseIP(ip), Port: port, Texts: []string{"path=/api", "api_version=1"}}
}

func TestPickServer(t *testing.T) {
	if _, ok := pickServer(nil); ok {
		t.Fatal("nothing found must not pick")
	}
	bad := []serviceEntry{
		{Name: "a", IPv4: net.ParseIP("10.0.0.1"), Port: 80, Texts: []string{"path=/other", "api_version=1"}},
		{Name: "b", IPv4: net.ParseIP("10.0.0.2"), Port: 80, Texts: []string{"path=/api", "api_version=2"}},
		{Name: "c", IPv4: net.ParseIP("10.0.0.3"), Port: 80},
		{Name: "d", IPv4: net.ParseIP("10.0.0.4"), Texts: []string{"path=/api", "api_version=1"}},
	}
	if _, ok := pickServer(bad); ok {
		t.Fatal("entries with the wrong path, version, no TXT or no port must be skipped")
	}
	got, ok := pickServer(append(bad, good("ReMoment on z", "10.0.0.9", 80), good("ReMoment on a", "10.0.0.8", 8080)))
	if !ok || got != "http://10.0.0.8:8080" {
		t.Fatalf("got %q %v, want the first by name with its port", got, ok)
	}
	if u, _ := pickServer([]serviceEntry{good("x", "10.0.0.5", 80)}); u != "http://10.0.0.5" {
		t.Fatalf("port 80 has no suffix: %q", u)
	}
	h := serviceEntry{Name: "h", Host: "remoment.local.", Port: 80, Texts: []string{"PATH=/api", "api_version=1"}}
	if u, _ := pickServer([]serviceEntry{h}); u != "http://remoment.local" {
		t.Fatalf("host only: %q", u)
	}
}

func noWait(time.Duration) bool { return true }

func TestResolveServerConfiguredWins(t *testing.T) {
	fb := &fakeBrowser{results: [][]serviceEntry{{good("a", "10.0.0.1", 80)}}}
	cfg := &Config{Server: "http://configured", StateDir: t.TempDir()}
	if got := resolveServer(cfg, fb, t.Logf, noWait); got != "http://configured" || fb.calls != 0 {
		t.Fatalf("got %q after %d browses", got, fb.calls)
	}
}

func TestResolveServerBrowsesAndPersists(t *testing.T) {
	dir := t.TempDir()
	fb := &fakeBrowser{results: [][]serviceEntry{{good("a", "10.0.0.1", 80)}}}
	cfg := &Config{StateDir: dir}
	if got := resolveServer(cfg, fb, t.Logf, noWait); got != "http://10.0.0.1" {
		t.Fatalf("got %q", got)
	}
	if loadSavedServer(dir) != "http://10.0.0.1" {
		t.Fatal("not persisted")
	}
	if st, _ := os.Stat(serverFile(dir)); st.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v", st.Mode())
	}
}

func TestResolveServerFallsBackToSaved(t *testing.T) {
	dir := t.TempDir()
	if err := saveServer(dir, "http://10.9.9.9/"); err != nil {
		t.Fatal(err)
	}
	fb := &fakeBrowser{results: [][]serviceEntry{nil}, err: errors.New("no multicast")}
	if got := resolveServer(&Config{StateDir: dir}, fb, t.Logf, noWait); got != "http://10.9.9.9" {
		t.Fatalf("got %q", got)
	}
	// a fresh answer beats the saved one, and replaces it
	fb = &fakeBrowser{results: [][]serviceEntry{{good("a", "10.0.0.1", 80)}}}
	if got := resolveServer(&Config{StateDir: dir}, fb, t.Logf, noWait); got != "http://10.0.0.1" || loadSavedServer(dir) != "http://10.0.0.1" {
		t.Fatalf("got %q", got)
	}
}

func TestResolveServerRetriesAndStops(t *testing.T) {
	fb := &fakeBrowser{results: [][]serviceEntry{nil, nil, {good("a", "10.0.0.1", 80)}}}
	waits := 0
	got := resolveServer(&Config{StateDir: t.TempDir()}, fb, t.Logf, func(time.Duration) bool { waits++; return true })
	if got != "http://10.0.0.1" || waits != 2 {
		t.Fatalf("got %q after %d waits", got, waits)
	}
	fb = &fakeBrowser{results: [][]serviceEntry{nil}}
	if got := resolveServer(&Config{StateDir: t.TempDir()}, fb, t.Logf, func(time.Duration) bool { return false }); got != "" {
		t.Fatalf("a stop must give no server, got %q", got)
	}
}
