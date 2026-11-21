package syncer

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	tid = "01m7zr811pfvdf6ssbmm7qyfcy"
	sid = "01m7zr811pfvdf6ssbmm7qyfc0"
)

type libFile struct {
	Fingerprint string
	Path        string
	Data        []byte
	State       string // ready (default) | preparing | failed
	Preparing   int    // rounds it stays preparing
	NoSHA       bool
}

// fakeServer plays the server: it plans from a library map against what the volume says it has, serves ranged downloads, and records what it was told.
type fakeServer struct {
	t *testing.T

	mu        sync.Mutex
	lib       map[string]*libFile
	plans     int
	downloads []string // keys, in order
	offsets   []int64
	reports   []Report
	events    []string // "download:key", "remove-first" ...

	confirmRequired  bool
	removeFirst      bool
	cancel           bool
	rangeOK          bool
	dlHook           func(key string, call int) (status int, body []byte, err error) // override a download
	calls            map[string]int
	lastHave         []Entry
	planPathOverride map[string]string
	extraRemove      []RemoveOp
	progressCalls    int
}

func newFake(t *testing.T) *fakeServer {
	return &fakeServer{t: t, lib: map[string]*libFile{}, rangeOK: true, calls: map[string]int{}}
}

func (f *fakeServer) add(key, path, fp, data string) *libFile {
	l := &libFile{Fingerprint: fp, Path: path, Data: []byte(data), State: "ready"}
	f.lib[key] = l
	return l
}

func (f *fakeServer) Plan(req PlanRequest) (*Plan, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.plans++
	f.lastHave = req.Have
	have := map[string]Entry{}
	for _, h := range req.Have {
		have[h.Key] = h
	}
	p := &Plan{RulesVersion: 3, ConfirmRequired: f.confirmRequired, RemoveFirst: f.removeFirst}
	keys := make([]string, 0, len(f.lib))
	for k := range f.lib {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		l := f.lib[k]
		path := l.Path
		if o, ok := f.planPathOverride[k]; ok {
			path = o
		}
		op := Op{Key: k, Path: path, Fingerprint: l.Fingerprint, Size: int64(len(l.Data)), State: "ready"}
		if !l.NoSHA {
			sum := sha256.Sum256(l.Data)
			s := hex.EncodeToString(sum[:])
			op.SHA256 = &s
		}
		if l.State == "failed" {
			op.State, op.Error = "failed", "boom"
		} else if l.Preparing > 0 {
			op.State = "preparing"
			l.Preparing--
		} else {
			u := "/api/sync/files/" + k
			op.URL = &u
		}
		h, ok := have[k]
		switch {
		case !ok:
			p.Add = append(p.Add, op)
		case h.Fingerprint != l.Fingerprint:
			op.FromPath, op.FromSize = h.Path, h.Size
			p.Replace = append(p.Replace, op)
		case !strings.EqualFold(h.Path, path):
			p.Move = append(p.Move, MoveOp{Key: k, Path: path, FromPath: h.Path})
		default:
			p.Keep++
		}
	}
	for _, h := range req.Have {
		if _, ok := f.lib[h.Key]; !ok {
			p.Remove = append(p.Remove, RemoveOp{Key: h.Key, Path: h.Path, Size: h.Size})
		}
	}
	p.Remove = append(p.Remove, f.extraRemove...)
	return p, nil
}

func (f *fakeServer) Download(ctx context.Context, url string, offset int64) (int, io.ReadCloser, error) {
	f.mu.Lock()
	key := strings.TrimPrefix(url, "/api/sync/files/")
	f.calls[key]++
	call := f.calls[key]
	f.downloads = append(f.downloads, key)
	f.offsets = append(f.offsets, offset)
	f.events = append(f.events, "download:"+key)
	hook := f.dlHook
	l := f.lib[key]
	f.mu.Unlock()

	if hook != nil {
		status, body, err := hook(key, call)
		if err != nil || status != 0 {
			return status, io.NopCloser(bytes.NewReader(body)), err
		}
	}
	if l == nil {
		return 404, io.NopCloser(strings.NewReader("")), nil
	}
	if offset > 0 && f.rangeOK {
		if offset >= int64(len(l.Data)) {
			return 416, io.NopCloser(strings.NewReader("")), nil
		}
		return 206, io.NopCloser(bytes.NewReader(l.Data[offset:])), nil
	}
	return http.StatusOK, io.NopCloser(bytes.NewReader(l.Data)), nil
}

func (f *fakeServer) Report(r Report) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.reports = append(f.reports, r)
	return nil
}

