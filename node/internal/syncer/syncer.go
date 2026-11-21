// Package syncer is the apply engine of a sync target (docs/architecture/offline-sync.md sections 3, 6 and 8): it reads the
// manifest on a volume, asks the server for a plan, downloads what is added or replaced into a temp file and renames it
// into place, renames moved files, deletes removed ones, and writes the manifest atomically as it goes.
//
// The safety rules live here, not in the server: a path is checked before it is used (SafePath), a file the manifest does
// not list is never overwritten or deleted, a file the user changed is released, a marker of another server or target
// refuses the whole run, and mass deletion needs a yes. Nothing outside the volume root is ever touched. No hardware,
// no node specifics: the node's job handlers and a CLI both call it.
package syncer

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// Entry is a manifest entry: what the server wrote to the volume.
type Entry struct {
	Key         string `json:"key"`
	Fingerprint string `json:"fingerprint"`
	Path        string `json:"path"`
	Size        int64  `json:"size"`
	Mtime       int64  `json:"mtime"`
}

// Manifest is `.remoment-sync/manifest.json`: the truth about what we put on the volume.
type Manifest struct {
	Version      int     `json:"version"`
	TargetID     string  `json:"target_id"`
	RulesVersion int     `json:"rules_version"`
	UpdatedAt    string  `json:"updated_at"`
	Entries      []Entry `json:"entries"`
	// Released are files the user changed on the volume: no longer ours. Such a key is added again only when its
	// fingerprint differs from the one it was released at (rule 3 of docs/architecture/offline-sync.md section 8).
	Released []ReleasedEntry `json:"released,omitempty"`
}

// ReleasedEntry remembers a released key and the fingerprint it had.
type ReleasedEntry struct {
	Key         string `json:"key"`
	Fingerprint string `json:"fingerprint"`
}

const maxReleased = 5000

// Marker is `.remoment-sync/target.json`: this volume belongs to this target of this server.
type Marker struct {
	Version   int    `json:"version"`
	TargetID  string `json:"target_id"`
	ServerID  string `json:"server_id"`
	CreatedAt string `json:"created_at"`
}

// Op is an add or replace of a plan. URL and SHA256 are null until the artifact is ready.
type Op struct {
	Key         string  `json:"key"`
	Path        string  `json:"path"`
	Fingerprint string  `json:"fingerprint"`
	Size        int64   `json:"size"`
	SHA256      *string `json:"sha256"`
	State       string  `json:"state"`
	URL         *string `json:"url"`
	Error       string  `json:"error"`
	FromPath    string  `json:"from_path"`
	FromSize    int64   `json:"from_size"`
}

// MoveOp is a rename on the target.
type MoveOp struct {
	Key      string `json:"key"`
	Path     string `json:"path"`
	FromPath string `json:"from_path"`
}

// RemoveOp is a deletion; only the key counts, the path deleted is the manifest's own.
type RemoveOp struct {
	Key  string `json:"key"`
	Path string `json:"path"`
	Size int64  `json:"size"`
}

// Plan is the server's answer to the manifest.
type Plan struct {
	RulesVersion    int              `json:"rules_version"`
	Add             []Op             `json:"add"`
	Replace         []Op             `json:"replace"`
	Move            []MoveOp         `json:"move"`
	Keep            int              `json:"keep"`
	Remove          []RemoveOp       `json:"remove"`
	ConfirmRequired bool             `json:"confirm_required"`
	RemoveFirst     bool             `json:"remove_first"`
	Warnings        []map[string]any `json:"warnings"`
}

// PlanRequest is what the server needs to plan.
type PlanRequest struct {
	Have         []Entry
	FreeBytes    int64
	FS           string
	RulesVersion int
}

// Report is what is told to the server afterwards.
type Report struct {
	RulesVersion int
	Applied      []string
	Failed       []Failure
	Released     []string
	OwnedBytes   int64
	OwnedCount   int
	FreeBytes    int64
}

// Failure is one file that could not be done.
type Failure struct {
	Key   string `json:"key"`
	Error string `json:"error"`
}

