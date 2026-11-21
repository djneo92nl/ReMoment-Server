package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"remoment/node/internal/syncer"
)

// cliServerFake adds the admin-token routes the helper uses on top of the node fake: the target, the plan and the report.
func cliServerFake(t *testing.T, f *syncFake) (string, *[]string) {
	inner := f.handler()
	var seen []string
	mux := http.NewServeMux()
	admin := func(w http.ResponseWriter, r *http.Request) bool {
		seen = append(seen, r.Method+" "+r.URL.Path)
		if r.Header.Get("Authorization") != "Bearer adm" {
			w.WriteHeader(401)
			return false
		}
		return true
	}
	mux.HandleFunc("/api/admin/sync/targets/"+testTarget, func(w http.ResponseWriter, r *http.Request) {
		if admin(w, r) {
			_ = json.NewEncoder(w).Encode(map[string]any{"id": testTarget, "server_id": testServer, "kind": "node"})
		}
	})
	mux.HandleFunc("/api/admin/sync/targets/"+testTarget+"/plan", func(w http.ResponseWriter, r *http.Request) {
		if admin(w, r) {
			r.URL.Path = "/api/nodes/sync/plan"
			r.Header.Set("Authorization", "Bearer tok1")
			inner.ServeHTTP(w, r)
		}
	})
	mux.HandleFunc("/api/admin/sync/targets/"+testTarget+"/report", func(w http.ResponseWriter, r *http.Request) {
		if admin(w, r) {
			r.URL.Path = "/api/nodes/sync/report"
			r.Header.Set("Authorization", "Bearer tok1")
			inner.ServeHTTP(w, r)
		}
	})
	mux.Handle("/", inner)
	srv := httptest.NewServer(mux)
	t.Cleanup(srv.Close)

	return srv.URL, &seen
}

func TestHelperCLIClaimsThenSyncsAFolderWithTheAdminToken(t *testing.T) {
	f := &syncFake{files: map[string]string{"1.mp3": "first file", "2.mp3": "second file"}}
	url, seen := cliServerFake(t, f)
	root := t.TempDir()
	args := func(extra ...string) []string {
		return append([]string{"--server", url, "--token", "adm", "--target-id", testTarget, "--path", root}, extra...)
	}
	var out, errOut bytes.Buffer

	if code := runSyncCLI(args(), &out, &errOut, nil); code != 1 || len(f.plans) != 0 {
		t.Fatalf("an unclaimed folder is never written or planned: %d %s", code, errOut.String())
	}
	if code := runSyncCLI(args("--claim"), &out, &errOut, nil); code != 0 {
		t.Fatalf("claim: %d %s", code, errOut.String())
	}
	if m, _ := syncer.ReadMarker(root); m == nil || m.ServerID != testServer {
		t.Fatal("the marker carries the server's id from the target")
	}
	if code := runSyncCLI(args(), &out, &errOut, nil); code != 0 {
		t.Fatalf("sync: %d %s %s", code, out.String(), errOut.String())
	}
	b, err := os.ReadFile(filepath.Join(root, "Music", "1.mp3"))
	if err != nil || string(b) != "first file" {
		t.Fatalf("file: %q %v", b, err)
	}
	if len(f.reports) != 1 || f.badAuth != 0 || !strings.Contains(strings.Join(*seen, "|"), "POST /api/admin/sync/targets/"+testTarget+"/report") {
		t.Fatalf("one report through the admin route: %v %v", f.reports, *seen)
	}
}

func TestHelperCLIChecksItsArguments(t *testing.T) {
	var out, errOut bytes.Buffer
	for _, args := range [][]string{{}, {"--server", "http://x", "--token", "t", "--target-id", "short", "--path", "/tmp"}} {
		if code := runSyncCLI(args, &out, &errOut, nil); code != 1 {
			t.Fatalf("%v: %d", args, code)
		}
	}
	if code := runSyncCLI([]string{"--server", "http://127.0.0.1:1", "--token", "t", "--target-id", testTarget, "--path", t.TempDir(), "--scan"}, &out, &errOut, nil); code != 0 || !strings.Contains(out.String(), "mounted:true") {
		t.Fatalf("scan needs no server: %d %s", code, out.String())
	}
}
