package main

import (
	"context"
	"encoding/binary"
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
)

// Nothing here touches a CD drive or runs a real cyanrip: the drive is a fake tocReader, cyanrip is a script that runs
// this test binary as a fake (TestFakeCyanrip), the server is httptest.

const (
	testRelease = "a060c352-c9d6-4717-aab8-95a2965fa8d7"
	testJob     = "01HZX3J5K6M7N8P9Q0R1S2T3V4"
)

var testTOC = &TOC{First: 1, Last: 3, Offsets: []int{150, 15000, 30000}, Leadout: 40000, Audio: true}

func okParams() *ripParams {
	p := &ripParams{ReleaseMBID: testRelease, DiscID: testTOC.discID()}
	p.Meta.Album, p.Meta.Artist, p.Meta.Barcode = "Fiction", "Dark Tranquillity", "0602537531159"
	y := 2005
	p.Meta.Year = &y
	for i, t := range []string{"Fiction", "Focus Shift", "Terminus"} {
		p.Meta.Tracks = append(p.Meta.Tracks, struct {
			Number int     `json:"number"`
			Title  string  `json:"title"`
			Artist *string `json:"artist"`
		}{Number: i + 1, Title: t})
	}
	return p
}

func TestDiscIDMatchesTheSpecification(t *testing.T) {
	// Vectors computed with an independent implementation of https://musicbrainz.org/doc/Disc_ID_Calculation (Python,
	// hashlib), not with a real disc.
	if got := testTOC.discID(); got != "s8_l.7d8nyVj4U19Pe8zcsDOing-" {
		t.Fatalf("disc id %q", got)
	}
	long := &TOC{First: 1, Last: 15, Leadout: 258725, Audio: true, Offsets: []int{150, 17510, 33275, 45910, 57805, 78310, 94650, 109580, 132010, 149000, 165920, 183905, 200740, 220205, 238100}}
	if got := long.discID(); got != "9u4TTf9gQs.9IVSJgrcfTnaSXl4-" {
		t.Fatalf("disc id %q", got)
	}
	if !discIDRe.MatchString(testTOC.discID()) {
		t.Fatal("a disc id must pass the server's own pattern")
	}
	if testTOC.tocString() != "1 3 40000 150 15000 30000" {
		t.Fatalf("toc string %q", testTOC.tocString())
	}
}

func TestTOCValidity(t *testing.T) {
	bad := []*TOC{
		{First: 0, Last: 1, Offsets: []int{150}, Leadout: 1000},
		{First: 1, Last: 2, Offsets: []int{150}, Leadout: 1000},
		{First: 1, Last: 2, Offsets: []int{150, 100}, Leadout: 1000},
		{First: 1, Last: 1, Offsets: []int{150}, Leadout: 150},
		{First: 2, Last: 1, Leadout: 1000},
	}
	for i, b := range bad {
		if b.valid() {
			t.Errorf("case %d must be invalid", i)
		}
	}
	if !testTOC.valid() {
		t.Fatal("the test TOC is valid")
	}
}

func TestRipArgsAreAnArgumentArrayWithTheReleaseAsTags(t *testing.T) {
	argv, err := ripArgs(RipperConfig{Device: "/dev/sr0"}, okParams())
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(argv[:7], " ") != "ionice -c 3 nice -n 15 cyanrip" {
		t.Fatalf("ionice and nice come first: %v", argv[:7])
	}
	joined := strings.Join(argv, "\x00")
	for _, want := range []string{"-d\x00/dev/sr0", "-o\x00flac", "-N",
		"MUSICBRAINZ_ALBUMID=" + testRelease, "BARCODE=0602537531159", "album=Fiction", "album_artist=Dark Tranquillity", "date=2005",
		"3=title=Terminus:artist=Dark Tranquillity"} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %q", want, strings.ReplaceAll(joined, "\x00", " "))
		}
	}

	zero, off := 0, 6
	argv, _ = ripArgs(RipperConfig{Device: "/dev/sr1", Cyanrip: "/opt/cyanrip", Nice: &zero, NoIonice: true, Offset: &off, Retries: 8}, okParams())
	if argv[0] != "/opt/cyanrip" || strings.Join(argv[1:3], " ") != "-d /dev/sr1" || !strings.Contains(strings.Join(argv, " "), "-s 6 -r 8") {
		t.Fatalf("no wrappers, offset and retries: %v", argv)
	}
}