// Progress is a live report of the run; sending it also renews the job's lease.
type Progress struct {
	Phase      string `json:"phase,omitempty"`
	FilesDone  int    `json:"files_done"`
	FilesTotal int    `json:"files_total"`
	BytesDone  int64  `json:"bytes_done"`
	BytesTotal int64  `json:"bytes_total"`
	Current    string `json:"current,omitempty"`
}

// Server is the part of the ReMoment API the engine uses. The node implements it over REST; tests fake it.
type Server interface {
	Plan(req PlanRequest) (*Plan, error)
	// Download fetches a file from offset: status 200 (the whole file), 206 (from offset), 416, 409 (still preparing) ...
	Download(ctx context.Context, url string, offset int64) (status int, body io.ReadCloser, err error)
	Report(r Report) error
	// Progress renews the lease and tells whether the admin asked to cancel.
	Progress(p Progress) (cancel bool, err error)
}

// Config sets one run. Zero values take the defaults of the plan (batches of 25 files or 60 s, three attempts).
type Config struct {
	Root     string // the volume root, from the node's own config, never from a job
	TargetID string
	ServerID string
	Server   Server
	// Confirm lets a plan with mass deletion go through.
	Confirm bool
	Logf    func(string, ...any)
	Sleep   func(time.Duration)

	BatchFiles       int
	BatchEvery       time.Duration
	MaxAttempts      int
	Backoff          func(attempt int) time.Duration
	PreparingWait    time.Duration
	MaxPreparingWait time.Duration
	LowSpaceReserve  int64
	ProgressEvery    time.Duration
}

// Result is the outcome of Apply.
type Result struct {
	State        string // done | needs_confirmation | low_space | cancelled
	Applied      []string
	Failed       []Failure
	Released     []string
	Removed      int
	Moved        int
	BytesWritten int64
	OwnedBytes   int64
	OwnedCount   int
	FreeBytes    int64
	Warnings     []string
}

// Map is the result as the job result the server stores.
func (r *Result) Map() map[string]any {
	failures := r.Failed
	if len(failures) > 20 {
		failures = failures[:20]
	}
	return map[string]any{
		"state": r.State, "applied": len(r.Applied), "failed": len(r.Failed), "released": len(r.Released), "removed": r.Removed,
		"moved": r.Moved, "bytes_written": r.BytesWritten, "owned_bytes": r.OwnedBytes, "owned_count": r.OwnedCount,
		"free_bytes": r.FreeBytes, "warnings": r.Warnings, "failures": failures,
	}
}

var (
	errLowSpace  = errors.New("low_space")
	errPreparing = errors.New("preparing")
	errStale     = errors.New("stale")
)

// Engine is one run on one volume.
type Engine struct {
	cfg Config

	mu        sync.Mutex
	manifest  Manifest
	byKey     map[string]int // key -> index in manifest.Entries, rebuilt after changes
	prog      Progress
	cancelled bool
	res       Result
	failed    map[string]bool
	lastSave  time.Time
	sinceSave int
}

// New fills the defaults.
func New(cfg Config) *Engine {
	if cfg.Logf == nil {
		cfg.Logf = func(string, ...any) {}
	}
	if cfg.Sleep == nil {
		cfg.Sleep = time.Sleep
	}
	if cfg.BatchFiles == 0 {
		cfg.BatchFiles = 25
	}
	if cfg.BatchEvery == 0 {
		cfg.BatchEvery = 60 * time.Second
	}
	if cfg.MaxAttempts == 0 {
		cfg.MaxAttempts = 3
	}
	if cfg.Backoff == nil {
		cfg.Backoff = func(a int) time.Duration { return time.Duration(a*a) * time.Second }
	}
	if cfg.PreparingWait == 0 {
		cfg.PreparingWait = 10 * time.Second
	}
	if cfg.MaxPreparingWait == 0 {
		cfg.MaxPreparingWait = 6 * time.Hour
	}
	if cfg.LowSpaceReserve == 0 {
		cfg.LowSpaceReserve = 8 << 20
	}
	if cfg.ProgressEvery == 0 {
		cfg.ProgressEvery = 5 * time.Second
	}
	return &Engine{cfg: cfg, failed: map[string]bool{}}
}

