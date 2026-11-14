package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

// fakeServer plays the parts of the ReMoment API a node uses.
type fakeServer struct {
	mu       sync.Mutex
	approved bool
	jobs     []map[string]any
	results  map[string]map[string]any
	accepted map[string]bool
	beats    int
	revoked  bool
	regBody  map[string]any
}

func (f *fakeServer) handler() http.Handler {
	mux := http.NewServeMux()
	write := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	mux.HandleFunc("/api/clients/register", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		_ = json.NewDecoder(r.Body).Decode(&f.regBody)
		write(w, 201, map[string]any{"registration_token": "reg1", "pairing_code": "K7M3QX", "status": "pending"})
	})
	mux.HandleFunc("/api/clients/status/reg1", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		if !f.approved {
			write(w, 200, map[string]any{"status": "pending"})
			return
		}
		write(w, 200, map[string]any{"status": "approved", "api_token": "tok1", "client_id": 7, "kind": "node", "roles": []string{}})
	})
	mux.HandleFunc("/api/clients/tok1/heartbeat", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		if f.revoked {
			write(w, 404, map[string]any{"message": "gone"})
			return
		}
		f.beats++
		write(w, 200, map[string]any{"status": "ok", "roles": []string{}})
	})
	auth := func(w http.ResponseWriter, r *http.Request) bool {
		if r.Header.Get("Authorization") != "Bearer tok1" {
			write(w, 401, map[string]any{"error": "unauthenticated"})
			return false
		}
		return true
	}
	mux.HandleFunc("/api/nodes/job", func(w http.ResponseWriter, r *http.Request) {
		if !auth(w, r) {
			return
		}
		f.mu.Lock()
		defer f.mu.Unlock()
		for _, j := range f.jobs {
			if _, done := f.results[j["id"].(string)]; !done {
				write(w, 200, map[string]any{"job": j})
				return
			}
		}
		write(w, 200, map[string]any{"job": nil})
	})
	mux.HandleFunc("/api/nodes/jobs/", func(w http.ResponseWriter, r *http.Request) {
		if !auth(w, r) {
			return
		}
		f.mu.Lock()
		defer f.mu.Unlock()
		id := r.URL.Path[len("/api/nodes/jobs/"):]
		switch {
		case len(id) > 7 && id[len(id)-7:] == "/accept":
			f.accepted[id[:len(id)-7]] = true
			write(w, 200, map[string]any{})
		case len(id) > 9 && id[len(id)-9:] == "/progress":
			write(w, 200, map[string]any{"cancel_requested": false})
		case len(id) > 7 && id[len(id)-7:] == "/result":
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			f.results[id[:len(id)-7]] = body
			write(w, 200, map[string]any{})
		}
	})
	return mux
}

func newTestNode(t *testing.T, f *fakeServer) (*Node, string) {
	srv := httptest.NewServer(f.handler())
	t.Cleanup(srv.Close)
	dir := t.TempDir()
	cfg := &Config{Server: srv.URL, StateDir: dir, HardwareID: "hw-1", Roles: []string{}, NoMQTT: true}
	return &Node{cfg: cfg, api: newAPI(srv.URL), started: time.Now(), nudge: make(chan struct{}, 1)}, dir
}

func TestRegisterWaitsForApprovalThenStoresTokenPrivately(t *testing.T) {
	f := &fakeServer{results: map[string]map[string]any{}, accepted: map[string]bool{}}
	n, dir := newTestNode(t, f)
	done := make(chan struct{})
	go func() { time.Sleep(300 * time.Millisecond); f.mu.Lock(); f.approved = true; f.mu.Unlock() }()

	// the real wait is 15 s; poll the status directly the way registerAndWait does after approval
	reg, err := n.api.register(n.cfg, map[string]any{})
	if err != nil || reg.PairingCode != "K7M3QX" {
		t.Fatalf("register: %v %+v", err, reg)
	}
	if f.regBody["kind"] != "node" || f.regBody["hardware_id"] != "hw-1" {
		t.Fatalf("registration body: %v", f.regBody)
	}
	time.Sleep(400 * time.Millisecond)
	s, err := n.api.status(reg.RegistrationToken)
	if err != nil || s.APIToken != "tok1" || s.ClientID != 7 {
		t.Fatalf("status: %v %+v", err, s)
	}
	if err := n.saveToken(s.APIToken, s.ClientID); err != nil {
		t.Fatal(err)
	}
	st, _ := os.Stat(filepath.Join(dir, "token"))
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("token file mode %v", st.Mode().Perm())
	}
	tok, id := n.loadToken()
	if tok != "tok1" || id != "7" {
		t.Fatalf("loaded %q %q", tok, id)
	}
	_ = done
}

func TestPingJobRunsAndUnknownTypeFails(t *testing.T) {
	f := &fakeServer{results: map[string]map[string]any{}, accepted: map[string]bool{}}
	f.jobs = []map[string]any{
		{"id": "J1", "type": "ping", "params": map[string]any{"message": "hello"}, "state": "queued"},
		{"id": "J2", "type": "exec", "params": map[string]any{"cmd": "rm -rf /"}, "state": "queued"},
	}
	n, _ := newTestNode(t, f)
	n.api.token = "tok1"

	if err := n.drain(); err != nil {
		t.Fatal(err)
	}
	if !f.accepted["J1"] || !f.accepted["J2"] {
		t.Fatalf("accepted: %v", f.accepted)
	}
	r1 := f.results["J1"]
	if r1["ok"] != true || r1["result"].(map[string]any)["pong"] != "hello" {
		t.Fatalf("ping result: %v", r1)
	}
	r2 := f.results["J2"]
	if r2["ok"] != false || r2["error"] != "unknown_type: exec" {
		t.Fatalf("exec must be refused: %v", r2)
	}
}

func TestRevokedTokenIsReportedAsUnauthorized(t *testing.T) {
	f := &fakeServer{results: map[string]map[string]any{}, accepted: map[string]bool{}, revoked: true}
	n, _ := newTestNode(t, f)
	n.api.token = "tok1"
	if err := n.beat(); err != errUnauthorized {
		t.Fatalf("beat on a revoked token: %v", err)
	}
	n.api.token = "wrong"
	if _, err := n.api.nextJob(); err != errUnauthorized {
		t.Fatalf("jobs with a wrong token: %v", err)
	}
}

func TestHeartbeatCarriesStatus(t *testing.T) {
	f := &fakeServer{results: map[string]map[string]any{}, accepted: map[string]bool{}}
	n, _ := newTestNode(t, f)
	n.api.token = "tok1"
	if err := n.beat(); err != nil || f.beats != 1 {
		t.Fatalf("beat: %v beats=%d", err, f.beats)
	}
}

func TestConfigNeedsServerAndHardwareID(t *testing.T) {
	t.Setenv("REMOMENT_NODE_SERVER", "")
	if _, err := loadConfig(filepath.Join(t.TempDir(), "none.json")); err == nil {
		t.Fatal("a config without server must fail")
	}
	t.Setenv("REMOMENT_NODE_SERVER", "http://x/")
	t.Setenv("REMOMENT_NODE_HARDWARE_ID", "abc")
	c, err := loadConfig(filepath.Join(t.TempDir(), "none.json"))
	if err != nil || c.Server != "http://x" || c.HardwareID != "abc" {
		t.Fatalf("config: %v %+v", err, c)
	}
}
