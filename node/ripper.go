package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Ripper role (workstream 18.1, docs/architecture/node-ripper.md): reads the TOC of the disc in the node's own drive
// (job `ripper.status`) and rips it with cyanrip (job `ripper.rip`), then uploads the FLAC files through the node
// upload of the server. The drive, the cyanrip binary and the temp directory are the node's own config, never taken
// from a job; a job only carries the release the server chose and its tags. Nothing here ejects the disc (the node is
// write-only towards the machine: no privileged helper).

// RipperConfig is the `ripper` object of the node config.
type RipperConfig struct {
	// Device is the CD drive, e.g. /dev/sr0. Empty: the node has no drive and the role reports `absent`.
	Device string `json:"device"`
	// Cyanrip is the program (default "cyanrip").
	Cyanrip string `json:"cyanrip"`
	// TempDir is where a rip is written before the upload (default <state_dir>/rips). Each rip gets its own
	// subdirectory, removed afterwards; nothing else in this directory is ever touched.
	TempDir string `json:"temp_dir"`
	// Nice level of the ripper (default 15, 0 = no nice); NoIonice true leaves out the idle I/O class (`ionice -c 3`).
	Nice     *int `json:"nice"`
	NoIonice bool `json:"no_ionice"`
	// Offset is the drive's read offset in samples (AccurateRip database); nil: not passed. Retries: cyanrip's -r.
	Offset  *int `json:"offset"`
	Retries int  `json:"retries"`
}

var (
	uuidRe      = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	discIDRe    = regexp.MustCompile(`^[A-Za-z0-9._-]{28}$`)
	barcodeRe   = regexp.MustCompile(`^[0-9]{8,14}$`)
	jobIDRe     = regexp.MustCompile(`^[0-9A-Za-z]{26}$`)
	ripDirRe    = regexp.MustCompile(`^rip-[0-9A-Za-z]{26}$`)
	driveRe     = regexp.MustCompile(`^/dev/[A-Za-z0-9._-]{1,30}$`)
	progressPct = regexp.MustCompile(`(\d{1,3}(?:\.\d+)?)\s*%`)
	progressTr  = regexp.MustCompile(`(?i)track\s+(\d{1,2})`)
)

// ripParams is the `ripper.rip` job: everything in it is untrusted until validate has passed.
type ripParams struct {
	ReleaseMBID string `json:"release_mbid"`
	DiscID      string `json:"disc_id"`
	Meta        struct {
		Album           string `json:"album"`
		Artist          string `json:"artist"`
		Year            *int   `json:"year"`
		Barcode         string `json:"barcode"`
		Label           string `json:"label"`
		CatalogueNumber string `json:"catalogue_number"`
		Tracks          []struct {
			Number int     `json:"number"`
			Title  string  `json:"title"`
			Artist *string `json:"artist"`
		} `json:"tracks"`
	} `json:"meta"`
}

// cleanText says whether a tag value is fit to hand to the ripper as an argument: not empty when required, no control
// characters, not absurdly long.
func cleanText(s string, required bool) bool {
	if required && strings.TrimSpace(s) == "" {
		return false
	}
	if len(s) > 300 {
		return false
	}
	for _, r := range s {
		if r < 0x20 || r == 0x7f {
			return false
		}
	}
	return true
}

func (p *ripParams) validate() error {
	if !uuidRe.MatchString(p.ReleaseMBID) {
		return fmt.Errorf("invalid_release")
	}
	if !discIDRe.MatchString(p.DiscID) {
		return fmt.Errorf("invalid_disc_id")
	}
	m := p.Meta
	if !cleanText(m.Album, true) || !cleanText(m.Artist, true) || !cleanText(m.Label, false) || !cleanText(m.CatalogueNumber, false) {
		return fmt.Errorf("invalid_meta")
	}
	if m.Barcode != "" && !barcodeRe.MatchString(m.Barcode) {
		return fmt.Errorf("invalid_barcode")
	}
	if m.Year != nil && (*m.Year < 1000 || *m.Year > 9999) {
		return fmt.Errorf("invalid_year")
	}
	if len(m.Tracks) < 1 || len(m.Tracks) > 99 {
		return fmt.Errorf("invalid_tracks")
	}
	for i, t := range m.Tracks {
		if t.Number != i+1 || !cleanText(t.Title, true) || (t.Artist != nil && !cleanText(*t.Artist, false)) {
			return fmt.Errorf("invalid_track_%d", i+1)
		}
	}
	return nil
}