func (e *Engine) meta(name string) string { return filepath.Join(e.cfg.Root, metaDir, name) }

// ReadMarker returns the marker of a volume, or nil when there is none.
func ReadMarker(root string) (*Marker, error) {
	b, err := os.ReadFile(filepath.Join(root, metaDir, "target.json"))
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var m Marker
	if err := json.Unmarshal(b, &m); err != nil || m.TargetID == "" {
		return nil, fmt.Errorf("unreadable marker")
	}
	return &m, nil
}

func isDir(p string) bool {
	fi, err := os.Stat(p)
	return err == nil && fi.IsDir()
}

// Scan reads the volume and writes nothing: whether it is there, its filesystem, size, free space, marker and what the
// manifest holds.
func Scan(root string) map[string]any {
	if !isDir(root) {
		return map[string]any{"mounted": false, "marker": nil, "manifest": nil}
	}
	size, free := Usage(root)
	out := map[string]any{"mounted": true, "fs": FSType(root), "size": size, "free": free, "marker": nil, "manifest": nil}
	if m, err := ReadMarker(root); err != nil {
		out["marker_error"] = err.Error()
	} else if m != nil {
		out["marker"] = map[string]any{"target_id": m.TargetID, "server_id": m.ServerID}
	}
	if mf, err := readManifest(filepath.Join(root, metaDir, "manifest.json")); err == nil && mf != nil {
		var bytes int64
		for _, en := range mf.Entries {
			bytes += en.Size
		}
		out["manifest"] = map[string]any{"entries": len(mf.Entries), "bytes": bytes, "rules_version": mf.RulesVersion}
	}
	return out
}

// Claim writes the marker on a volume that has none ("Use this drive"). A volume with a marker of this target and server
// is fine (idempotent); any other marker is refused. Nothing else is written, nothing is formatted.
func Claim(root, targetID, serverID string, now time.Time) error {
	if !isDir(root) {
		return fmt.Errorf("volume_not_found")
	}
	m, err := ReadMarker(root)
	if err != nil {
		return fmt.Errorf("already_marked: %v", err)
	}
	if m != nil {
		if m.TargetID == targetID && m.ServerID == serverID {
			return nil
		}
		return fmt.Errorf("already_marked")
	}
	if err := os.MkdirAll(filepath.Join(root, metaDir, "tmp"), 0o755); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(Marker{Version: 1, TargetID: targetID, ServerID: serverID, CreatedAt: now.UTC().Format(time.RFC3339)}, "", "  ")
	return writeAtomic(filepath.Join(root, metaDir, "target.json"), b)
}