func TestRipArgsEscapeTagValuesAndRefuseAFreeDevice(t *testing.T) {
	p := okParams()
	p.Meta.Tracks[0].Title = `A: B = C \ D`
	argv, _ := ripArgs(RipperConfig{Device: "/dev/sr0", NoIonice: true}, p)
	if !strings.Contains(strings.Join(argv, "\x00"), `1=title=A\: B \= C \\ D:artist=`) {
		t.Fatalf("title not escaped: %q", argv)
	}
	for _, dev := range []string{"", "sr0", "/dev/sr0; rm -rf /", "/etc/passwd", "/dev/../etc/passwd", "/dev/sr0 -x"} {
		if _, err := ripArgs(RipperConfig{Device: dev}, okParams()); err == nil {
			t.Errorf("device %q must be refused", dev)
		}
	}
}

func TestRipParamsAreValidated(t *testing.T) {
	if err := okParams().validate(); err != nil {
		t.Fatal(err)
	}
	mut := map[string]func(p *ripParams){
		"release":    func(p *ripParams) { p.ReleaseMBID = "x" },
		"disc id":    func(p *ripParams) { p.DiscID = "short" },
		"album":      func(p *ripParams) { p.Meta.Album = "" },
		"control":    func(p *ripParams) { p.Meta.Artist = "a\nb" },
		"barcode":    func(p *ripParams) { p.Meta.Barcode = "12" },
		"year":       func(p *ripParams) { y := 5; p.Meta.Year = &y },
		"no tracks":  func(p *ripParams) { p.Meta.Tracks = nil },
		"numbering":  func(p *ripParams) { p.Meta.Tracks[1].Number = 5 },
		"long title": func(p *ripParams) { p.Meta.Tracks[2].Title = strings.Repeat("x", 301) },
	}
	for name, f := range mut {
		p := okParams()
		f(p)
		if p.validate() == nil {
			t.Errorf("%s must be refused", name)
		}
	}
}

func TestParseRipLine(t *testing.T) {
	cases := []struct {
		line   string
		tr     int
		pct    float64
		hT, hP bool
	}{
		{"Ripping and encoding track 3 of 12", 3, 0, true, false},
		{"  Progress - 45.5%", 0, 45.5, false, true},
		{"track 2: 80% done", 2, 80, true, true},
		{"nothing to see", 0, 0, false, false},
		{"loaded 250% of cache", 0, 0, false, false},
	}
	for _, c := range cases {
		tr, pct, hT, hP := parseRipLine(c.line)
		if tr != c.tr || pct != c.pct || hT != c.hT || hP != c.hP {
			t.Errorf("%q: got %d %v %v %v", c.line, tr, pct, hT, hP)
		}
	}
}

// writeFlac writes a minimal FLAC file: STREAMINFO and a last Vorbis comment block with the tags.
func writeFlac(path string, tags map[string]string) error {
	var vc []byte
	le := func(n int) []byte { b := make([]byte, 4); binary.LittleEndian.PutUint32(b, uint32(n)); return b }
	vendor := "fake"
	vc = append(vc, le(len(vendor))...)
	vc = append(vc, vendor...)
	vc = append(vc, le(len(tags))...)
	for k, v := range tags {
		c := k + "=" + v
		vc = append(vc, le(len(c))...)
		vc = append(vc, c...)
	}
	b := []byte("fLaC")
	b = append(b, 0x00, 0, 0, 34)
	b = append(b, make([]byte, 34)...)
	b = append(b, 0x84, byte(len(vc)>>16), byte(len(vc)>>8), byte(len(vc)))
	b = append(b, vc...)
	b = append(b, "audio"...)
	return os.WriteFile(path, b, 0o644)
}