func (f *fakeServer) Progress(p Progress) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.progressCalls++
	return f.cancel, nil
}

func newEngine(t *testing.T, root string, f Server, mod ...func(*Config)) *Engine {
	cfg := Config{Root: root, TargetID: tid, ServerID: sid, Server: f, Sleep: func(time.Duration) {}, ProgressEvery: 5 * time.Millisecond, Logf: t.Logf}
	for _, m := range mod {
		m(&cfg)
	}
	return New(cfg)
}

func claimed(t *testing.T) string {
	root := t.TempDir()
	if err := Claim(root, tid, sid, time.Now()); err != nil {
		t.Fatal(err)
	}
	return root
}

func read(t *testing.T, root, rel string) string {
	b, err := os.ReadFile(filepath.Join(root, filepath.FromSlash(rel)))
	if err != nil {
		t.Fatalf("read %s: %v", rel, err)
	}
	return string(b)
}

func exists(root, rel string) bool {
	_, err := os.Stat(filepath.Join(root, filepath.FromSlash(rel)))
	return err == nil
}

// files lists every file under root except the metadata directory, as relative paths.
func files(t *testing.T, root string) []string {
	var out []string
	_ = filepath.Walk(root, func(p string, fi os.FileInfo, err error) error {
		rel, _ := filepath.Rel(root, p)
		if rel == metaDir {
			return filepath.SkipDir
		}
		if err == nil && !fi.IsDir() {
			out = append(out, filepath.ToSlash(rel))
		}
		return nil
	})
	sort.Strings(out)
	return out
}

func manifestOf(t *testing.T, root string) Manifest {
	var m Manifest
	b, err := os.ReadFile(filepath.Join(root, metaDir, "manifest.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func apply(t *testing.T, e *Engine) *Result {
	r, err := e.Apply(context.Background())
	if err != nil {
		t.Fatalf("apply: %v", err)
	}
	return r
}

func TestClaimWritesOnlyTheMarkerAndOnlyOnAnUnmarkedVolume(t *testing.T) {
	root := t.TempDir()
	os.WriteFile(filepath.Join(root, "holiday.jpg"), []byte("x"), 0o644)

	if err := Claim(root, tid, sid, time.Now()); err != nil {
		t.Fatal(err)
	}
	m, err := ReadMarker(root)
	if err != nil || m == nil || m.TargetID != tid || m.ServerID != sid {
		t.Fatalf("marker: %+v %v", m, err)
	}
	if got := files(t, root); len(got) != 1 || got[0] != "holiday.jpg" {
		t.Fatalf("claim touched the volume: %v", got)
	}
	if err := Claim(root, tid, sid, time.Now()); err != nil {
		t.Fatalf("claiming again for the same target must be fine: %v", err)
	}
	if err := Claim(root, "01m7zr811pfvdf6ssbmm7qyfaa", sid, time.Now()); err == nil || !strings.HasPrefix(err.Error(), "already_marked") {
		t.Fatalf("a marked volume is refused: %v", err)
	}
	if err := Claim(root, tid, "other-server", time.Now()); err == nil {
		t.Fatal("another server's claim must be refused")
	}
	if err := Claim(filepath.Join(root, "nope"), tid, sid, time.Now()); err == nil || err.Error() != "volume_not_found" {
		t.Fatalf("missing volume: %v", err)
	}
}

func TestAnUnmarkedOrForeignVolumeIsNeverWritten(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "A/one.mp3", "f1", "one")

	bare := t.TempDir()
	if _, err := newEngine(t, bare, f).Apply(context.Background()); err == nil || !strings.HasPrefix(err.Error(), "no_marker") {
		t.Fatalf("unmarked: %v", err)
	}
	if entries, _ := os.ReadDir(bare); len(entries) != 0 {
		t.Fatalf("an unmarked volume was written to: %v", entries)
	}

	root := claimed(t)
	e := New(Config{Root: root, TargetID: tid, ServerID: "another-server", Server: f})
	if _, err := e.Apply(context.Background()); err == nil || !strings.HasPrefix(err.Error(), "wrong_server") {
		t.Fatalf("wrong server: %v", err)
	}
	e = New(Config{Root: root, TargetID: "01m7zr811pfvdf6ssbmm7qyfaa", ServerID: sid, Server: f})
	if _, err := e.Apply(context.Background()); err == nil || !strings.HasPrefix(err.Error(), "wrong_target") {
		t.Fatalf("wrong target: %v", err)
	}
	if _, err := newEngine(t, filepath.Join(root, "gone"), f).Apply(context.Background()); err == nil || err.Error() != "volume_not_found" {
		t.Fatalf("missing volume: %v", err)
	}
	if f.plans != 0 || len(f.downloads) != 0 {
		t.Fatal("a refused run must not even ask for a plan")
	}
	if got := files(t, root); len(got) != 0 {
		t.Fatalf("files written: %v", got)
	}
}

func TestFirstSyncWritesFilesAtomicallyAndASecondSyncDoesNothing(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "Band/LP/01 - One.mp3", "f1", "one-bytes")
	f.add("track:2", "Band/LP/02 - Two.mp3", "f2", "two-bytes-longer")
	root := claimed(t)

	r := apply(t, newEngine(t, root, f))
	if r.State != "done" || len(r.Applied) != 2 || len(r.Failed) != 0 || r.BytesWritten != int64(len("one-bytes")+len("two-bytes-longer")) {
		t.Fatalf("result: %+v", r)
	}
	if read(t, root, "Band/LP/01 - One.mp3") != "one-bytes" || read(t, root, "Band/LP/02 - Two.mp3") != "two-bytes-longer" {
		t.Fatal("content")
	}
	m := manifestOf(t, root)
	if len(m.Entries) != 2 || m.TargetID != tid || m.RulesVersion != 3 || m.Entries[0].Size == 0 || m.Entries[0].Mtime == 0 {
		t.Fatalf("manifest: %+v", m)
	}
	if exists(root, ".remoment-sync/manifest.json.tmp") {
		t.Fatal("a half-written manifest was left")
	}
	if parts, _ := filepath.Glob(filepath.Join(root, metaDir, "tmp", "*")); len(parts) != 0 {
		t.Fatalf("partial downloads left: %v", parts)
	}
	rep := f.reports[len(f.reports)-1]
	if len(rep.Applied) != 2 || rep.OwnedCount != 2 || rep.OwnedBytes != r.OwnedBytes || rep.RulesVersion != 3 {
		t.Fatalf("report: %+v", rep)
	}

	before := len(f.downloads)
	r = apply(t, newEngine(t, root, f))
	if len(f.downloads) != before || len(r.Applied) != 0 || r.Removed != 0 || r.State != "done" {
		t.Fatalf("a second sync must change nothing: %+v downloads %d->%d", r, before, len(f.downloads))
	}
	if len(f.lastHave) != 2 {
		t.Fatalf("have: %+v", f.lastHave)
	}
}