// writeAtomic writes next to the target, flushes, and renames, so a pulled cable leaves the old or the new file, never half.
func writeAtomic(path string, data []byte) error {
	tmp := path + ".tmp"
	f, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
	if err != nil {
		return err
	}
	if _, err := f.Write(data); err != nil {
		f.Close()
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func readManifest(path string) (*Manifest, error) {
	b, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var m Manifest
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, err
	}
	return &m, nil
}

// checkMarker refuses a volume that is not marked, or marked for another server or target: nothing is written then.
func (e *Engine) checkMarker() error {
	if !isDir(e.cfg.Root) {
		return fmt.Errorf("volume_not_found")
	}
	m, err := ReadMarker(e.cfg.Root)
	if err != nil {
		return fmt.Errorf("marker_unreadable")
	}
	switch {
	case m == nil:
		return fmt.Errorf("no_marker: this drive is not marked; use it for the target first")
	case m.ServerID != e.cfg.ServerID:
		return fmt.Errorf("wrong_server: this drive belongs to another ReMoment server")
	case m.TargetID != e.cfg.TargetID:
		return fmt.Errorf("wrong_target: this drive belongs to another target")
	}
	return nil
}

func (e *Engine) index() {
	e.byKey = make(map[string]int, len(e.manifest.Entries))
	for i, en := range e.manifest.Entries {
		e.byKey[en.Key] = i
	}
}

func (e *Engine) entry(key string) (Entry, bool) {
	i, ok := e.byKey[key]
	if !ok {
		return Entry{}, false
	}
	return e.manifest.Entries[i], true
}

func (e *Engine) setEntry(en Entry) {
	if i, ok := e.byKey[en.Key]; ok {
		e.manifest.Entries[i] = en
		return
	}
	e.manifest.Entries = append(e.manifest.Entries, en)
	e.byKey[en.Key] = len(e.manifest.Entries) - 1
}

func (e *Engine) dropEntry(key string) {
	i, ok := e.byKey[key]
	if !ok {
		return
	}
	e.manifest.Entries = append(e.manifest.Entries[:i], e.manifest.Entries[i+1:]...)
	e.index()
}

// load reads the manifest. An unreadable one (or one of another target) is treated as unknown: the run starts from an
// empty `have`, so it can only add, never delete, and a copy of the broken file is kept for a person to look at.
func (e *Engine) load() {
	e.manifest = Manifest{Version: 1, TargetID: e.cfg.TargetID}
	path := e.meta("manifest.json")
	mf, err := readManifest(path)
	if err != nil || (mf != nil && (mf.TargetID != e.cfg.TargetID || mf.Version != 1)) {
		_ = os.Rename(path, fmt.Sprintf("%s.lost-%d", path, time.Now().Unix()))
		e.res.Warnings = append(e.res.Warnings, "manifest_lost")
		e.cfg.Logf("sync: manifest unreadable or foreign; starting from an empty one, no deletions")
	} else if mf != nil {
		e.manifest = *mf
	}
	e.index()
}

// save writes the manifest atomically.
func (e *Engine) save() error {
	e.manifest.Version = 1
	e.manifest.TargetID = e.cfg.TargetID
	e.manifest.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	sort.Slice(e.manifest.Entries, func(i, j int) bool { return e.manifest.Entries[i].Key < e.manifest.Entries[j].Key })
	e.index()
	b, err := json.Marshal(e.manifest)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Join(e.cfg.Root, metaDir, "tmp"), 0o755); err != nil {
		return err
	}
	e.lastSave, e.sinceSave = time.Now(), 0
	return writeAtomic(e.meta("manifest.json"), b)
}

// tick counts a finished file and saves the manifest after a batch (default 25 files or 60 s).
func (e *Engine) tick() {
	e.sinceSave++
	if e.sinceSave >= e.cfg.BatchFiles || time.Since(e.lastSave) >= e.cfg.BatchEvery {
		if err := e.save(); err != nil {
			e.cfg.Logf("sync: manifest: %v", err)
		}
	}
}

// verify compares each manifest entry with its file (rule 3). Missing: the entry is dropped (it comes back at the next
// plan if still wanted). Changed size or mtime: the user edited or replaced it, so it is released: dropped from the
// manifest, the file left alone and reported. An entry with an unsafe path is dropped and never touched.
func (e *Engine) verify() {
	var keep []Entry
	for _, en := range e.manifest.Entries {
		if SafePath(en.Path) != nil {
			e.res.Warnings = append(e.res.Warnings, "unsafe_manifest_path")
			continue
		}
		fi, err := os.Lstat(abs(e.cfg.Root, en.Path))
		switch {
		case err != nil || !fi.Mode().IsRegular():
			e.res.Warnings = append(e.res.Warnings, "file_missing")
		case fi.Size() != en.Size || (en.Mtime > 0 && absInt(fi.ModTime().Unix()-en.Mtime) > 2):
			e.res.Released = append(e.res.Released, en.Key)
			e.manifest.Released = append(e.manifest.Released, ReleasedEntry{Key: en.Key, Fingerprint: en.Fingerprint})
		default:
			keep = append(keep, en)
		}
	}
	e.manifest.Entries = keep
	if n := len(e.manifest.Released); n > maxReleased {
		e.manifest.Released = e.manifest.Released[n-maxReleased:]
	}
	e.index()
}

