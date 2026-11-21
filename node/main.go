// remoment-node: the ReMoment Node agent (docs/architecture/node-agent.md). Registers as a node client, is approved by
// the admin, sends a heartbeat every 30 s, listens for the MQTT doorbell and runs fixed job types fetched over REST:
// `ping`, the line-in role (`line_in.*`) and the ripper role (`ripper.status`, `ripper.rip`). No shell, no free paths.
package main

import (
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

const (
	heartbeatEvery = 30 * time.Second
	pollEvery      = 10 * time.Second
	statusEvery    = 15 * time.Second
)

type Node struct {
	cfg     *Config
	api     *API
	started time.Time
	nudge   chan struct{}
	mu      sync.Mutex
	roles   []string
	lineIn  *lineIn
	ripper  *ripper
	syncing atomic.Bool
	job     *Job // the job being run (one at a time), for handlers that report progress
}

func (n *Node) logf(f string, a ...any) { log.Printf(f, a...) }

func main() {
	cfgPath := flag.String("config", "/etc/remoment-node.json", "config file (JSON)")
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()
	if *showVersion {
		fmt.Println(version)
		return
	}

	cfg, err := loadConfig(*cfgPath)
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	n := &Node{cfg: cfg, api: newAPI(cfg.Server), started: time.Now(), nudge: make(chan struct{}, 1)}
	n.lineIn = newLineIn(cfg.LineIn, n.logf)
	defer n.lineIn.Stop()
	n.ripper = newRipper(cfg.Ripper, cfg.StateDir, n.logf)
	n.ripper.cleanStale() // what an interrupted rip left behind

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	done := make(chan struct{})
	go func() { <-stop; close(done) }()

	if err := n.run(done); err != nil {
		if errors.Is(err, errUnauthorized) {
			log.Printf("stopping: %v. Delete %s to register again.", err, filepath.Join(cfg.StateDir, "token"))
			os.Exit(2)
		}
		log.Fatalf("%v", err)
	}
}

func (n *Node) tokenFile() string { return filepath.Join(n.cfg.StateDir, "token") }

// loadToken returns the stored "api_token\nclient_id" or empty.
func (n *Node) loadToken() (token string, id string) {
	b, err := os.ReadFile(n.tokenFile())
	if err != nil {
		return "", ""
	}
	p := strings.Fields(string(b))
	if len(p) == 2 {
		return p[0], p[1]
	}
	return "", ""
}

func (n *Node) saveToken(token string, id int) error {
	if err := os.MkdirAll(n.cfg.StateDir, 0o700); err != nil {
		return err
	}
	return os.WriteFile(n.tokenFile(), []byte(fmt.Sprintf("%s\n%d\n", token, id)), 0o600)
}

func (n *Node) run(done <-chan struct{}) error {
	token, id := n.loadToken()
	if token == "" {
		var err error
		if token, id, err = n.registerAndWait(done); err != nil {
			return err
		}
		if token == "" { // stopped while waiting
			return nil
		}
	}
	n.api.token = token
	n.logf("approved; node id %s, running (version %s)", id, version)

	if !n.cfg.NoMQTT {
		go n.mqttLoop(id, done)
	}

	hb := time.NewTicker(heartbeatEvery)
	poll := time.NewTicker(pollEvery)
	defer hb.Stop()
	defer poll.Stop()

	if err := n.beat(); err != nil && errors.Is(err, errUnauthorized) {
		return err
	}
	for {
		select {
		case <-done:
			return nil
		case <-hb.C:
			if err := n.beat(); errors.Is(err, errUnauthorized) {
				return err
			}
		case <-poll.C:
			if err := n.drain(); errors.Is(err, errUnauthorized) {
				return err
			}
		case <-n.nudge:
			if err := n.drain(); errors.Is(err, errUnauthorized) {
				return err
			}
		}
	}
}

// registerAndWait registers (idempotent per hardware_id) and polls until the admin approves.
func (n *Node) registerAndWait(done <-chan struct{}) (string, string, error) {
	var reg *registration
	for {
		r, err := n.api.register(n.cfg, n.capabilities())
		if err == nil {
			reg = r
			break
		}
		n.logf("register: %v (retrying)", err)
		select {
		case <-done:
			return "", "", nil
		case <-time.After(15 * time.Second):
		}
	}
	n.logf("registered; pairing code %s. Approve this node at %s/settings/clients", reg.PairingCode, n.cfg.Server)
	for {
		s, err := n.api.status(reg.RegistrationToken)
		if err == nil && s.Status == "approved" && s.APIToken != "" {
			if err := n.saveToken(s.APIToken, s.ClientID); err != nil {
				return "", "", fmt.Errorf("saving the token: %w", err)
			}
			return s.APIToken, fmt.Sprint(s.ClientID), nil
		}
		if err != nil {
			n.logf("status: %v", err)
		}
		select {
		case <-done:
			return "", "", nil
		case <-time.After(statusEvery):
		}
	}
}

func (n *Node) status() nodeStatus {
	n.mu.Lock()
	defer n.mu.Unlock()
	st := nodeStatus{Roles: map[string]roleState{}, UptimeSeconds: int64(time.Since(n.started).Seconds()), Load: loadAvg(), DiskFree: diskFree(n.cfg.StateDir)}
	for _, r := range n.roles {
		st.Roles[r] = roleState{State: "idle"}
		if r == "line-in" && n.lineIn != nil {
			st.Roles[r] = n.lineIn.roleState()
		}
		if r == "ripper" && n.ripper != nil {
			st.Roles[r] = n.ripper.roleState()
		}
		if r == "sync-target" {
			st.Roles[r] = n.syncRoleState()
		}
	}
	return st
}

func (n *Node) beat() error {
	h, err := n.api.heartbeat(n.status())
	if err != nil {
		n.logf("heartbeat: %v", err)
		return err
	}
	n.mu.Lock()
	n.roles = h.Roles
	n.mu.Unlock()
	return nil
}

// drain runs queued jobs one after another until there is none.
func (n *Node) drain() error {
	for i := 0; i < 20; i++ {
		j, err := n.api.nextJob()
		if err != nil {
			if !errors.Is(err, errUnauthorized) {
				n.logf("jobs: %v", err)
			}
			return err
		}
		if j == nil {
			return nil
		}
		n.runJob(j)
	}
	return nil
}

// capabilities is what the node declares at registration: informational only, never executed by the server.
func (n *Node) capabilities() map[string]any {
	caps := map[string]any{}
	if n.cfg.LineIn.Device != "" {
		label := n.cfg.LineIn.Label
		if label == "" {
			label = n.cfg.LineIn.Device
		}
		caps["line-in"] = map[string]any{"inputs": []map[string]any{{"id": n.cfg.LineIn.Device, "label": label}}}
	}
	if n.cfg.Sync.VolumePath != "" {
		caps["sync-target"] = map[string]any{"volume": n.cfg.Sync.VolumePath}
	}
	if n.cfg.Ripper.Device != "" {
		caps["ripper"] = map[string]any{"drive": n.cfg.Ripper.Device, "engine": "cyanrip"}
	}
	return caps
}