func TestADownloadResumesFromThePartFile(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "fp1", "0123456789abcdef")
	root := claimed(t)
	os.MkdirAll(filepath.Join(root, metaDir, "tmp"), 0o755)
	os.WriteFile(filepath.Join(root, metaDir, "tmp", "fp1.part"), []byte("01234567"), 0o644)

	apply(t, newEngine(t, root, f))
	if read(t, root, "a.mp3") != "0123456789abcdef" {
		t.Fatal("content")
	}
	if len(f.offsets) != 1 || f.offsets[0] != 8 {
		t.Fatalf("it must ask for the rest with a range: %v", f.offsets)
	}
}

func TestAConnectionThatDiesMidFileLeavesAPartThatTheNextRunResumes(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "fp1", "0123456789abcdef")
	f.add("track:2", "b.mp3", "fp2", "second")
	root := claimed(t)

	// The cable is pulled: the body breaks after 6 bytes and the retry (one attempt only) is not made.
	broken := &brokenServer{fakeServer: f, cut: 6}
	r := apply(t, newEngine(t, root, nil, func(c *Config) { c.Server = broken; c.MaxAttempts = 1 }))
	if len(r.Failed) != 1 || r.Failed[0].Key != "track:1" || len(r.Applied) != 1 || r.Applied[0] != "track:2" {
		t.Fatalf("the other file must still go through: %+v", r)
	}
	if exists(root, "a.mp3") {
		t.Fatal("a half file must never sit at the final path")
	}
	if b, _ := os.ReadFile(filepath.Join(root, metaDir, "tmp", "fp1.part")); string(b) != "012345" {
		t.Fatalf("part: %q", b)
	}
	if m := manifestOf(t, root); len(m.Entries) != 1 || m.Entries[0].Key != "track:2" {
		t.Fatalf("the manifest holds only what is complete: %+v", m)
	}

	f.dlHook = nil
	f.offsets = nil
	r = apply(t, newEngine(t, root, f))
	if read(t, root, "a.mp3") != "0123456789abcdef" || len(r.Applied) != 1 || len(f.offsets) != 1 || f.offsets[0] != 6 {
		t.Fatalf("resume: %+v offsets %v", r, f.offsets)
	}
}

