package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Line-in role (workstream 18.2, docs/architecture/node-agent.md section 6): ffmpeg captures one ALSA device chosen
// in the node's own config and pushes MP3 to the server's Icecast mount. The device, the Icecast host and the program
// path come from the config; a job only names the server-issued mount, the Icecast port and the source password.

// LineInConfig is the `line_in` object of the node config.
type LineInConfig struct {
	// Device is the ALSA capture device, e.g. "hw:1,0" or "plughw:CARD=USB,DEV=0". Never taken from a job.
	Device string `json:"device"`
	// Label is shown in the node's capabilities.
	Label string `json:"label"`
	// Rate in Hz (default 44100), Bitrate in kbit/s (default 128).
	Rate    int `json:"rate"`
	Bitrate int `json:"bitrate"`
	// Nice level of ffmpeg (default 10, 0 = no nice).
	Nice *int `json:"nice"`
	// FFmpeg is the program (default "ffmpeg"); IcecastHost the host of Icecast (default: the host of `server`).
	FFmpeg      string `json:"ffmpeg"`
	IcecastHost string `json:"icecast_host"`
}

// IcecastTarget is where the stream goes.
type IcecastTarget struct {
	Host     string
	Port     int
	Mount    string
	Password string
}

var (
	alsaDeviceRe = regexp.MustCompile(`^[A-Za-z0-9_:,.=\-]{1,64}$`)
	mountRe      = regexp.MustCompile(`^linein-[0-9]{1,9}\.mp3$`)
	hostRe       = regexp.MustCompile(`^[A-Za-z0-9.\-]{1,253}$|^\[[0-9A-Fa-f:]+\]$`)
)

// captureCommand builds the argument array of the capture; pure, so it is tested without ffmpeg or audio hardware.
// It never goes through a shell.
func captureCommand(c LineInConfig, t IcecastTarget) ([]string, error) {
	if !alsaDeviceRe.MatchString(c.Device) {
		return nil, fmt.Errorf("invalid_device")
	}
	if !mountRe.MatchString(t.Mount) {
		return nil, fmt.Errorf("invalid_mount")
	}
	if !hostRe.MatchString(t.Host) || t.Port < 1 || t.Port > 65535 {
		return nil, fmt.Errorf("invalid_target")
	}
	if t.Password == "" || len(t.Password) > 200 || strings.ContainsAny(t.Password, "\r\n\x00") {
		return nil, fmt.Errorf("invalid_password")
	}
	rate, bitrate := c.Rate, c.Bitrate
	if rate == 0 {
		rate = 44100
	}
	if bitrate == 0 {
		bitrate = 128
	}
	if rate < 8000 || rate > 192000 || bitrate < 32 || bitrate > 320 {
		return nil, fmt.Errorf("invalid_audio_settings")
	}
	ffmpeg := c.FFmpeg
	if ffmpeg == "" {
		ffmpeg = "ffmpeg"
	}
	nice := 10
	if c.Nice != nil {
		nice = *c.Nice
	}
	target := fmt.Sprintf("icecast://source:%s@%s:%d/%s", url.QueryEscape(t.Password), t.Host, t.Port, t.Mount)

	var argv []string
	if nice > 0 {
		argv = append(argv, "nice", "-n", strconv.Itoa(nice))
	}
	argv = append(argv,
		ffmpeg, "-nostdin", "-hide_banner", "-loglevel", "error",
		"-f", "alsa", "-ac", "2", "-ar", strconv.Itoa(rate), "-i", c.Device,
		"-vn", "-c:a", "libmp3lame", "-b:a", strconv.Itoa(bitrate)+"k", "-ar", "44100",
		"-f", "mp3", "-content_type", "audio/mpeg", target,
	)
	return argv, nil
}

// redact hides the source password of an argument list for logs and status.
func redact(argv []string) string {
	out := make([]string, len(argv))
	for i, a := range argv {
		if strings.HasPrefix(a, "icecast://source:") {
			if at := strings.LastIndex(a, "@"); at > 0 {
				a = "icecast://source:***" + a[at:]
			}
		}
		out[i] = a
	}
	return strings.Join(out, " ")
}

// backoff is the wait before restart number attempt (1-based): 2, 4, 8, 16, 30 seconds.
func backoff(attempt int) time.Duration {
	if attempt < 1 {
		attempt = 1
	}
	if attempt > 5 {
		attempt = 5
	}
	d := time.Duration(1<<attempt) * time.Second
	if d > 30*time.Second {
		d = 30 * time.Second
	}
	return d
}

// runFunc runs one capture until it ends or ctx is cancelled; the tail of its stderr comes back in the error.
type runFunc func(ctx context.Context, argv []string) error

func execRun(ctx context.Context, argv []string) error {
	cmd := exec.CommandContext(ctx, argv[0], argv[1:]...)
	var tail tailBuffer
	cmd.Stderr = &tail
	err := cmd.Run()
	if ctx.Err() != nil {
		return nil
	}
	if err == nil {
		return fmt.Errorf("ffmpeg ended")
	}
	if t := tail.String(); t != "" {
		return fmt.Errorf("%v: %s", err, t)
	}
	return err
}