func TestReadFlacTags(t *testing.T) {
	f := filepath.Join(t.TempDir(), "a.flac")
	if err := writeFlac(f, map[string]string{"musicbrainz_albumid": testRelease, "TITLE": "x = y"}); err != nil {
		t.Fatal(err)
	}
	tags, err := readFlacTags(f)
	if err != nil || tags["MUSICBRAINZ_ALBUMID"] != testRelease || tags["TITLE"] != "x = y" {
		t.Fatalf("tags %v %v", tags, err)
	}
	os.WriteFile(f, []byte("RIFFxxxx"), 0o644)
	if _, err := readFlacTags(f); err == nil {
		t.Fatal("not a FLAC")
	}
}

// ---- the fake cyanrip: this test binary, run through a one-line script -----------------------------------------------

// TestFakeCyanrip is the fake ripper. It only runs when the script below starts it (FAKE_CYANRIP set); FAKE_MODE says
// how it behaves: ok, fail, incomplete (one track short), untagged, slow (never ends by itself).
func TestFakeCyanrip(t *testing.T) {
	if os.Getenv("FAKE_CYANRIP") == "" {
		t.Skip("only run through the fake cyanrip script")
	}
	args := os.Args
	for i, a := range args {
		if a == "--" {
			args = args[i+1:]
			break
		}
	}
	var album string
	var tracks []string
	for i := 0; i < len(args)-1; i++ {
		switch args[i] {
		case "-a":
			album = args[i+1]
		case "-t":
			tracks = append(tracks, args[i+1])
		}
	}
	if marker := os.Getenv("FAKE_ARGS_FILE"); marker != "" {
		os.WriteFile(marker, []byte(strings.Join(args, "\n")), 0o644)
	}
	mode := os.Getenv("FAKE_MODE")
	n := len(tracks)
	if mode == "incomplete" {
		n--
	}
	mbid := ""
	for _, kv := range strings.Split(album, ":") {
		if strings.HasPrefix(kv, "MUSICBRAINZ_ALBUMID=") {
			mbid = strings.TrimPrefix(kv, "MUSICBRAINZ_ALBUMID=")
		}
	}
	dir := "Dark Tranquillity - Fiction (2005)"
	os.MkdirAll(dir, 0o755)
	for i := 1; i <= n; i++ {
		fmt.Printf("Ripping and encoding track %d of %d\n", i, len(tracks))
		fmt.Printf("  Progress - 50%%\r  Progress - 100%%\n")
		tags := map[string]string{"TITLE": fmt.Sprintf("track %d", i), "MUSICBRAINZ_ALBUMID": mbid}
		if mode == "untagged" {
			delete(tags, "MUSICBRAINZ_ALBUMID")
		}
		writeFlac(filepath.Join(dir, fmt.Sprintf("%02d - track.flac", i)), tags)
		if mode == "slow" && i == 1 {
			time.Sleep(time.Minute)
		}
	}
	os.WriteFile(filepath.Join(dir, "rip.log"), []byte("cyanrip fake log\nAccurateRip: all tracks match\n"), 0o644)
	if mode == "fail" {
		fmt.Fprintln(os.Stderr, "read error on track 2")
		os.Exit(3)
	}
}

// fakeCyanrip makes a script that runs TestFakeCyanrip of this test binary and returns its path.
func fakeCyanrip(t *testing.T, mode string) (script, argsFile string) {
	t.Helper()
	exe, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	script = filepath.Join(dir, "cyanrip")
	body := fmt.Sprintf("#!/bin/sh\nexec %q -test.run='^TestFakeCyanrip$' -- \"$@\"\n", exe)
	if err := os.WriteFile(script, []byte(body), 0o755); err != nil {
		t.Fatal(err)
	}
	argsFile = filepath.Join(dir, "args.txt")
	t.Setenv("FAKE_CYANRIP", "1")
	t.Setenv("FAKE_MODE", mode)
	t.Setenv("FAKE_ARGS_FILE", argsFile)
	return script, argsFile
}

// ---- a fake server for progress and upload ---------------------------------------------------------------------------

type ripServer struct {
	mu        sync.Mutex
	progress  []map[string]any
	uploads   []string
	failOnce  map[string]int // file name -> how many 503 answers before it works
	cancelAt  int            // cancel_requested true from the n-th progress call on (0: never)
	noRipJob  bool
	hits      int
	calls     map[string]int
	results   map[string]map[string]any
	accepted  bool
	tokenSeen string
}