// releasedSame says whether a plan op wants a key the user took over, at the very fingerprint it was released at: then the
// file stays the user's and nothing is added. A different fingerprint (a better rip) is added again, under a numbered
// name if the user's file is still in the way.
func (e *Engine) releasedSame(op Op) bool {
	for i, r := range e.manifest.Released {
		if r.Key != op.Key {
			continue
		}
		if r.Fingerprint == op.Fingerprint {
			return true
		}
		e.manifest.Released = append(e.manifest.Released[:i], e.manifest.Released[i+1:]...)
		return false
	}
	return false
}

// cleanTmp removes partial downloads nobody resumed for a day.
func (e *Engine) cleanTmp() {
	dir := filepath.Join(e.cfg.Root, metaDir, "tmp")
	entries, _ := os.ReadDir(dir)
	for _, en := range entries {
		if fi, err := en.Info(); err == nil && strings.HasSuffix(en.Name(), ".part") && time.Since(fi.ModTime()) > 24*time.Hour {
			_ = os.Remove(filepath.Join(dir, en.Name()))
		}
	}
}

func absInt(v int64) int64 {
	if v < 0 {
		return -v
	}
	return v
}

func (e *Engine) taken() map[string]string {
	t := make(map[string]string, len(e.manifest.Entries))
	for _, en := range e.manifest.Entries {
		t[strings.ToLower(en.Path)] = en.Key
	}
	return t
}

func (e *Engine) setProgress(f func(p *Progress)) {
	e.mu.Lock()
	f(&e.prog)
	e.mu.Unlock()
}

func (e *Engine) isCancelled() bool {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.cancelled
}

// pump sends the live progress now and then, which also renews the job's lease on the server, and notes a cancel.
func (e *Engine) pump(ctx context.Context) func() {
	stop := make(chan struct{})
	done := make(chan struct{})
	send := func() {
		e.mu.Lock()
		p := e.prog
		e.mu.Unlock()
		if cancel, err := e.cfg.Server.Progress(p); err == nil && cancel {
			e.mu.Lock()
			e.cancelled = true
			e.mu.Unlock()
		}
	}
	go func() {
		defer close(done)
		t := time.NewTicker(e.cfg.ProgressEvery)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case <-ctx.Done():
				return
			case <-t.C:
				send()
			}
		}
	}()
	return func() { close(stop); <-done; send() }
}

// Apply runs one sync. It returns an error only when nothing could be done (volume gone, wrong marker, the plan could not
// be fetched); files that fail are in the result and the run goes on.
func (e *Engine) Apply(ctx context.Context) (*Result, error) {
	if err := e.checkMarker(); err != nil {
		return nil, err
	}
	e.res = Result{State: "done"}
	e.load()
	e.verify()
	e.cleanTmp()
	stopPump := e.pump(ctx)
	defer stopPump()

	waited := time.Duration(0)
	staleRounds := 0
	needsConfirm := false

	for {
		if ctx.Err() != nil || e.isCancelled() {
			break
		}
		plan, err := e.plan()
		if err != nil {
			_ = e.save()
			e.report()

			return nil, fmt.Errorf("plan: %w", err)
		}

		pending, stale, err := e.round(ctx, plan, &needsConfirm)
		if errors.Is(err, errLowSpace) {
			e.res.State = "low_space"
			break
		}
		if pending == 0 && !stale {
			break
		}
		if stale {
			staleRounds++
			if staleRounds > 3 {
				e.res.Warnings = append(e.res.Warnings, "files_changed_during_sync")
				break
			}
			continue
		}
		if waited >= e.cfg.MaxPreparingWait {
			e.res.Warnings = append(e.res.Warnings, "artifacts_not_ready")
			break
		}
		e.setProgress(func(p *Progress) { p.Phase = "preparing" })
		e.cfg.Sleep(e.cfg.PreparingWait)
		waited += e.cfg.PreparingWait
	}

	if err := e.save(); err != nil {
		return nil, fmt.Errorf("writing the manifest: %w", err)
	}
	if needsConfirm && e.res.State == "done" {
		e.res.State = "needs_confirmation"
	}
	if e.isCancelled() {
		e.res.State = "cancelled"
	}
	e.finish()
	e.report()
	e.cfg.Logf("sync: %s: %d applied, %d failed, %d released, %d removed", e.res.State, len(e.res.Applied), len(e.res.Failed), len(e.res.Released), e.res.Removed)

	return &e.res, nil
}

