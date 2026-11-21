package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"

	"remoment/node/internal/syncer"
)

// Sync-target role (workstream 18.3, docs/architecture/offline-sync.md section 6): the node keeps one removable volume
// filled by the server. The volume is the mount point in the node's own config; a job only names the target and carries
// the server's id. Everything that touches the volume is `internal/syncer`, which checks paths and markers itself.

// SyncConfig is the `sync` object of the node config.
type SyncConfig struct {
	// VolumePath is where the stick or share is mounted, e.g. /media/usb/STICK. Empty: the node has no sync target.
	VolumePath string `json:"volume_path"`
}

var idRe = regexp.MustCompile(`^[0-9A-Za-z]{26}$`)

type syncParams struct {
	TargetID string `json:"target_id"`
	ServerID string `json:"server_id"`
	Confirm  bool   `json:"confirm"`
}

// syncParse validates the job params (untrusted until here) and returns the volume path from the node's own config.
func syncParse(n *Node, raw json.RawMessage) (*syncParams, string, error) {
	if n.cfg.Sync.VolumePath == "" {
		return nil, "", fmt.Errorf("no_volume_configured")
	}
	var p syncParams
	if err := json.Unmarshal(raw, &p); err != nil {
		return nil, "", fmt.Errorf("bad params: %w", err)
	}
	if !idRe.MatchString(p.TargetID) || !idRe.MatchString(p.ServerID) {
		return nil, "", fmt.Errorf("bad params: target_id and server_id must be ids")
	}
	return &p, n.cfg.Sync.VolumePath, nil
}

func (n *Node) syncRoleState() roleState {
	switch {
	case n.cfg.Sync.VolumePath == "":
		return roleState{State: "absent", Detail: "no volume configured"}
	case n.syncing.Load():
		return roleState{State: "busy", Detail: "syncing"}
	}
	return roleState{State: "idle"}
}

func syncScan(n *Node, raw json.RawMessage) (map[string]any, error) {
	_, root, err := syncParse(n, raw)
	if err != nil {
		return nil, err
	}

	return syncer.Scan(root), nil
}

func syncClaim(n *Node, raw json.RawMessage) (map[string]any, error) {
	p, root, err := syncParse(n, raw)
	if err != nil {
		return nil, err
	}
	if err := syncer.Claim(root, p.TargetID, p.ServerID, time.Now()); err != nil {
		return nil, err
	}

	return map[string]any{"claimed": true}, nil
}

func syncApply(n *Node, raw json.RawMessage) (map[string]any, error) {
	p, root, err := syncParse(n, raw)
	if err != nil {
		return nil, err
	}
	if n.job == nil {
		return nil, fmt.Errorf("no job")
	}
	n.syncing.Store(true)
	defer n.syncing.Store(false)

	srv := &syncServer{n: n, jobID: n.job.ID, targetID: p.TargetID}
	e := syncer.New(syncer.Config{Root: root, TargetID: p.TargetID, ServerID: p.ServerID, Server: srv, Confirm: p.Confirm, Logf: n.logf})
	res, err := e.Apply(context.Background())
	if err != nil {
		return nil, err
	}
	if res.State == "cancelled" {
		return nil, fmt.Errorf("cancelled")
	}

	return res.Map(), nil
}

// syncServer is the syncer.Server over the node's own REST routes: the plan and the report with the node's Bearer token,
// the files from the signed URLs the plan carries (no token), progress through the job route.
type syncServer struct {
	n        *Node
	jobID    string
	targetID string
	dl       *http.Client
}

var planClient = &http.Client{Timeout: 10 * time.Minute}

func (s *syncServer) Plan(req syncer.PlanRequest) (*syncer.Plan, error) {
	have := req.Have
	if have == nil {
		have = []syncer.Entry{}
	}
	body := map[string]any{"target_id": s.targetID, "have": have, "free_bytes": req.FreeBytes, "rules_version": req.RulesVersion}
	if req.FS != "" {
		body["fs"] = req.FS
	}
	var plan syncer.Plan
	if err := s.n.api.doWith(planClient, 256<<20, "POST", "/api/nodes/sync/plan", true, body, &plan); err != nil {
		return nil, err
	}

	return &plan, nil
}

func (s *syncServer) Report(r syncer.Report) error {
	if r.Applied == nil {
		r.Applied = []string{}
	}
	if r.Released == nil {
		r.Released = []string{}
	}
	if r.Failed == nil {
		r.Failed = []syncer.Failure{}
	}

	return s.n.api.doWith(planClient, 1<<20, "POST", "/api/nodes/sync/report", true, map[string]any{
		"target_id": s.targetID, "rules_version": r.RulesVersion, "applied": r.Applied, "failed": r.Failed, "released": r.Released,
		"owned_bytes": r.OwnedBytes, "owned_count": r.OwnedCount, "free_bytes": r.FreeBytes,
	}, nil)
}

func (s *syncServer) Progress(p syncer.Progress) (bool, error) {
	return s.n.api.progressWith(s.jobID, p)
}

// Download fetches from the server only: the URL must be a sync file path the server issued, never another address.
func (s *syncServer) Download(ctx context.Context, url string, offset int64) (int, io.ReadCloser, error) {
	if s.dl == nil {
		s.dl = &http.Client{Transport: &http.Transport{ResponseHeaderTimeout: 60 * time.Second}}
	}

	return fetchSyncFile(ctx, s.dl, s.n.cfg.Server, url, offset)
}

// fetchSyncFile is the ranged GET of a signed sync file under base, shared by the node job and the helper CLI.
func fetchSyncFile(ctx context.Context, client *http.Client, base, url string, offset int64) (int, io.ReadCloser, error) {
	if !strings.HasPrefix(url, "/api/sync/files/") {
		return 0, nil, fmt.Errorf("refusing a download URL that is not a sync file")
	}
	ctx, cancel := context.WithCancel(ctx)
	req, err := http.NewRequestWithContext(ctx, "GET", base+url, nil)
	if err != nil {
		cancel()
		return 0, nil, err
	}
	if offset > 0 {
		req.Header.Set("Range", fmt.Sprintf("bytes=%d-", offset))
	}
	resp, err := client.Do(req)
	if err != nil {
		cancel()
		return 0, nil, err
	}

	return resp.StatusCode, newIdleBody(resp.Body, cancel, 60*time.Second), nil
}

// idleBody cancels the request when no byte arrives for `idle`: a stalled connection must not hang the job (the progress
// report keeps its lease alive, so only this ends it).
type idleBody struct {
	rc     io.ReadCloser
	timer  *time.Timer
	cancel context.CancelFunc
	idle   time.Duration
}

func newIdleBody(rc io.ReadCloser, cancel context.CancelFunc, idle time.Duration) *idleBody {
	return &idleBody{rc: rc, cancel: cancel, idle: idle, timer: time.AfterFunc(idle, cancel)}
}

func (b *idleBody) Read(p []byte) (int, error) {
	n, err := b.rc.Read(p)
	b.timer.Reset(b.idle)

	return n, err
}

func (b *idleBody) Close() error {
	b.timer.Stop()
	err := b.rc.Close()
	b.cancel()

	return err
}