func (s *ripServer) handler() http.Handler {
	mux := http.NewServeMux()
	reply := func(w http.ResponseWriter, code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		json.NewEncoder(w).Encode(v)
	}
	mux.HandleFunc("/api/nodes/files", func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		s.tokenSeen = r.Header.Get("Authorization")
		s.hits++
		if s.noRipJob {
			reply(w, 403, map[string]any{"error": "no_rip_job"})
			return
		}
		if err := r.ParseMultipartForm(32 << 20); err != nil {
			reply(w, 400, map[string]any{"error": "bad"})
			return
		}
		_, hdr, err := r.FormFile("file")
		if err != nil {
			reply(w, 422, map[string]any{"error": "no file"})
			return
		}
		if s.calls == nil {
			s.calls = map[string]int{}
		}
		s.calls[hdr.Filename]++
		if s.failOnce[hdr.Filename] >= s.calls[hdr.Filename] {
			reply(w, 503, map[string]any{"error": "busy"})
			return
		}
		s.uploads = append(s.uploads, hdr.Filename)
		reply(w, 201, map[string]any{"file": map[string]any{"id": 100 + len(s.uploads)}, "track_id": 200 + len(s.uploads)})
	})
	mux.HandleFunc("/api/nodes/jobs/", func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		path := strings.TrimPrefix(r.URL.Path, "/api/nodes/jobs/")
		switch {
		case strings.HasSuffix(path, "/accept"):
			s.accepted = true
			reply(w, 200, map[string]any{})
		case strings.HasSuffix(path, "/progress"):
			var body map[string]any
			json.NewDecoder(r.Body).Decode(&body)
			if p, ok := body["progress"].(map[string]any); ok {
				s.progress = append(s.progress, p)
			}
			reply(w, 200, map[string]any{"cancel_requested": s.cancelAt > 0 && len(s.progress) >= s.cancelAt})
		case strings.HasSuffix(path, "/result"):
			var body map[string]any
			json.NewDecoder(r.Body).Decode(&body)
			if s.results == nil {
				s.results = map[string]map[string]any{}
			}
			s.results[strings.TrimSuffix(path, "/result")] = body
			reply(w, 200, map[string]any{})
		}
	})
	return mux
}

func newTestRipper(t *testing.T, mode string, toc *TOC) (*ripper, *API, *ripServer, string) {
	t.Helper()
	script, _ := fakeCyanrip(t, mode)
	srv := &ripServer{failOnce: map[string]int{}}
	ts := httptest.NewServer(srv.handler())
	t.Cleanup(ts.Close)
	api := newAPI(ts.URL)
	api.token = "tok1"
	temp := t.TempDir()
	zero := 0
	r := newRipper(RipperConfig{Device: "/dev/sr0", Cyanrip: script, TempDir: temp, Nice: &zero, NoIonice: true}, t.TempDir(), t.Logf)
	r.every = 30 * time.Millisecond
	r.toc = func(string) (*TOC, error) {
		if toc == nil {
			return nil, errNoDisc
		}
		return toc, nil
	}
	return r, api, srv, temp
}

func entries(t *testing.T, dir string) []string {
	es, _ := os.ReadDir(dir)
	var names []string
	for _, e := range es {
		names = append(names, e.Name())
	}
	return names
}

