package main

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"
)

var okTarget = IcecastTarget{Host: "192.168.1.5", Port: 8000, Mount: "linein-7.mp3", Password: "p@ss/word"}

func TestCaptureCommandIsAnArgumentArrayWithNiceAndEscapedPassword(t *testing.T) {
	argv, err := captureCommand(LineInConfig{Device: "hw:1,0"}, okTarget)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(argv[:4], " ") != "nice -n 10 ffmpeg" {
		t.Fatalf("expected nice before ffmpeg: %v", argv[:4])
	}
	joined := strings.Join(argv, " ")
	for _, want := range []string{"-f alsa", "-i hw:1,0", "-c:a libmp3lame", "-b:a 128k", "-ar 44100", "icecast://source:p%40ss%2Fword@192.168.1.5:8000/linein-7.mp3"} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %s", want, joined)
		}
	}
	if strings.Contains(redact(argv), "p%40ss") {
		t.Fatalf("password leaked: %s", redact(argv))
	}
	zero := 0
	argv, _ = captureCommand(LineInConfig{Device: "hw:1,0", Nice: &zero, Bitrate: 192, Rate: 48000}, okTarget)
	if argv[0] != "ffmpeg" || !strings.Contains(strings.Join(argv, " "), "-b:a 192k") {
		t.Fatalf("nice 0 / bitrate: %v", argv)
	}
}

func TestCaptureCommandRefusesAnythingOddInDeviceMountHostOrPassword(t *testing.T) {
	bad := []struct {
		c LineInConfig
		t IcecastTarget
	}{
		{LineInConfig{Device: ""}, okTarget},
		{LineInConfig{Device: "hw:1,0; rm -rf /"}, okTarget},
		{LineInConfig{Device: "hw:1,0"}, IcecastTarget{Host: "h", Port: 8000, Mount: "../../etc/passwd", Password: "x"}},
		{LineInConfig{Device: "hw:1,0"}, IcecastTarget{Host: "h", Port: 8000, Mount: "relay-1.mp3", Password: "x"}},
		{LineInConfig{Device: "hw:1,0"}, IcecastTarget{Host: "h/x@evil", Port: 8000, Mount: "linein-1.mp3", Password: "x"}},
		{LineInConfig{Device: "hw:1,0"}, IcecastTarget{Host: "h", Port: 0, Mount: "linein-1.mp3", Password: "x"}},
		{LineInConfig{Device: "hw:1,0"}, IcecastTarget{Host: "h", Port: 8000, Mount: "linein-1.mp3", Password: ""}},
		{LineInConfig{Device: "hw:1,0", Bitrate: 5000}, okTarget},
	}
	for i, b := range bad {
		if _, err := captureCommand(b.c, b.t); err == nil {
			t.Errorf("case %d must be refused", i)
		}
	}
}

func TestBackoffGrowsAndCaps(t *testing.T) {
	want := []time.Duration{2, 4, 8, 16, 30, 30}
	for i, w := range want {
		if got := backoff(i + 1); got != w*time.Second {
			t.Errorf("attempt %d: %v", i+1, got)
		}
	}
}

// fakeRun fails the first `fail` runs at once, then blocks until cancelled.
type fakeRun struct {
	mu    sync.Mutex
	calls int
	fail  int
}

func (f *fakeRun) run(ctx context.Context, argv []string) error {
	f.mu.Lock()
	f.calls++
	n := f.calls
	f.mu.Unlock()
	if n <= f.fail {
		return errors.New("alsa: device busy")
	}
	<-ctx.Done()
	return nil
}

func (f *fakeRun) count() int { f.mu.Lock(); defer f.mu.Unlock(); return f.calls }