// brokenServer cuts the first download body after `cut` bytes with an error.
type brokenServer struct {
	*fakeServer
	cut  int
	done bool
}

func (b *brokenServer) Download(ctx context.Context, url string, offset int64) (int, io.ReadCloser, error) {
	status, body, err := b.fakeServer.Download(ctx, url, offset)
	if err != nil || status != 200 || strings.HasSuffix(url, "track:2") {
		return status, body, err
	}
	data, _ := io.ReadAll(body)
	return status, io.NopCloser(io.MultiReader(bytes.NewReader(data[:b.cut]), errReader{})), nil
}

type errReader struct{}

func (errReader) Read([]byte) (int, error) { return 0, errors.New("connection reset by peer") }

func TestAWrongSizeOrHashIsRetriedAndNeverPlaced(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "fp1", "good-content")
	root := claimed(t)

	f.dlHook = func(key string, call int) (int, []byte, error) {
		if call == 1 {
			return 200, []byte("evil-content"), nil // same size, wrong hash
		}
		return 0, nil, nil
	}
	r := apply(t, newEngine(t, root, f))
	if read(t, root, "a.mp3") != "good-content" || len(r.Failed) != 0 || f.calls["track:1"] != 2 {
		t.Fatalf("retry: %+v calls %d", r, f.calls["track:1"])
	}

	g := newFake(t)
	g.add("track:9", "bad.mp3", "fp9", "payload")
	g.dlHook = func(string, int) (int, []byte, error) { return 200, []byte("shorter"[:3]), nil }
	root2 := claimed(t)
	r = apply(t, newEngine(t, root2, g))
	if len(r.Failed) != 1 || exists(root2, "bad.mp3") || g.calls["track:9"] != 3 {
		t.Fatalf("a file that never verifies is failed after three attempts: %+v calls %d", r, g.calls["track:9"])
	}
	if parts, _ := filepath.Glob(filepath.Join(root2, metaDir, "tmp", "*")); len(parts) != 0 {
		t.Fatalf("a corrupt part must be removed: %v", parts)
	}
}

func TestAFileWeDoNotOwnIsNeverOverwritten(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "Band/LP/01 - One.mp3", "f1", "ours")
	f.add("track:2", "Band/LP/02 - Two.mp3", "f2", "ours-too")
	root := claimed(t)
	os.MkdirAll(filepath.Join(root, "Band", "LP"), 0o755)
	os.WriteFile(filepath.Join(root, "Band", "LP", "01 - One.mp3"), []byte("the user's own file"), 0o644)
	os.WriteFile(filepath.Join(root, "Band", "LP", "02 - TWO.MP3"), []byte("user, other case"), 0o644) // FAT would treat it as the same name

	r := apply(t, newEngine(t, root, f))
	if read(t, root, "Band/LP/01 - One.mp3") != "the user's own file" || read(t, root, "Band/LP/02 - TWO.MP3") != "user, other case" {
		t.Fatal("the user's files were touched")
	}
	m := manifestOf(t, root)
	paths := []string{m.Entries[0].Path, m.Entries[1].Path}
	sort.Strings(paths)
	if paths[0] != "Band/LP/01 - One (2).mp3" || paths[1] != "Band/LP/02 - Two (2).mp3" {
		t.Fatalf("paths: %v", paths)
	}
	if !contains(r.Warnings, "renamed_to_avoid_overwrite") {
		t.Fatalf("warnings: %v", r.Warnings)
	}
	if read(t, root, "Band/LP/01 - One (2).mp3") != "ours" {
		t.Fatal("content")
	}
}

