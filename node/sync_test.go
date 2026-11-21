package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"remoment/node/internal/syncer"
)

const (
	testTarget = "01m7zr811pfvdf6ssbmm7qyfcy"
	testServer = "01m7zr811pfvdf6ssbmm7qyfc0"
)

// syncFake plays the sync routes of the ReMoment server for the node glue: the plan, the report, progress and the files.
type syncFake struct {
	mu       sync.Mutex
	files    map[string]string // path under /api/sync/files/ -> content
	plans    []map[string]any
	reports  []map[string]any
	progress int
	badAuth  int
	stall    bool
	planBody func(have []syncer.Entry) map[string]any
}

func (f *syncFake) handler() http.Handler {
	mux := http.NewServeMux()
	write := func(w http.ResponseWriter, v any) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(v)
	}
	bearer := func(w http.ResponseWriter, r *http.Request) bool {
		if r.Header.Get("Authorization") != "Bearer tok1" {
			f.mu.Lock()
			f.badAuth++
			f.mu.Unlock()
			w.WriteHeader(401)
			return false
		}
		return true
	}
	mux.HandleFunc("/api/nodes/sync/plan", func(w http.ResponseWriter, r *http.Request) {
		if !bearer(w, r) {
			return
		}
		var body struct {
			TargetID string          `json:"target_id"`
			Have     []syncer.Entry  `json:"have"`
			Free     int64           `json:"free_bytes"`
			FS       string          `json:"fs"`
			Rules    int             `json:"rules_version"`
			Raw      json.RawMessage `json:"-"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		f.plans = append(f.plans, map[string]any{"target_id": body.TargetID, "have": len(body.Have), "free": body.Free})
		f.mu.Unlock()
		if f.planBody != nil {
			write(w, f.planBody(body.Have))
			return
		}
		have := map[string]bool{}
		for _, h := range body.Have {
			have[h.Key] = true
		}
		add := []map[string]any{}
		for p, content := range f.files {
			key := "track:" + strings.TrimSuffix(p, ".mp3")
			if have[key] {
				continue
			}
			sum := sha256.Sum256([]byte(content))
			add = append(add, map[string]any{"key": key, "path": "Music/" + p, "fingerprint": "fp-" + p, "size": len(content), "sha256": hex.EncodeToString(sum[:]), "state": "ready", "url": "/api/sync/files/" + p + "?expires=1&signature=x"})
		}
		write(w, map[string]any{"rules_version": 4, "add": add, "replace": []any{}, "move": []any{}, "keep": 0, "remove": []any{}, "confirm_required": false, "remove_first": false, "warnings": []any{}})
	})
	mux.HandleFunc("/api/nodes/sync/report", func(w http.ResponseWriter, r *http.Request) {
		if !bearer(w, r) {
			return
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		f.reports = append(f.reports, body)
		f.mu.Unlock()
		write(w, map[string]any{"status": "ok"})
	})
	mux.HandleFunc("/api/nodes/jobs/", func(w http.ResponseWriter, r *http.Request) {
		if !bearer(w, r) {
			return
		}
		f.mu.Lock()
		f.progress++
		f.mu.Unlock()
		write(w, map[string]any{"cancel_requested": false})
	})
	mux.HandleFunc("/api/sync/files/", func(w http.ResponseWriter, r *http.Request) {
		name := strings.TrimPrefix(r.URL.Path, "/api/sync/files/")
		content, ok := f.files[name]
		if !ok {
			w.WriteHeader(404)
			return
		}
		if f.stall {
			w.WriteHeader(200)
			w.(http.Flusher).Flush()
			time.Sleep(2 * time.Second)
			return
		}
		offset := 0
		if rg := r.Header.Get("Range"); strings.HasPrefix(rg, "bytes=") {
			offset, _ = strconv.Atoi(strings.TrimSuffix(strings.TrimPrefix(rg, "bytes="), "-"))
			w.Header().Set("Content-Range", fmt.Sprintf("bytes %d-%d/%d", offset, len(content)-1, len(content)))
			w.WriteHeader(206)
		}
		_, _ = io.WriteString(w, content[offset:])
	})
	return mux
}

func syncNode(t *testing.T, f *syncFake) (*Node, string) {
	srv := httptest.NewServer(f.handler())
	t.Cleanup(srv.Close)
	root := t.TempDir()
	cfg := &Config{Server: srv.URL, StateDir: t.TempDir(), HardwareID: "hw", Roles: []string{"sync-target"}, NoMQTT: true, Sync: SyncConfig{VolumePath: root}}
	n := &Node{cfg: cfg, api: newAPI(srv.URL), started: time.Now(), nudge: make(chan struct{}, 1)}
	n.api.token = "tok1"
	n.job = &Job{ID: "job1"}

	return n, root
}

func params(extra string) json.RawMessage {
	return json.RawMessage(fmt.Sprintf(`{"target_id":%q,"server_id":%q%s}`, testTarget, testServer, extra))
}

func TestSyncJobsAreRegisteredAndParamsAreChecked(t *testing.T) {
	for _, typ := range []string{"sync.scan", "sync.claim", "sync.apply"} {
		if _, ok := handlers[typ]; !ok {
			t.Fatalf("%s is not in the closed handler table", typ)
		}
	}
	n, _ := syncNode(t, &syncFake{})
	for _, bad := range []string{`{}`, `{"target_id":"x","server_id":"y"}`, `{"target_id":"../../etc/passwd0000000000","server_id":"` + testServer + `"}`, `not json`} {
		if _, err := syncScan(n, json.RawMessage(bad)); err == nil || !strings.HasPrefix(err.Error(), "bad params") {
			t.Fatalf("%s: %v", bad, err)
		}
	}
	n.cfg.Sync.VolumePath = ""
	if _, err := syncApply(n, params("")); err == nil || err.Error() != "no_volume_configured" {
		t.Fatalf("no volume: %v", err)
	}
}

func TestTheVolumeComesFromTheNodesConfigNeverFromTheJob(t *testing.T) {
	n, root := syncNode(t, &syncFake{})
	elsewhere := t.TempDir()
	// A path in the job's params is ignored: the unknown field is not read and the config volume is used.
	if _, err := syncClaim(n, params(`,"path":"`+elsewhere+`","volume_path":"`+elsewhere+`"`)); err != nil {
		t.Fatal(err)
	}
	if m, _ := syncer.ReadMarker(root); m == nil {
		t.Fatal("the marker belongs on the configured volume")
	}
	if m, _ := syncer.ReadMarker(elsewhere); m != nil {
		t.Fatal("a path from the job must never be used")
	}
}

func TestScanClaimApplyEndToEndAgainstTheServerRoutes(t *testing.T) {
	f := &syncFake{files: map[string]string{"1.mp3": "first file", "2.mp3": "second file"}}
	n, root := syncNode(t, f)

	res, err := syncScan(n, params(""))
	if err != nil || res["mounted"] != true || res["marker"] != nil {
		t.Fatalf("scan: %v %v", res, err)
	}
	if _, err := syncApply(n, params("")); err == nil || !strings.HasPrefix(err.Error(), "no_marker") {
		t.Fatalf("an unclaimed drive is never written: %v", err)
	}
	if len(f.plans) != 0 {
		t.Fatal("a refused apply must not ask for a plan")
	}
	if _, err := syncClaim(n, params("")); err != nil {
		t.Fatal(err)
	}
	if res, _ = syncScan(n, params("")); res["marker"].(map[string]any)["target_id"] != testTarget {
		t.Fatalf("scan after claim: %v", res)
	}

	res, err = syncApply(n, params(""))
	if err != nil {
		t.Fatal(err)
	}
	if res["state"] != "done" || res["applied"] != 2 || res["failed"] != 0 {
		t.Fatalf("result: %v", res)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "Music", "1.mp3")); string(b) != "first file" {
		t.Fatalf("content %q", b)
	}
	if f.badAuth != 0 {
		t.Fatalf("every node route must carry the node's Bearer token (%d refused)", f.badAuth)
	}
	if len(f.plans) < 1 || f.plans[0]["target_id"] != testTarget || f.plans[0]["have"] != 0 {
		t.Fatalf("plans: %v", f.plans)
	}
	rep := f.reports[len(f.reports)-1]
	if rep["target_id"] != testTarget || rep["owned_count"] != float64(2) || len(rep["applied"].([]any)) != 2 || rep["rules_version"] != float64(4) {
		t.Fatalf("report: %v", rep)
	}
	if n.syncing.Load() {
		t.Fatal("the busy flag must clear")
	}

	// The second run sends the manifest as `have` and downloads nothing more.
	res, _ = syncApply(n, params(""))
	if res["applied"] != 0 || f.plans[len(f.plans)-1]["have"] != 2 {
		t.Fatalf("second run: %v plans %v", res, f.plans)
	}
}

func TestADownloadURLThatIsNotAServerSyncFileIsRefused(t *testing.T) {
	f := &syncFake{files: map[string]string{"1.mp3": "x"}}
	n, root := syncNode(t, f)
	f.planBody = func([]syncer.Entry) map[string]any {
		return map[string]any{"rules_version": 1, "add": []map[string]any{
			{"key": "track:1", "path": "a.mp3", "fingerprint": "f", "size": 1, "state": "ready", "url": "http://evil.example/a.mp3"},
			{"key": "track:2", "path": "b.mp3", "fingerprint": "g", "size": 1, "state": "ready", "url": "/api/admin/sync/files/x"},
		}, "replace": []any{}, "move": []any{}, "remove": []any{}, "keep": 0}
	}
	syncClaim(n, params(""))
	res, err := syncApply(n, params(""))
	if err != nil {
		t.Fatal(err)
	}
	if res["failed"] != 2 || res["applied"] != 0 {
		t.Fatalf("result: %v", res)
	}
	if entries, _ := os.ReadDir(root); len(entries) != 1 { // only .remoment-sync
		t.Fatalf("volume: %v", entries)
	}
}

func TestAStalledConnectionEndsInsteadOfHangingTheJob(t *testing.T) {
	f := &syncFake{files: map[string]string{"1.mp3": "x"}, stall: true}
	n, _ := syncNode(t, f)
	s := &syncServer{n: n, jobID: "job1", targetID: testTarget}
	status, body, err := s.Download(context.Background(), "/api/sync/files/1.mp3", 0)
	if err != nil || status != 200 {
		t.Fatal(err, status)
	}
	ib := body.(*idleBody)
	ib.idle = 100 * time.Millisecond
	ib.timer.Reset(ib.idle)
	start := time.Now()
	if _, err := io.ReadAll(body); err == nil {
		t.Fatal("a stalled body must fail")
	}
	if time.Since(start) > time.Second {
		t.Fatal("the idle timeout did not fire")
	}
	body.Close()
}

func TestTheRoleStateAndCapabilities(t *testing.T) {
	n, _ := syncNode(t, &syncFake{})
	if st := n.syncRoleState(); st.State != "idle" {
		t.Fatalf("%v", st)
	}
	n.syncing.Store(true)
	if st := n.syncRoleState(); st.State != "busy" {
		t.Fatalf("%v", st)
	}
	if c := n.capabilities()["sync-target"].(map[string]any); c["volume"] != n.cfg.Sync.VolumePath {
		t.Fatalf("%v", c)
	}
	n.cfg.Sync.VolumePath = ""
	if st := n.syncRoleState(); st.State != "absent" {
		t.Fatalf("%v", st)
	}
	if _, ok := n.capabilities()["sync-target"]; ok {
		t.Fatal("no capability without a volume")
	}
}