func TestRipRipsUploadsAndCleansUp(t *testing.T) {
	r, api, srv, temp := newTestRipper(t, "ok", testTOC)

	res, err := r.rip(api, testJob, okParams())
	if err != nil {
		t.Fatal(err)
	}
	if res["tracks"] != 3 || !strings.Contains(res["log"].(string), "AccurateRip: all tracks match") {
		t.Fatalf("result %v", res)
	}
	if len(res["warnings"].([]string)) != 0 {
		t.Fatalf("warnings %v", res["warnings"])
	}
	up := res["uploaded"].([]uploadReceipt)
	if len(up) != 3 || up[0].FileID != 101 || up[2].TrackID != 203 {
		t.Fatalf("uploaded %+v", up)
	}
	if strings.Join(srv.uploads, ",") != "01 - track.flac,02 - track.flac,03 - track.flac" {
		t.Fatalf("uploads %v", srv.uploads)
	}
	if srv.tokenSeen != "Bearer tok1" {
		t.Fatalf("upload auth %q", srv.tokenSeen)
	}
	if got := entries(t, temp); len(got) != 0 {
		t.Fatalf("the temp dir must be clean, has %v", got)
	}
	var phases []string
	for _, p := range srv.progress {
		phases = append(phases, p["phase"].(string))
	}
	if len(srv.progress) == 0 {
		t.Log("no progress tick happened (the fake is quick)")
	}
	for _, p := range phases {
		if p != "ripping" && p != "uploading" {
			t.Fatalf("phase %q", p)
		}
	}
	if st := r.roleState(); st.State != "idle" {
		t.Fatalf("role state %+v", st)
	}
}

func TestTheRipperGetsTheTagsAndNoShell(t *testing.T) {
	r, api, _, _ := newTestRipper(t, "ok", testTOC)
	_, argsFile := fakeCyanrip(t, "ok")
	script, _ := fakeCyanrip(t, "ok")
	r.cfg.Cyanrip = script
	t.Setenv("FAKE_ARGS_FILE", argsFile)

	if _, err := r.rip(api, testJob, okParams()); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(argsFile)
	args := string(b)
	for _, want := range []string{"-d\n/dev/sr0", "-N", "MUSICBRAINZ_ALBUMID=" + testRelease, "BARCODE=0602537531159"} {
		if !strings.Contains(args, want) {
			t.Errorf("cyanrip did not get %q: %q", want, args)
		}
	}
}

func TestMissingTagsAreAWarningNotAFailure(t *testing.T) {
	r, api, _, _ := newTestRipper(t, "untagged", testTOC)
	res, err := r.rip(api, testJob, okParams())
	if err != nil {
		t.Fatal(err)
	}
	if w := res["warnings"].([]string); len(w) != 3 || !strings.Contains(w[0], "MUSICBRAINZ_ALBUMID") {
		t.Fatalf("warnings %v", w)
	}
}

func TestRipRefusesAnotherDiscAndAnEmptyDriveWithoutRunningTheRipper(t *testing.T) {
	other := &TOC{First: 1, Last: 3, Offsets: []int{150, 16000, 31000}, Leadout: 41000, Audio: true}
	r, api, _, temp := newTestRipper(t, "ok", other)
	if _, err := r.rip(api, testJob, okParams()); err == nil || err.Error() != "disc_changed" {
		t.Fatalf("another disc: %v", err)
	}
	if len(entries(t, temp)) != 0 {
		t.Fatal("nothing may be created for a refused rip")
	}

	r, api, _, _ = newTestRipper(t, "ok", nil)
	if _, err := r.rip(api, testJob, okParams()); err == nil || err.Error() != "no_disc" {
		t.Fatalf("no disc: %v", err)
	}
	data := &TOC{First: 1, Last: 3, Offsets: testTOC.Offsets, Leadout: 40000, Audio: false}
	r, api, _, _ = newTestRipper(t, "ok", data)
	if _, err := r.rip(api, testJob, okParams()); err == nil || err.Error() != "not_audio" {
		t.Fatalf("data disc: %v", err)
	}
	if _, err := r.rip(api, "../../etc", okParams()); err == nil {
		t.Fatal("a job id that is not a ULID must be refused")
	}
}

func TestAFailedAndAnIncompleteRipFailWithTheirReasonAndCleanUp(t *testing.T) {
	r, api, srv, temp := newTestRipper(t, "fail", testTOC)
	_, err := r.rip(api, testJob, okParams())
	if err == nil || !strings.HasPrefix(err.Error(), "ripper_failed") || !strings.Contains(err.Error(), "read error on track 2") {
		t.Fatalf("fail: %v", err)
	}
	if len(srv.uploads) != 0 || len(entries(t, temp)) != 0 {
		t.Fatalf("no upload and a clean temp dir after a failure: %v %v", srv.uploads, entries(t, temp))
	}
	if st := r.roleState(); st.State != "error" {
		t.Fatalf("role state %+v", st)
	}

	r, api, srv, temp = newTestRipper(t, "incomplete", testTOC)
	_, err = r.rip(api, testJob, okParams())
	if err == nil || !strings.HasPrefix(err.Error(), "incomplete_rip: found 2 of 3") {
		t.Fatalf("incomplete: %v", err)
	}
	if len(srv.uploads) != 0 || len(entries(t, temp)) != 0 {
		t.Fatal("an incomplete rip uploads nothing and leaves nothing")
	}
}