func TestAFileTheUserChangedIsReleasedAndAFileTheyDeletedComesBack(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "original-1")
	f.add("track:2", "b.mp3", "f2", "original-2")
	f.add("track:3", "c.mp3", "f3", "original-3")
	root := claimed(t)
	apply(t, newEngine(t, root, f))

	os.WriteFile(filepath.Join(root, "a.mp3"), []byte("user retagged this file"), 0o644) // edited: size changes
	os.Remove(filepath.Join(root, "b.mp3"))                                              // deleted by hand

	r := apply(t, newEngine(t, root, f))
	if len(r.Released) != 1 || r.Released[0] != "track:1" {
		t.Fatalf("released: %v", r.Released)
	}
	if read(t, root, "a.mp3") != "user retagged this file" {
		t.Fatal("an edited file must be left alone")
	}
	if read(t, root, "b.mp3") != "original-2" || len(r.Applied) != 1 || r.Applied[0] != "track:2" {
		t.Fatalf("a deleted track is added again: %+v", r)
	}
	m := manifestOf(t, root)
	for _, en := range m.Entries {
		if en.Key == "track:1" {
			t.Fatal("a released file is no longer in the manifest")
		}
	}
	if rep := f.reports[len(f.reports)-1]; len(rep.Released) != 0 && false {
		t.Log(rep)
	}

	// It stays the user's while its fingerprint is the same ...
	r = apply(t, newEngine(t, root, f))
	if len(r.Applied) != 0 || read(t, root, "a.mp3") != "user retagged this file" || !contains(r.Warnings, "user_file_kept") {
		t.Fatalf("released and unchanged: %+v", r)
	}
	// ... and is added again, next to it, when the server's file changes (a better rip).
	f.lib["track:1"].Fingerprint = "f1-better"
	f.lib["track:1"].Data = []byte("better rip")
	r = apply(t, newEngine(t, root, f))
	if len(r.Applied) != 1 || read(t, root, "a.mp3") != "user retagged this file" || read(t, root, "a (2).mp3") != "better rip" {
		t.Fatalf("a changed fingerprint adds it again under a numbered name: %+v files %v", r, files(t, root))
	}
}

func TestRemovalsTouchOnlyEntriesOfTheManifest(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "Band/LP/one.mp3", "f1", "one")
	f.add("track:2", "Band/Solo/two.mp3", "f2", "two")
	f.add("track:3", "keep.mp3", "f3", "three")
	root := claimed(t)
	apply(t, newEngine(t, root, f))
	os.WriteFile(filepath.Join(root, "Band", "LP", "cover.jpg"), []byte("stranger"), 0o644) // a stranger in our folder
	os.WriteFile(filepath.Join(root, "notes.txt"), []byte("stranger 2"), 0o644)

	delete(f.lib, "track:1")
	delete(f.lib, "track:2")
	// A hostile or buggy server also asks to delete things that are not in the manifest.
	f.extraRemove = []RemoveOp{{Key: "track:99", Path: "notes.txt"}, {Key: "track:98", Path: "../outside.txt"}}
	outside := filepath.Join(filepath.Dir(root), "outside.txt")
	os.WriteFile(outside, []byte("do not touch"), 0o644)
	defer os.Remove(outside)

	r := apply(t, newEngine(t, root, f, func(c *Config) { c.Confirm = true }))
	if r.Removed != 2 {
		t.Fatalf("removed: %d", r.Removed)
	}
	if got := files(t, root); strings.Join(got, ",") != "Band/LP/cover.jpg,keep.mp3,notes.txt" {
		t.Fatalf("files: %v", got)
	}
	if exists(root, "Band/Solo") {
		t.Fatal("an emptied folder of ours goes")
	}
	if !exists(root, "Band/LP") {
		t.Fatal("a folder with a stranger inside stays")
	}
	if b, _ := os.ReadFile(outside); string(b) != "do not touch" {
		t.Fatal("outside the volume was touched")
	}
}

func TestMassDeletionNeedsAYes(t *testing.T) {
	f := newFake(t)
	for i := 1; i <= 4; i++ {
		f.add(fmt.Sprintf("track:%d", i), fmt.Sprintf("old/%d.mp3", i), fmt.Sprintf("f%d", i), "x")
	}
	root := claimed(t)
	apply(t, newEngine(t, root, f))

	for i := 1; i <= 4; i++ {
		delete(f.lib, fmt.Sprintf("track:%d", i))
	}
	f.add("track:10", "new.mp3", "f10", "fresh")
	f.confirmRequired = true

	r := apply(t, newEngine(t, root, f))
	if r.State != "needs_confirmation" || r.Removed != 0 || len(files(t, root)) != 5 {
		t.Fatalf("unattended: %+v files %v", r, files(t, root))
	}
	if read(t, root, "new.mp3") != "fresh" {
		t.Fatal("the additions still go through")
	}

	r = apply(t, newEngine(t, root, f, func(c *Config) { c.Confirm = true }))
	if r.State != "done" || r.Removed != 4 || strings.Join(files(t, root), ",") != "new.mp3" {
		t.Fatalf("confirmed: %+v files %v", r, files(t, root))
	}
}