// tailBuffer keeps the last 300 bytes written.
type tailBuffer struct{ b []byte }

func (t *tailBuffer) Write(p []byte) (int, error) {
	t.b = append(t.b, p...)
	if len(t.b) > 300 {
		t.b = t.b[len(t.b)-300:]
	}
	return len(p), nil
}
func (t *tailBuffer) String() string { return strings.TrimSpace(string(t.b)) }

// lineIn supervises the one capture of this node: restart with backoff while wanted, a status for the heartbeat.
type lineIn struct {
	cfg    LineInConfig
	run    runFunc
	sleep  func(ctx context.Context, d time.Duration)
	logf   func(string, ...any)
	mu     sync.Mutex
	cancel context.CancelFunc
	done   chan struct{}
	target IcecastTarget
	state  string
	detail string
}

func newLineIn(cfg LineInConfig, logf func(string, ...any)) *lineIn {
	return &lineIn{cfg: cfg, run: execRun, logf: logf, state: "idle", sleep: func(ctx context.Context, d time.Duration) {
		select {
		case <-ctx.Done():
		case <-time.After(d):
		}
	}}
}

// roleState is what the heartbeat reports for the role.
func (l *lineIn) roleState() roleState {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.cfg.Device == "" && l.cancel == nil {
		return roleState{State: "absent", Detail: "no input configured"}
	}
	return roleState{State: l.state, Detail: l.detail}
}

func (l *lineIn) running() bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.cancel != nil
}

// Start begins capturing. The same target while running is a no-op; another target restarts the capture.
func (l *lineIn) Start(t IcecastTarget) error {
	argv, err := captureCommand(l.cfg, t)
	if err != nil {
		return err
	}
	l.mu.Lock()
	if l.cancel != nil && l.target == t {
		l.mu.Unlock()
		return nil
	}
	l.mu.Unlock()
	l.Stop()

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	l.mu.Lock()
	l.cancel, l.done, l.target, l.state, l.detail = cancel, done, t, "busy", "capturing "+t.Mount
	l.mu.Unlock()
	go l.supervise(ctx, argv, done)
	return nil
}

func (l *lineIn) supervise(ctx context.Context, argv []string, done chan struct{}) {
	defer close(done)
	failures := 0
	for ctx.Err() == nil {
		started := time.Now()
		l.logf("line-in: %s", redact(argv))
		err := l.run(ctx, argv)
		if ctx.Err() != nil {
			return
		}
		if time.Since(started) > time.Minute {
			failures = 0 // it ran for a while: not a crash loop
		}
		failures++
		wait := backoff(failures)
		l.mu.Lock()
		l.state, l.detail = "error", fmt.Sprintf("capture failed (attempt %d), retry in %s: %v", failures, wait, err)
		l.mu.Unlock()
		l.logf("line-in: %s", l.detail)
		l.sleep(ctx, wait)
		l.mu.Lock()
		if ctx.Err() == nil {
			l.state, l.detail = "busy", "capturing "+l.target.Mount
		}
		l.mu.Unlock()
	}
}

// Stop ends the capture and waits for it. Safe when nothing runs.
func (l *lineIn) Stop() {
	l.mu.Lock()
	cancel, done := l.cancel, l.done
	l.cancel, l.done = nil, nil
	l.mu.Unlock()
	if cancel == nil {
		return
	}
	cancel()
	<-done
	l.mu.Lock()
	l.state, l.detail = "idle", ""
	l.mu.Unlock()
}

// lineInStart validates the job's params (all untrusted) and starts the capture.
func lineInStart(n *Node, params json.RawMessage) (map[string]any, error) {
	if n.lineIn == nil || n.cfg.LineIn.Device == "" {
		return nil, fmt.Errorf("no_input_configured")
	}
	var p struct {
		Mount          string `json:"mount"`
		IcecastPort    int    `json:"icecast_port"`
		SourcePassword string `json:"source_password"`
	}
	if err := json.Unmarshal(params, &p); err != nil {
		return nil, fmt.Errorf("bad params: %w", err)
	}
	host := n.cfg.LineIn.IcecastHost
	if host == "" {
		u, err := url.Parse(n.cfg.Server)
		if err != nil || u.Hostname() == "" {
			return nil, fmt.Errorf("no icecast host")
		}
		host = u.Hostname()
		if strings.Contains(host, ":") {
			host = "[" + host + "]"
		}
	}
	if err := n.lineIn.Start(IcecastTarget{Host: host, Port: p.IcecastPort, Mount: p.Mount, Password: p.SourcePassword}); err != nil {
		return nil, err
	}
	return map[string]any{"state": "capturing", "mount": p.Mount}, nil
}

func lineInStop(n *Node, _ json.RawMessage) (map[string]any, error) {
	if n.lineIn != nil {
		n.lineIn.Stop()
	}
	return map[string]any{"state": "stopped"}, nil
}