func waitFor(t *testing.T, cond func() bool) {
	t.Helper()
	for i := 0; i < 200; i++ {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("condition not met")
}

func TestSupervisorRestartsWithBackoffReportsErrorAndStops(t *testing.T) {
	fr := &fakeRun{fail: 2}
	var waits []time.Duration
	var wmu sync.Mutex
	l := newLineIn(LineInConfig{Device: "hw:1,0"}, func(string, ...any) {})
	l.run = fr.run
	l.sleep = func(ctx context.Context, d time.Duration) { wmu.Lock(); waits = append(waits, d); wmu.Unlock() }

	if err := l.Start(okTarget); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { return fr.count() == 3 })
	waitFor(t, func() bool { return l.roleState().State == "busy" })
	wmu.Lock()
	if len(waits) != 2 || waits[0] != 2*time.Second || waits[1] != 4*time.Second {
		t.Fatalf("backoff waits: %v", waits)
	}
	wmu.Unlock()

	// the same target again is a no-op, not a second capture
	_ = l.Start(okTarget)
	if fr.count() != 3 {
		t.Fatalf("restarted on an identical start: %d", fr.count())
	}
	l.Stop()
	if st := l.roleState(); st.State != "idle" || l.running() {
		t.Fatalf("after stop: %+v", st)
	}
	l.Stop() // safe twice
}

func TestSupervisorShowsTheErrorWhileWaiting(t *testing.T) {
	fr := &fakeRun{fail: 100}
	l := newLineIn(LineInConfig{Device: "hw:1,0"}, func(string, ...any) {})
	l.run = fr.run
	block := make(chan struct{})
	l.sleep = func(ctx context.Context, d time.Duration) { <-block }
	_ = l.Start(okTarget)
	waitFor(t, func() bool { return l.roleState().State == "error" })
	if d := l.roleState().Detail; !strings.Contains(d, "device busy") || strings.Contains(d, "p%40ss") {
		t.Fatalf("detail: %s", d)
	}
	close(block)
	l.Stop()
}

func TestLineInJobsUseConfigDeviceNeverTheJobs(t *testing.T) {
	fr := &fakeRun{}
	var got [][]string
	n := &Node{cfg: &Config{Server: "http://192.168.1.9:8080", LineIn: LineInConfig{Device: "hw:2,0"}}}
	n.lineIn = newLineIn(n.cfg.LineIn, func(string, ...any) {})
	n.lineIn.run = func(ctx context.Context, argv []string) error { got = append(got, argv); return fr.run(ctx, argv) }

	params := json.RawMessage(`{"mount":"linein-7.mp3","icecast_port":8000,"source_password":"s3","device":"hw:9,9","host":"evil"}`)
	res, err := handlers["line_in.start"](n, params)
	if err != nil || res["mount"] != "linein-7.mp3" {
		t.Fatalf("start: %v %v", err, res)
	}
	waitFor(t, func() bool { return fr.count() == 1 })
	joined := strings.Join(got[0], " ")
	if !strings.Contains(joined, "-i hw:2,0") || strings.Contains(joined, "hw:9,9") || strings.Contains(joined, "evil") || !strings.Contains(joined, "@192.168.1.9:8000/") {
		t.Fatalf("command: %s", joined)
	}
	if _, err := handlers["line_in.stop"](n, nil); err != nil || n.lineIn.running() {
		t.Fatalf("stop: %v", err)
	}
	if _, err := handlers["line_in.start"](n, json.RawMessage(`{"mount":"x","icecast_port":8000,"source_password":"s"}`)); err == nil {
		t.Fatal("a bad mount must be refused")
	}
	n.cfg.LineIn.Device = ""
	n.lineIn = newLineIn(n.cfg.LineIn, func(string, ...any) {})
	if _, err := handlers["line_in.start"](n, params); err == nil || err.Error() != "no_input_configured" {
		t.Fatalf("no device: %v", err)
	}
}

func TestCapabilitiesDeclareTheConfiguredInput(t *testing.T) {
	n := &Node{cfg: &Config{LineIn: LineInConfig{Device: "hw:1,0", Label: "USB audio"}}}
	in := n.capabilities()["line-in"].(map[string]any)["inputs"].([]map[string]any)
	if in[0]["id"] != "hw:1,0" || in[0]["label"] != "USB audio" {
		t.Fatalf("%v", in)
	}
	if len((&Node{cfg: &Config{}}).capabilities()) != 0 {
		t.Fatal("no device, no capability")
	}
}