func (e *Engine) finish() {
	e.res.OwnedCount = len(e.manifest.Entries)
	e.res.OwnedBytes = 0
	for _, en := range e.manifest.Entries {
		e.res.OwnedBytes += en.Size
	}
	_, e.res.FreeBytes = Usage(e.cfg.Root)
}

func (e *Engine) report() {
	r := Report{
		RulesVersion: e.manifest.RulesVersion, Applied: e.res.Applied, Failed: e.res.Failed, Released: e.res.Released,
		OwnedBytes: e.res.OwnedBytes, OwnedCount: e.res.OwnedCount, FreeBytes: e.res.FreeBytes,
	}
	if err := e.cfg.Server.Report(r); err != nil {
		e.cfg.Logf("sync: report: %v", err)
	}
}

func (e *Engine) plan() (*Plan, error) {
	_, free := Usage(e.cfg.Root)
	have := append([]Entry(nil), e.manifest.Entries...)
	p, err := e.cfg.Server.Plan(PlanRequest{Have: have, FreeBytes: free, FS: FSType(e.cfg.Root), RulesVersion: e.manifest.RulesVersion})
	if err != nil {
		return nil, err
	}
	// The manifest records the rules version the plan was made for, so the next plan knows only the library moved.
	e.manifest.RulesVersion = p.RulesVersion
	return p, nil
}