// metaEscape escapes a value for the `key=value:key=value` metadata strings of cyanrip (an ffmpeg dictionary string):
// a backslash before a backslash, a colon or an equals sign.
func metaEscape(s string) string {
	return strings.NewReplacer(`\`, `\\`, `:`, `\:`, `=`, `\=`).Replace(s)
}

func metaString(pairs [][2]string) string {
	var parts []string
	for _, kv := range pairs {
		if kv[1] != "" {
			parts = append(parts, kv[0]+"="+metaEscape(kv[1]))
		}
	}
	return strings.Join(parts, ":")
}

// ripArgs builds the argument array of the rip (a pure function, tested without cyanrip or a drive). The tags come
// from the server's release: no MusicBrainz lookup by the node (-N), the release id and barcode are written as the
// Vorbis comments MUSICBRAINZ_ALBUMID and BARCODE, which is what links the files to the CD of the physical collection.
// The flags are cyanrip's as documented; unverified against a real cyanrip build (docs/architecture/node-ripper.md).
func ripArgs(c RipperConfig, p *ripParams) ([]string, error) {
	if !driveRe.MatchString(c.Device) {
		return nil, fmt.Errorf("invalid_device")
	}
	prog := c.Cyanrip
	if prog == "" {
		prog = "cyanrip"
	}
	nice := 15
	if c.Nice != nil {
		nice = *c.Nice
	}
	var argv []string
	if !c.NoIonice {
		argv = append(argv, "ionice", "-c", "3")
	}
	if nice > 0 {
		argv = append(argv, "nice", "-n", strconv.Itoa(nice))
	}
	m := p.Meta
	year := ""
	if m.Year != nil {
		year = strconv.Itoa(*m.Year)
	}
	argv = append(argv, prog, "-d", c.Device, "-o", "flac", "-N")
	if c.Offset != nil {
		argv = append(argv, "-s", strconv.Itoa(*c.Offset))
	}
	if c.Retries > 0 {
		argv = append(argv, "-r", strconv.Itoa(c.Retries))
	}
	argv = append(argv, "-a", metaString([][2]string{
		{"album", m.Album}, {"album_artist", m.Artist}, {"date", year}, {"label", m.Label}, {"catalognumber", m.CatalogueNumber},
		{"MUSICBRAINZ_ALBUMID", strings.ToLower(p.ReleaseMBID)}, {"BARCODE", m.Barcode},
	}))
	for _, t := range m.Tracks {
		artist := m.Artist
		if t.Artist != nil && *t.Artist != "" {
			artist = *t.Artist
		}
		argv = append(argv, "-t", strconv.Itoa(t.Number)+"="+metaString([][2]string{{"title", t.Title}, {"artist", artist}}))
	}
	return argv, nil
}

// ripProgress is what a progress report carries.
type ripProgress struct {
	Phase   string  `json:"phase"`
	Track   int     `json:"track"`
	Tracks  int     `json:"tracks"`
	Percent float64 `json:"percent"`
	Detail  string  `json:"detail,omitempty"`
}

// parseRipLine reads what cyanrip prints while it works: a track number and/or a percentage. What the lines look like
// is unverified, so this is lenient and the caller also counts the files that appeared.
func parseRipLine(line string) (track int, percent float64, hasTrack, hasPercent bool) {
	if m := progressTr.FindStringSubmatch(line); m != nil {
		track, _ = strconv.Atoi(m[1])
		hasTrack = true
	}
	if m := progressPct.FindStringSubmatch(line); m != nil {
		percent, _ = strconv.ParseFloat(m[1], 64)
		if hasPercent = percent <= 100; !hasPercent {
			percent = 0
		}
	}
	return
}

// runCmd runs a command to its end in dir, calling onLine for every line of its output (split on \n and \r, since
// progress lines are redrawn). ctx cancels it. It returns the tail of the output.
type runCmd func(ctx context.Context, argv []string, dir string, onLine func(string)) (tail string, err error)

func execRipper(ctx context.Context, argv []string, dir string, onLine func(string)) (string, error) {
	cmd := exec.CommandContext(ctx, argv[0], argv[1:]...)
	cmd.Dir = dir
	cmd.WaitDelay = 5 * time.Second
	pr, pw := io.Pipe()
	cmd.Stdout, cmd.Stderr = pw, pw
	var tail tailBuffer
	done := make(chan struct{})
	go func() {
		defer close(done)
		sc := bufio.NewScanner(pr)
		sc.Buffer(make([]byte, 64*1024), 1<<20)
		sc.Split(func(data []byte, atEOF bool) (int, []byte, error) {
			if i := bytes.IndexAny(data, "\r\n"); i >= 0 {
				return i + 1, data[:i], nil
			}
			if atEOF && len(data) > 0 {
				return len(data), data, nil
			}
			return 0, nil, nil
		})
		for sc.Scan() {
			if line := strings.TrimSpace(sc.Text()); line != "" {
				tail.Write([]byte(line + "\n"))
				onLine(line)
			}
		}
	}()
	err := cmd.Run()
	pw.Close()
	<-done
	return tail.String(), err
}

// ripper is the role: one rip at a time, the state for the heartbeat.
type ripper struct {
	cfg    RipperConfig
	toc    tocReader
	run    runCmd
	logf   func(string, ...any)
	every  time.Duration // how often progress goes to the server (2 s; tests shorten it)
	busy   sync.Mutex
	mu     sync.Mutex
	state  string
	detail string
}

func newRipper(cfg RipperConfig, stateDir string, logf func(string, ...any)) *ripper {
	if cfg.TempDir == "" {
		cfg.TempDir = filepath.Join(stateDir, "rips")
	}
	return &ripper{cfg: cfg, toc: readTOC, run: execRipper, logf: logf, every: 2 * time.Second, state: "idle"}
}

func (r *ripper) roleState() roleState {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.cfg.Device == "" {
		return roleState{State: "absent", Detail: "no drive configured"}
	}
	return roleState{State: r.state, Detail: r.detail}
}

func (r *ripper) set(state, detail string) {
	r.mu.Lock()
	r.state, r.detail = state, detail
	r.mu.Unlock()
}

// cleanStale removes the leftovers of rips an earlier run of the node did not finish. Only directories named
// rip-<job id> directly inside the temp directory go; the directory itself and anything else stay.
func (r *ripper) cleanStale() {
	entries, err := os.ReadDir(r.cfg.TempDir)
	if err != nil {
		return
	}
	for _, e := range entries {
		if e.IsDir() && ripDirRe.MatchString(e.Name()) {
			if err := os.RemoveAll(filepath.Join(r.cfg.TempDir, e.Name())); err != nil {
				r.logf("ripper: cannot remove %s: %v", e.Name(), err)
			}
		}
	}
}

// status is job `ripper.status`: is there a disc, and which.
func (r *ripper) status() (map[string]any, error) {
	if r.cfg.Device == "" {
		return nil, fmt.Errorf("no_drive_configured")
	}
	t, err := r.toc(r.cfg.Device)
	if errors.Is(err, errNoDisc) {
		return map[string]any{"present": false, "drive": r.cfg.Device}, nil
	}
	if err != nil {
		return nil, fmt.Errorf("drive_error: %v", err)
	}
	if !t.valid() {
		return nil, fmt.Errorf("drive_error: the TOC makes no sense")
	}
	res := map[string]any{"present": true, "audio": t.Audio, "tracks": t.tracks(), "drive": r.cfg.Device}
	if t.Audio {
		res["disc_id"] = t.discID()
		res["toc"] = t.tocString()
	}
	return res, nil
}

func ripperStatus(n *Node, _ json.RawMessage) (map[string]any, error) {
	if n.ripper == nil {
		return nil, fmt.Errorf("no_drive_configured")
	}
	return n.ripper.status()
}

func ripperRip(n *Node, params json.RawMessage) (map[string]any, error) {
	if n.ripper == nil || n.ripper.cfg.Device == "" || n.job == nil {
		return nil, fmt.Errorf("no_drive_configured")
	}
	var p ripParams
	if err := json.Unmarshal(params, &p); err != nil {
		return nil, fmt.Errorf("bad params: %w", err)
	}
	if err := p.validate(); err != nil {
		return nil, err
	}
	return n.ripper.rip(n.api, n.job.ID, &p)
}

// ripFiles finds the FLAC files and the log the ripper left under dir, in path order.
func ripFiles(dir string) (flacs []string, logs []string, err error) {
	err = filepath.WalkDir(dir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		switch strings.ToLower(filepath.Ext(path)) {
		case ".flac":
			flacs = append(flacs, path)
		case ".log":
			logs = append(logs, path)
		}
		return nil
	})
	sort.Strings(flacs)
	sort.Strings(logs)
	return
}

// reporter sends the latest progress every two seconds, which also renews the job's lease on the server, and cancels
// the rip when the server says so (or no longer knows the job as ours).
type reporter struct {
	api    *API
	id     string
	cancel context.CancelFunc
	mu     sync.Mutex
	p      ripProgress
	why    string
}

func (rp *reporter) update(f func(p *ripProgress)) {
	rp.mu.Lock()
	f(&rp.p)
	rp.mu.Unlock()
}

func (rp *reporter) snapshot() ripProgress {
	rp.mu.Lock()
	defer rp.mu.Unlock()
	return rp.p
}

func (rp *reporter) reason() string {
	rp.mu.Lock()
	defer rp.mu.Unlock()
	return rp.why
}

func (rp *reporter) send() {
	cancel, err := rp.api.progressWith(rp.id, rp.snapshot())
	var he *httpError
	switch {
	case cancel:
		rp.stop("cancelled")
	case errors.As(err, &he) && he.Status == 409: // the server no longer has this job as running (lost, cancelled)
		rp.stop("cancelled")
	case errors.Is(err, errUnauthorized):
		rp.stop("unauthorized")
	}
}

func (rp *reporter) stop(why string) {
	rp.mu.Lock()
	if rp.why == "" {
		rp.why = why
	}
	rp.mu.Unlock()
	rp.cancel()
}

func (rp *reporter) loop(ctx context.Context, every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			rp.send()
		}
	}
}

const logLimit = 32 * 1024

func tailString(b []byte, n int) string {
	if len(b) > n {
		b = b[len(b)-n:]
	}
	return strings.ToValidUTF8(string(b), "")
}

// rip runs one rip: cyanrip into its own temp directory, then the upload, then the cleanup. One at a time.
func (r *ripper) rip(api *API, jobID string, p *ripParams) (map[string]any, error) {
	if !r.busy.TryLock() {
		return nil, fmt.Errorf("busy")
	}
	defer r.busy.Unlock()
	if !jobIDRe.MatchString(jobID) {
		return nil, fmt.Errorf("invalid_job_id")
	}

	// The disc in the drive must be the one the server chose a release for.
	t, err := r.toc(r.cfg.Device)
	if errors.Is(err, errNoDisc) {
		return nil, fmt.Errorf("no_disc")
	}
	if err != nil {
		return nil, fmt.Errorf("drive_error: %v", err)
	}
	if !t.valid() || !t.Audio {
		return nil, fmt.Errorf("not_audio")
	}
	if t.discID() != p.DiscID || t.tracks() != len(p.Meta.Tracks) {
		return nil, fmt.Errorf("disc_changed")
	}

	argv, err := ripArgs(r.cfg, p)
	if err != nil {
		return nil, err
	}

	r.cleanStale()
	work := filepath.Join(r.cfg.TempDir, "rip-"+jobID)
	if err := os.MkdirAll(work, 0o700); err != nil {
		return nil, fmt.Errorf("temp_dir: %v", err)
	}
	defer os.RemoveAll(work)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	rp := &reporter{api: api, id: jobID, cancel: cancel, p: ripProgress{Phase: "ripping", Tracks: len(p.Meta.Tracks), Track: 1}}
	go rp.loop(ctx, r.every)

	label := p.Meta.Artist + " – " + p.Meta.Album
	r.set("busy", "ripping "+label)

	// 1. rip
	r.logf("ripper: %s", strings.Join(argv, " "))
	tail, runErr := r.run(ctx, argv, work, func(line string) {
		tr, pct, hasTr, hasPct := parseRipLine(line)
		rp.update(func(q *ripProgress) {
			if hasTr && tr >= 1 && tr <= q.Tracks {
				if tr != q.Track {
					q.Percent = 0
				}
				q.Track = tr
			}
			if hasPct {
				q.Percent = pct
			}
		})
	})
	flacs, logs, _ := ripFiles(work)
	logText := tailString([]byte(tail), logLimit)
	if len(logs) > 0 {
		if b, err := os.ReadFile(logs[0]); err == nil {
			logText = tailString(b, logLimit)
		}
	}
	if rp.reason() == "cancelled" {
		r.set("idle", "")
		return nil, fmt.Errorf("cancelled")
	}
	if runErr != nil {
		r.set("error", "the rip failed")
		return nil, fmt.Errorf("ripper_failed: %v: %s", runErr, tailString([]byte(tail), 200))
	}
	if len(flacs) != len(p.Meta.Tracks) {
		r.set("error", "the rip is incomplete")
		return nil, fmt.Errorf("incomplete_rip: found %d of %d tracks", len(flacs), len(p.Meta.Tracks))
	}

	// 2. check that the tags are there, then upload
	var warnings []string
	for _, f := range flacs {
		tags, err := readFlacTags(f)
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("%s: tags unreadable (%v)", filepath.Base(f), err))
			continue
		}
		if !strings.EqualFold(tags["MUSICBRAINZ_ALBUMID"], p.ReleaseMBID) {
			warnings = append(warnings, fmt.Sprintf("%s: no MUSICBRAINZ_ALBUMID tag; the server links the CD from the job instead", filepath.Base(f)))
		}
	}

	rp.update(func(q *ripProgress) { q.Phase, q.Track, q.Percent = "uploading", 0, 0 })
	r.set("busy", "uploading "+label)
	uploaded, err := r.upload(ctx, api, work, flacs, rp)
	if rp.reason() == "cancelled" {
		r.set("idle", "")
		return nil, fmt.Errorf("cancelled")
	}
	if err != nil {
		r.set("error", "the upload failed")
		return nil, fmt.Errorf("upload_failed: %v", err)
	}

	r.set("idle", "")
	return map[string]any{
		"tracks": len(flacs), "uploaded": uploaded, "log": logText, "warnings": warnings,
		"disc_id": p.DiscID, "release_mbid": strings.ToLower(p.ReleaseMBID),
	}, nil
}

type uploadReceipt struct {
	Name    string `json:"name"`
	FileID  int    `json:"file_id"`
	TrackID int    `json:"track_id"`
}

// upload sends the files one by one. A marker file in the rip's directory remembers what the server confirmed, so a
// retry of the phase only sends the rest; the server also keeps one copy of identical bytes, so a repeat is harmless.
func (r *ripper) upload(ctx context.Context, api *API, work string, files []string, rp *reporter) ([]uploadReceipt, error) {
	marker := filepath.Join(work, ".uploaded")
	done := map[string]uploadReceipt{}
	if b, err := os.ReadFile(marker); err == nil {
		for _, line := range strings.Split(strings.TrimSpace(string(b)), "\n") {
			var u uploadReceipt
			if json.Unmarshal([]byte(line), &u) == nil && u.Name != "" {
				done[u.Name] = u
			}
		}
	}

	var out []uploadReceipt
	for i, f := range files {
		name := filepath.Base(f)
		rp.update(func(q *ripProgress) { q.Track = i + 1 })
		if u, ok := done[name]; ok {
			out = append(out, u)
			continue
		}
		var lastErr error
		for attempt := 0; attempt < 5; attempt++ {
			if ctx.Err() != nil {
				return out, ctx.Err()
			}
			rec, err := api.uploadFile(ctx, f)
			if err == nil {
				u := uploadReceipt{Name: name, FileID: rec.File.ID, TrackID: rec.TrackID}
				out = append(out, u)
				if mf, e := os.OpenFile(marker, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); e == nil {
					b, _ := json.Marshal(u)
					mf.Write(append(b, '\n'))
					mf.Close()
				}
				lastErr = nil
				break
			}
			lastErr = err
			if !retryable(err) {
				return out, err
			}
			select {
			case <-ctx.Done():
				return out, ctx.Err()
			case <-time.After(time.Duration(1<<attempt) * time.Second):
			}
		}
		if lastErr != nil {
			return out, fmt.Errorf("%s: %v", name, lastErr)
		}
	}
	return out, nil
}

// retryable says whether an upload error is worth another try: a network error, a 5xx, 408 or 429. The rest (403 no
// rip job, 422 not audio, 401) will not change by trying again.
func retryable(err error) bool {
	var he *httpError
	if errors.As(err, &he) {
		return he.Status >= 500 || he.Status == 408 || he.Status == 429
	}
	return !errors.Is(err, errUnauthorized)
}