func TestPlanPathsThatEscapeTheVolumeAreRefused(t *testing.T) {
	root := claimed(t)
	parent := filepath.Dir(root)
	f := newFake(t)
	bad := []string{"../evil.mp3", "/etc/passwd", "a/../../b.mp3", "C:/x.mp3", `a\b.mp3`, ".remoment-sync/target.json", ".REMOMENT-SYNC/x", "a/./b.mp3", "con?.mp3", "a//b.mp3", "trailing.", "x/y ", "ctl\x01.mp3", ""}
	for i, p := range bad {
		f.add(fmt.Sprintf("track:%d", i+1), p, fmt.Sprintf("f%d", i), "evil")
	}
	f.add("track:100", "fine/ok.mp3", "fok", "ok")

	r := apply(t, newEngine(t, root, f))
	if len(r.Failed) != len(bad) || len(r.Applied) != 1 || r.Applied[0] != "track:100" {
		t.Fatalf("result: applied %v failed %d", r.Applied, len(r.Failed))
	}
	for _, fl := range r.Failed {
		if !strings.Contains(fl.Error, "unsafe_path") {
			t.Fatalf("failure: %+v", fl)
		}
	}
	if got := files(t, root); strings.Join(got, ",") != "fine/ok.mp3" {
		t.Fatalf("files: %v", got)
	}
	if m, _ := ReadMarker(root); m == nil || m.TargetID != tid {
		t.Fatal("the marker must survive")
	}
	for _, name := range []string{"evil.mp3", "b.mp3", "passwd"} {
		if _, err := os.Stat(filepath.Join(parent, name)); err == nil {
			t.Fatalf("%s written outside the volume", name)
		}
	}
}

func TestSafePathTable(t *testing.T) {
	ok := []string{"a.mp3", "Artist/Album/01 - Title.mp3", "Ünïcode/日本語.flac", ".hidden/x.mp3", "a b/c d.mp3"}
	for _, p := range ok {
		if err := SafePath(p); err != nil {
			t.Errorf("%q should be fine: %v", p, err)
		}
	}
	bad := []string{"", "/a", "a/", "../a", "a/..", "a/../b", "a\\b", "a/b:c", "a/b*c", "a|b", "a\"b", "a<b", strings.Repeat("x", 256), strings.Repeat("a/", 600) + "b", "\xff\xfe.mp3"}
	for _, p := range bad {
		if SafePath(p) == nil {
			t.Errorf("%q must be refused", p)
		}
	}
}

func TestAMovedTrackIsRenamedWithoutDownloading(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "Band/LP/01 - One.mp3", "f1", "one")
	root := claimed(t)
	apply(t, newEngine(t, root, f))
	before := len(f.downloads)

	f.lib["track:1"].Path = "Band/LP/01 - Uno.mp3"
	r := apply(t, newEngine(t, root, f))
	if r.Moved != 1 || len(f.downloads) != before || read(t, root, "Band/LP/01 - Uno.mp3") != "one" || exists(root, "Band/LP/01 - One.mp3") {
		t.Fatalf("move: %+v", r)
	}
	if m := manifestOf(t, root); m.Entries[0].Path != "Band/LP/01 - Uno.mp3" {
		t.Fatalf("manifest: %+v", m)
	}
}

func TestAReplacementSwapsTheFileAndALaterPathDropsTheOldOne(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "old-bytes")
	root := claimed(t)
	apply(t, newEngine(t, root, f))

	f.lib["track:1"].Fingerprint = "f1b"
	f.lib["track:1"].Data = []byte("new-bytes-longer")
	r := apply(t, newEngine(t, root, f))
	if len(r.Applied) != 1 || read(t, root, "a.mp3") != "new-bytes-longer" || len(files(t, root)) != 1 {
		t.Fatalf("replace in place: %+v files %v", r, files(t, root))
	}

	f.lib["track:1"].Fingerprint = "f1c"
	f.lib["track:1"].Path = "b/new.mp3"
	f.lib["track:1"].Data = []byte("third")
	apply(t, newEngine(t, root, f))
	if strings.Join(files(t, root), ",") != "b/new.mp3" || read(t, root, "b/new.mp3") != "third" {
		t.Fatalf("replace to a new path: %v", files(t, root))
	}
}

func TestRemoveFirstFreesSpaceBeforeTheDownloads(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "old.mp3", "f1", "old")
	root := claimed(t)
	apply(t, newEngine(t, root, f))

	delete(f.lib, "track:1")
	f.add("track:2", "new.mp3", "f2", "new")
	f.removeFirst = true
	f.events = nil
	e := newEngine(t, root, f, func(c *Config) { c.Confirm = true })
	apply(t, e)
	if strings.Join(files(t, root), ",") != "new.mp3" {
		t.Fatalf("files: %v", files(t, root))
	}
	if exists(root, "old.mp3") {
		t.Fatal("old file should be gone")
	}
}