func TestAFileThatFailsToUploadIsRetriedAndTheOthersAreNotSentAgain(t *testing.T) {
	r, api, srv, _ := newTestRipper(t, "ok", testTOC)
	srv.failOnce["02 - track.flac"] = 1
	// shorten the wait between attempts: the first retry waits one second; that is fine for one test
	res, err := r.rip(api, testJob, okParams())
	if err != nil {
		t.Fatal(err)
	}
	if len(res["uploaded"].([]uploadReceipt)) != 3 {
		t.Fatalf("uploaded %v", res["uploaded"])
	}
	if srv.calls["01 - track.flac"] != 1 || srv.calls["02 - track.flac"] != 2 || srv.calls["03 - track.flac"] != 1 {
		t.Fatalf("calls %v", srv.calls)
	}
}

func TestAnUploadTheServerRefusesIsNotRetried(t *testing.T) {
	r, api, srv, temp := newTestRipper(t, "ok", testTOC)
	srv.noRipJob = true
	_, err := r.rip(api, testJob, okParams())
	if err == nil || !strings.HasPrefix(err.Error(), "upload_failed") || !strings.Contains(err.Error(), "403") {
		t.Fatalf("refused upload: %v", err)
	}
	if srv.hits != 1 {
		t.Fatalf("a 403 must not be retried: %d hits", srv.hits)
	}
	if len(entries(t, temp)) != 0 {
		t.Fatal("clean up after a failed upload")
	}
}

func TestCancelStopsTheRipperAndCleansUp(t *testing.T) {
	r, api, srv, temp := newTestRipper(t, "slow", testTOC)
	srv.cancelAt = 2
	start := time.Now()
	_, err := r.rip(api, testJob, okParams())
	if err == nil || err.Error() != "cancelled" {
		t.Fatalf("cancel: %v", err)
	}
	if time.Since(start) > 20*time.Second {
		t.Fatal("the fake ripper that sleeps for a minute must have been killed")
	}
	if len(entries(t, temp)) != 0 {
		t.Fatal("clean up after a cancel")
	}
	if len(srv.uploads) != 0 {
		t.Fatal("nothing uploaded after a cancel")
	}
}

func TestOnlyOneRipAtATime(t *testing.T) {
	r, api, _, _ := newTestRipper(t, "ok", testTOC)
	r.busy.Lock()
	defer r.busy.Unlock()
	if _, err := r.rip(api, testJob, okParams()); err == nil || err.Error() != "busy" {
		t.Fatalf("busy: %v", err)
	}
}

func TestStatusReportsTheDisc(t *testing.T) {
	r, _, _, _ := newTestRipper(t, "ok", testTOC)
	res, err := r.status()
	if err != nil || res["present"] != true || res["audio"] != true || res["tracks"] != 3 || res["disc_id"] != testTOC.discID() || res["toc"] != testTOC.tocString() {
		t.Fatalf("status %v %v", res, err)
	}

	r, _, _, _ = newTestRipper(t, "ok", nil)
	if res, err := r.status(); err != nil || res["present"] != false {
		t.Fatalf("empty drive %v %v", res, err)
	}

	data := &TOC{First: 1, Last: 3, Offsets: testTOC.Offsets, Leadout: 40000, Audio: false}
	r, _, _, _ = newTestRipper(t, "ok", data)
	if res, err := r.status(); err != nil || res["audio"] != false || res["disc_id"] != nil {
		t.Fatalf("data disc %v %v", res, err)
	}

	r.toc = func(string) (*TOC, error) { return nil, fmt.Errorf("EIO") }
	if _, err := r.status(); err == nil || !strings.HasPrefix(err.Error(), "drive_error") {
		t.Fatalf("drive error: %v", err)
	}
	r.cfg.Device = ""
	if st := r.roleState(); st.State != "absent" {
		t.Fatalf("no drive: %+v", st)
	}
	if _, err := r.status(); err == nil {
		t.Fatal("no drive configured must fail")
	}
}