// round does one plan: moves, then (when space is short) removals, then the ready additions and replacements, then the
// removals. It returns how many operations are still waiting for their artifact, and whether a file turned out stale.
func (e *Engine) round(ctx context.Context, plan *Plan, needsConfirm *bool) (pending int, stale bool, err error) {
	for _, w := range plan.Warnings {
		if code, ok := w["code"].(string); ok && !contains(e.res.Warnings, code) {
			e.res.Warnings = append(e.res.Warnings, code)
		}
	}

	var work []Op
	var bytesTotal int64
	for _, op := range append(append([]Op{}, plan.Add...), plan.Replace...) {
		if e.failed[op.Key] {
			continue
		}
		if e.releasedSame(op) {
			if !contains(e.res.Warnings, "user_file_kept") {
				e.res.Warnings = append(e.res.Warnings, "user_file_kept")
			}
			continue
		}
		if op.State == "failed" {
			e.fail(op.Key, "artifact_failed: "+op.Error)
			continue
		}
		work = append(work, op)
		bytesTotal += op.Size
	}
	done := len(e.res.Applied)
	e.setProgress(func(p *Progress) {
		p.Phase = "download"
		p.FilesTotal = done + len(work)
		p.FilesDone = done
		p.BytesTotal = e.res.BytesWritten + bytesTotal
		p.BytesDone = e.res.BytesWritten
	})

	e.moves(plan.Move)

	destructive := e.cfg.Confirm || !plan.ConfirmRequired
	if !destructive {
		*needsConfirm = true
	}
	allReady := true
	for _, op := range work {
		if op.State != "ready" {
			allReady = false
		}
	}
	removed := false
	if plan.RemoveFirst && destructive {
		e.removals(plan.Remove)
		removed = true
	}

	for _, op := range work {
		if ctx.Err() != nil || e.isCancelled() {
			return 0, false, nil
		}
		if op.State != "ready" || op.URL == nil {
			pending++
			continue
		}
		replace := op.FromPath != "" || e.hasEntry(op.Key)
		switch ferr := e.place(ctx, op, replace); {
		case ferr == nil:
		case errors.Is(ferr, errLowSpace):
			return pending, false, errLowSpace
		case errors.Is(ferr, errPreparing):
			pending++
		case errors.Is(ferr, errStale):
			stale = true
		default:
			e.fail(op.Key, ferr.Error())
		}
		e.tick()
	}

	// Removals last, once nothing is waiting, so a failed run never leaves the target emptier than it was.
	if !removed && destructive && pending == 0 && !stale && allReady && ctx.Err() == nil && !e.isCancelled() {
		e.removals(plan.Remove)
	}

	return pending, stale, nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

func (e *Engine) hasEntry(key string) bool { _, ok := e.byKey[key]; return ok }

func (e *Engine) fail(key, msg string) {
	e.failed[key] = true
	e.res.Failed = append(e.res.Failed, Failure{Key: key, Error: msg})
	e.cfg.Logf("sync: %s: %s", key, msg)
}

// moves renames files on the target. Only entries of the manifest move, and only inside the volume.
func (e *Engine) moves(moves []MoveOp) {
	for _, m := range moves {
		en, ok := e.entry(m.Key)
		if !ok || e.failed[m.Key] {
			continue
		}
		if err := SafePath(m.Path); err != nil {
			e.fail(m.Key, "unsafe_path: "+err.Error())
			continue
		}
		dest, _ := resolveFree(e.cfg.Root, m.Path, e.taken(), m.Key)
		if strings.EqualFold(dest, en.Path) {
			continue
		}
		if err := os.MkdirAll(filepath.Dir(abs(e.cfg.Root, dest)), 0o755); err != nil {
			e.fail(m.Key, err.Error())
			continue
		}
		if err := os.Rename(abs(e.cfg.Root, en.Path), abs(e.cfg.Root, dest)); err != nil {
			e.fail(m.Key, "move: "+err.Error())
			continue
		}
		old := en.Path
		en.Path = dest
		e.setEntry(en)
		pruneEmptyDirs(e.cfg.Root, old)
		e.res.Moved++
		e.tick()
	}
}

// removals deletes files of the manifest, file first and entry after, so a crash leaves an entry whose file is gone (the
// next plan re-adds or drops it), never a file we forgot we own. The path deleted is the manifest's, not the plan's.
func (e *Engine) removals(list []RemoveOp) {
	for _, r := range list {
		en, ok := e.entry(r.Key)
		if !ok {
			continue // not ours: invisible to sync
		}
		if e.isCancelled() {
			return
		}
		if err := os.Remove(abs(e.cfg.Root, en.Path)); err != nil && !os.IsNotExist(err) {
			e.fail(r.Key, "remove: "+err.Error())
			continue
		}
		e.dropEntry(r.Key)
		pruneEmptyDirs(e.cfg.Root, en.Path)
		e.res.Removed++
		e.tick()
	}
}

// place downloads one file and puts it in its final path.
func (e *Engine) place(ctx context.Context, op Op, replace bool) error {
	if err := SafePath(op.Path); err != nil {
		return fmt.Errorf("unsafe_path: %v", err)
	}
	if op.Size < 0 {
		return fmt.Errorf("bad size")
	}
	old, hadOld := e.entry(op.Key)

	if err := os.MkdirAll(filepath.Join(e.cfg.Root, metaDir, "tmp"), 0o755); err != nil {
		return err
	}
	part := filepath.Join(e.cfg.Root, metaDir, "tmp", safeName(op.Fingerprint)+".part")

	e.setProgress(func(p *Progress) { p.Current = op.Path })
	if err := e.download(ctx, op, part); err != nil {
		return err
	}

	var dest string
	if hadOld && replace && strings.EqualFold(old.Path, op.Path) {
		dest = old.Path // our own file at the same path: swapped by the rename
	} else {
		taken := e.taken()
		var changed bool
		dest, changed = resolveFree(e.cfg.Root, op.Path, taken, op.Key)
		if changed {
			e.res.Warnings = append(e.res.Warnings, "renamed_to_avoid_overwrite")
		}
	}
	if err := os.MkdirAll(filepath.Dir(abs(e.cfg.Root, dest)), 0o755); err != nil {
		return err
	}
	if err := os.Rename(part, abs(e.cfg.Root, dest)); err != nil {
		return fmt.Errorf("rename: %v", err)
	}
	fi, err := os.Stat(abs(e.cfg.Root, dest))
	if err != nil {
		return err
	}
	if hadOld && !strings.EqualFold(old.Path, dest) {
		if rmErr := os.Remove(abs(e.cfg.Root, old.Path)); rmErr == nil {
			pruneEmptyDirs(e.cfg.Root, old.Path)
		}
	}
	e.setEntry(Entry{Key: op.Key, Fingerprint: op.Fingerprint, Path: dest, Size: fi.Size(), Mtime: fi.ModTime().Unix()})
	e.res.Applied = append(e.res.Applied, op.Key)
	e.res.BytesWritten += fi.Size()
	e.setProgress(func(p *Progress) { p.FilesDone = len(e.res.Applied); p.BytesDone = e.res.BytesWritten })

	return nil
}

func safeName(s string) string {
	var b strings.Builder
	for _, r := range s {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '-' || r == '_' {
			b.WriteRune(r)
		}
	}
	if b.Len() == 0 || b.Len() > 80 {
		return "file"
	}
	return b.String()
}

// download fetches the file into `part`, resuming from what is there, and checks size and sha256.
func (e *Engine) download(ctx context.Context, op Op, part string) error {
	var last error
	for attempt := 1; attempt <= e.cfg.MaxAttempts; attempt++ {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		var offset int64
		if fi, err := os.Stat(part); err == nil {
			offset = fi.Size()
			if op.Size > 0 && offset > op.Size {
				_ = os.Remove(part)
				offset = 0
			}
		}
		if _, free := Usage(e.cfg.Root); op.Size > 0 && free < op.Size-offset+e.cfg.LowSpaceReserve {
			return errLowSpace
		}

		status, body, err := e.cfg.Server.Download(ctx, *op.URL, offset)
		if err != nil {
			last = err
			e.cfg.Sleep(e.cfg.Backoff(attempt))
			continue
		}
		switch status {
		case 409:
			body.Close()
			return errPreparing
		case 403, 404, 410:
			body.Close()
			return errStale
		case 416:
			body.Close()
			if offset != op.Size {
				_ = os.Remove(part)
				last = fmt.Errorf("range not satisfiable")
				continue
			}
		case 200, 206:
			if err := e.write(part, body, status == 206 && offset > 0); err != nil {
				last = err
				e.cfg.Sleep(e.cfg.Backoff(attempt))
				continue
			}
		default:
			body.Close()
			last = fmt.Errorf("HTTP %d", status)
			e.cfg.Sleep(e.cfg.Backoff(attempt))
			continue
		}

		if err := verifyFile(part, op); err != nil {
			_ = os.Remove(part)
			last = err
			continue
		}
		return nil
	}
	if last == nil {
		last = fmt.Errorf("download failed")
	}
	return last
}

// write copies the body into the part file (appending for a 206), flushes it to the disk, and closes the body.
func (e *Engine) write(part string, body io.ReadCloser, appendMode bool) error {
	defer body.Close()
	flags := os.O_WRONLY | os.O_CREATE
	if appendMode {
		flags |= os.O_APPEND
	} else {
		flags |= os.O_TRUNC
	}
	f, err := os.OpenFile(part, flags, 0o644)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, body); err != nil {
		f.Close()
		return err // the part stays: the next attempt resumes from it
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

// verifyFile checks the size, and the sha256 when the server gave one.
func verifyFile(path string, op Op) error {
	fi, err := os.Stat(path)
	if err != nil {
		return err
	}
	if op.Size > 0 && fi.Size() != op.Size {
		return fmt.Errorf("size mismatch: got %d, want %d", fi.Size(), op.Size)
	}
	if op.SHA256 == nil || *op.SHA256 == "" {
		return nil
	}
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return err
	}
	if !strings.EqualFold(hex.EncodeToString(h.Sum(nil)), *op.SHA256) {
		return fmt.Errorf("sha256 mismatch")
	}
	return nil
}