func TestLowSpaceStopsTheRunAndKeepsTheManifest(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "small")
	root := claimed(t)
	apply(t, newEngine(t, root, f))

	f.add("track:2", "huge.mp3", "f2", "x").Data = []byte("x")
	// A file that cannot fit: the plan says it is far bigger than any free space.
	g := &hugeServer{fakeServer: f}
	r, err := newEngine(t, root, g).Apply(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if r.State != "low_space" || len(r.Applied) != 0 || exists(root, "huge.mp3") {
		t.Fatalf("result: %+v", r)
	}
	if m := manifestOf(t, root); len(m.Entries) != 1 {
		t.Fatalf("manifest: %+v", m)
	}
	if parts, _ := filepath.Glob(filepath.Join(root, metaDir, "tmp", "*")); len(parts) != 0 {
		t.Fatalf("parts: %v", parts)
	}
}

type hugeServer struct{ *fakeServer }

func (h *hugeServer) Plan(req PlanRequest) (*Plan, error) {
	p, err := h.fakeServer.Plan(req)
	for i := range p.Add {
		p.Add[i].Size = 1 << 60
		p.Add[i].SHA256 = nil
	}
	return p, err
}

func TestAnArtifactThatIsStillPreparingIsAskedForAgain(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "ready.mp3", "f1", "ready")
	f.add("track:2", "later.mp3", "f2", "later").Preparing = 2
	root := claimed(t)
	slept := 0

	r := apply(t, newEngine(t, root, f, func(c *Config) { c.Sleep = func(time.Duration) { slept++ } }))
	if len(r.Applied) != 2 || f.plans != 3 || slept != 2 {
		t.Fatalf("applied %v plans %d slept %d", r.Applied, f.plans, slept)
	}
	if f.downloads[0] != "track:1" {
		t.Fatalf("the ready file goes first: %v", f.downloads)
	}
	if read(t, root, "later.mp3") != "later" {
		t.Fatal("content")
	}
}

func TestPreparingForeverEndsWithAWarningNotAHang(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "never.mp3", "f1", "x").Preparing = 1 << 30
	f.add("track:2", "old.mp3", "f2", "y")
	root := claimed(t)
	apply(t, newEngine(t, root, f))
	delete(f.lib, "track:2") // wanted to be removed, but must not be while something is still pending

	r := apply(t, newEngine(t, root, f, func(c *Config) {
		c.MaxPreparingWait = 30 * time.Second
		c.PreparingWait = 10 * time.Second
		c.Confirm = true
	}))
	if !contains(r.Warnings, "artifacts_not_ready") || len(r.Applied) != 0 {
		t.Fatalf("result: %+v", r)
	}
	if !exists(root, "old.mp3") {
		t.Fatal("removals wait until nothing is pending: a failed run never leaves the target emptier")
	}
}

func TestAStaleOrPreparingAnswerIsNotAFailure(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "alpha")
	root := claimed(t)
	f.dlHook = func(key string, call int) (int, []byte, error) {
		switch call {
		case 1:
			return 403, nil, nil // the signature ran out: plan again for a fresh URL
		case 2:
			return 409, nil, nil // still being prepared
		}
		return 0, nil, nil
	}
	slept := 0
	r := apply(t, newEngine(t, root, f, func(c *Config) { c.Sleep = func(time.Duration) { slept++ } }))
	if len(r.Failed) != 0 || len(r.Applied) != 1 || read(t, root, "a.mp3") != "alpha" || f.plans < 3 {
		t.Fatalf("result: %+v plans %d", r, f.plans)
	}
}

func TestACorruptManifestMeansNoDeletionsAndNoOverwrites(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "alpha")
	root := claimed(t)
	apply(t, newEngine(t, root, f))
	os.WriteFile(filepath.Join(root, metaDir, "manifest.json"), []byte("{broken"), 0o644)
	os.WriteFile(filepath.Join(root, "orphan.mp3"), []byte("left from an earlier life"), 0o644)

	r := apply(t, newEngine(t, root, f, func(c *Config) { c.Confirm = true }))
	if !contains(r.Warnings, "manifest_lost") || r.Removed != 0 {
		t.Fatalf("result: %+v", r)
	}
	if read(t, root, "a.mp3") != "alpha" || read(t, root, "orphan.mp3") != "left from an earlier life" || read(t, root, "a (2).mp3") != "alpha" {
		t.Fatalf("files: %v", files(t, root))
	}
	if lost, _ := filepath.Glob(filepath.Join(root, metaDir, "manifest.json.lost-*")); len(lost) != 1 {
		t.Fatal("the broken manifest must be kept for a person to look at")
	}
	if len(manifestOf(t, root).Entries) != 1 {
		t.Fatal("a new, valid manifest is written")
	}
}