func TestCleanStaleRemovesOnlyRipDirectories(t *testing.T) {
	r, _, _, temp := newTestRipper(t, "ok", testTOC)
	os.MkdirAll(filepath.Join(temp, "rip-"+testJob, "sub"), 0o755)
	os.WriteFile(filepath.Join(temp, "rip-"+testJob, "sub", "a.flac"), []byte("x"), 0o644)
	os.MkdirAll(filepath.Join(temp, "keep-me"), 0o755)
	os.MkdirAll(filepath.Join(temp, "rip-notaulid"), 0o755)
	os.WriteFile(filepath.Join(temp, "rip-"+testJob+".txt"), []byte("x"), 0o644)

	r.cleanStale()

	got := strings.Join(entries(t, temp), ",")
	if got != "keep-me,rip-"+testJob+".txt,rip-notaulid" {
		t.Fatalf("left: %s", got)
	}
}

func TestJobsRunThroughTheNodeAndReportOverRest(t *testing.T) {
	r, api, srv, _ := newTestRipper(t, "ok", testTOC)
	n := &Node{cfg: &Config{}, api: api, ripper: r, started: time.Now(), nudge: make(chan struct{}, 1)}

	statusJob := &Job{ID: testJob[:25] + "A", Type: "ripper.status", Params: json.RawMessage(`{}`)}
	n.runJob(statusJob)
	if res := srv.results[statusJob.ID]; res["ok"] != true || res["result"].(map[string]any)["disc_id"] != testTOC.discID() {
		t.Fatalf("status result %v", srv.results[statusJob.ID])
	}

	params, _ := json.Marshal(okParams())
	ripJob := &Job{ID: testJob, Type: "ripper.rip", Params: params}
	n.runJob(ripJob)
	res := srv.results[testJob]
	if res["ok"] != true || res["result"].(map[string]any)["tracks"] != float64(3) {
		t.Fatalf("rip result %v", res)
	}
	if n.job != nil {
		t.Fatal("the current job must be cleared")
	}

	// a rip with bad params is a failure with a reason, nothing runs
	bad := &Job{ID: "01HZX3J5K6M7N8P9Q0R1S2T3V5", Type: "ripper.rip", Params: json.RawMessage(`{"release_mbid":"x"}`)}
	n.runJob(bad)
	if res := srv.results[bad.ID]; res["ok"] != false || res["error"] != "invalid_release" {
		t.Fatalf("bad params %v", res)
	}

	// without a configured drive both types fail the same way
	n.ripper = newRipper(RipperConfig{}, t.TempDir(), t.Logf)
	for _, typ := range []string{"ripper.status", "ripper.rip"} {
		j := &Job{ID: "01HZX3J5K6M7N8P9Q0R1S2T3V" + strconv.Itoa(len(typ)%10), Type: typ, Params: params}
		n.runJob(j)
		if res := srv.results[j.ID]; res["ok"] != false || res["error"] != "no_drive_configured" {
			t.Fatalf("%s without a drive: %v", typ, res)
		}
	}
}

func TestExecRipperCancelsViaContext(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	start := time.Now()
	_, err := execRipper(ctx, []string{"sleep", "30"}, t.TempDir(), func(string) {})
	if err == nil || time.Since(start) > 10*time.Second {
		t.Fatalf("sleep must be killed: %v after %v", err, time.Since(start))
	}
	tail, err := execRipper(context.Background(), []string{"sh", "-c", "printf 'a\\rb\\n'; echo err >&2"}, t.TempDir(), func(string) {})
	if err != nil || !strings.Contains(tail, "a") || !strings.Contains(tail, "err") {
		t.Fatalf("output %q %v", tail, err)
	}
	_ = io.Discard
}