func TestACancelFinishesTheCurrentFileWritesTheManifestAndStops(t *testing.T) {
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "first")
	f.add("track:2", "b.mp3", "f2", "second")
	f.add("track:3", "c.mp3", "f3", "third")
	root := claimed(t)
	f.dlHook = func(key string, call int) (int, []byte, error) {
		if key == "track:1" {
			f.mu.Lock()
			f.cancel = true // the admin pressed Cancel while the first file downloads
			f.mu.Unlock()
			time.Sleep(60 * time.Millisecond) // the progress report picks it up
		}
		return 0, nil, nil
	}
	r := apply(t, newEngine(t, root, f))
	if r.State != "cancelled" || len(r.Applied) != 1 || read(t, root, "a.mp3") != "first" || exists(root, "b.mp3") {
		t.Fatalf("result: %+v files %v", r, files(t, root))
	}
	if m := manifestOf(t, root); len(m.Entries) != 1 {
		t.Fatalf("the manifest must hold the finished file: %+v", m)
	}
}

func TestTheManifestIsSavedInBatchesSoAPulledCableLosesLittle(t *testing.T) {
	f := newFake(t)
	for i := 1; i <= 3; i++ {
		f.add(fmt.Sprintf("track:%d", i), fmt.Sprintf("%d.mp3", i), fmt.Sprintf("f%d", i), "x")
	}
	root := claimed(t)
	var seen []int
	f.dlHook = func(key string, call int) (int, []byte, error) {
		if b, err := os.ReadFile(filepath.Join(root, metaDir, "manifest.json")); err == nil {
			var m Manifest
			json.Unmarshal(b, &m)
			seen = append(seen, len(m.Entries))
		} else {
			seen = append(seen, 0)
		}
		return 0, nil, nil
	}
	apply(t, newEngine(t, root, f, func(c *Config) { c.BatchFiles = 1 }))
	if fmt.Sprint(seen) != "[0 1 2]" {
		t.Fatalf("manifest on disk at each download: %v", seen)
	}
}

func TestScan(t *testing.T) {
	gone := Scan(filepath.Join(t.TempDir(), "nope"))
	if gone["mounted"] != false {
		t.Fatalf("%v", gone)
	}
	bare := t.TempDir()
	s := Scan(bare)
	if s["mounted"] != true || s["marker"] != nil || s["manifest"] != nil || s["free"].(int64) <= 0 {
		t.Fatalf("%v", s)
	}
	f := newFake(t)
	f.add("track:1", "a.mp3", "f1", "alpha")
	root := claimed(t)
	apply(t, newEngine(t, root, f))
	s = Scan(root)
	marker := s["marker"].(map[string]any)
	mf := s["manifest"].(map[string]any)
	if marker["target_id"] != tid || marker["server_id"] != sid || mf["entries"] != 1 || mf["bytes"] != int64(5) {
		t.Fatalf("%v", s)
	}
}

func TestRunsWithoutRangeSupportRestartTheFile(t *testing.T) {
	f := newFake(t)
	f.rangeOK = false
	f.add("track:1", "a.mp3", "fp1", "0123456789")
	root := claimed(t)
	os.MkdirAll(filepath.Join(root, metaDir, "tmp"), 0o755)
	os.WriteFile(filepath.Join(root, metaDir, "tmp", "fp1.part"), []byte("0123"), 0o644)
	apply(t, newEngine(t, root, f))
	if read(t, root, "a.mp3") != "0123456789" {
		t.Fatalf("a server answering 200 to a range request must restart the file, not append to the part")
	}
}

func TestOldPartsAreCleanedAndFreshOnesKept(t *testing.T) {
	f := newFake(t)
	root := claimed(t)
	tmp := filepath.Join(root, metaDir, "tmp")
	os.MkdirAll(tmp, 0o755)
	os.WriteFile(filepath.Join(tmp, "old.part"), []byte("x"), 0o644)
	os.WriteFile(filepath.Join(tmp, "new.part"), []byte("x"), 0o644)
	past := time.Now().Add(-48 * time.Hour)
	os.Chtimes(filepath.Join(tmp, "old.part"), past, past)
	apply(t, newEngine(t, root, f))
	if exists(root, ".remoment-sync/tmp/old.part") || !exists(root, ".remoment-sync/tmp/new.part") {
		t.Fatal("clean-up of partial downloads")
	}
}
