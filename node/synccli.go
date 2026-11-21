package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"remoment/node/internal/syncer"
)

// `remoment-node sync` is the helper: the same apply engine as the node's sync-target role, run by hand on a Mac or PC
// against a folder or a mounted stick (docs/architecture/offline-sync.md section 6, "Where it runs on a Mac or PC").
// It signs in with an admin bearer token (an app sign-in, `POST /api/admin/login`), not with a node registration, so it
// needs no node box. Hooked in by init so the node's own main stays untouched.
//
//	remoment-node sync --server http://remoment.local --token rmt_... --target-id <ULID> --path /Volumes/STICK [--claim] [--scan] [--confirm]
func init() {
	if len(os.Args) > 1 && os.Args[1] == "sync" {
		os.Exit(runSyncCLI(os.Args[2:], os.Stdout, os.Stderr, nil))
	}
}

// runSyncCLI returns the exit code: 0 done, 1 failed or refused, 3 needs_confirmation, 4 low_space, 130 cancelled.
// A nil client takes the default one (tests pass the httptest client).
func runSyncCLI(args []string, out, errOut io.Writer, client *http.Client) int {
	fs := flag.NewFlagSet("sync", flag.ContinueOnError)
	fs.SetOutput(errOut)
	server := fs.String("server", os.Getenv("REMOMENT_SERVER"), "server address, e.g. http://remoment.local (or REMOMENT_SERVER)")
	token := fs.String("token", os.Getenv("REMOMENT_TOKEN"), "admin bearer token (or REMOMENT_TOKEN)")
	targetID := fs.String("target-id", "", "the sync target (ULID) this folder or drive is for")
	path := fs.String("path", "", "the folder or mounted drive to fill")
	claim := fs.Bool("claim", false, "write the marker on a folder that has none (\"Use this drive\"), then stop")
	scan := fs.Bool("scan", false, "read the folder (marker, free space, manifest), write nothing, then stop")
	confirm := fs.Bool("confirm", false, "let a plan that removes over 30 % of the files, or over 500, go through")
	if err := fs.Parse(args); err != nil {
		return 1
	}
	fail := func(format string, a ...any) int {
		fmt.Fprintf(errOut, "sync: "+format+"\n", a...)
		return 1
	}
	if *server == "" || *token == "" || !idRe.MatchString(*targetID) || *path == "" {
		return fail("--server, --token, --target-id (a 26 character id) and --path are required")
	}
	root, err := filepath.Abs(*path)
	if err != nil {
		return fail("%v", err)
	}
	if *scan {
		fmt.Fprintf(out, "%v\n", syncer.Scan(root))
		return 0
	}
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Minute}
	}
	a := newAPI(strings.TrimRight(*server, "/"))
	a.token = *token

	var target struct {
		ID       string `json:"id"`
		ServerID string `json:"server_id"`
		Kind     string `json:"kind"`
	}
	if err := a.doWith(client, 1<<20, "GET", "/api/admin/sync/targets/"+*targetID, true, nil, &target); err != nil {
		return fail("cannot read the target: %v", err)
	}
	if target.ServerID == "" {
		return fail("the server did not tell its id; update the server")
	}
	if *claim {
		if err := syncer.Claim(root, *targetID, target.ServerID, time.Now()); err != nil {
			return fail("claim: %v", err)
		}
		fmt.Fprintln(out, "claimed: the drive now belongs to this target")
		return 0
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	srv := &cliServer{a: a, client: client, targetID: *targetID, out: out, ctx: ctx}
	e := syncer.New(syncer.Config{Root: root, TargetID: *targetID, ServerID: target.ServerID, Server: srv, Confirm: *confirm, Logf: func(f string, a ...any) { fmt.Fprintf(errOut, f+"\n", a...) }})
	res, err := e.Apply(ctx)
	if err != nil {
		return fail("%v", err)
	}
	fmt.Fprintf(out, "%s: %d added or replaced, %d failed, %d released, %d removed, %d moved, %d bytes written\n", res.State, len(res.Applied), len(res.Failed), len(res.Released), res.Removed, res.Moved, res.BytesWritten)
	for _, w := range res.Warnings {
		fmt.Fprintln(errOut, "warning:", w)
	}
	switch res.State {
	case "needs_confirmation":
		fmt.Fprintln(errOut, "the plan removes a lot: run again with --confirm to go ahead")
		return 3
	case "low_space":
		return 4
	case "cancelled":
		return 130
	}
	return 0
}

// cliServer is the syncer.Server over the admin routes: plan and report with the admin token, files from the signed URLs.
type cliServer struct {
	a        *API
	client   *http.Client
	targetID string
	out      io.Writer
	ctx      context.Context
	last     time.Time
}

func (s *cliServer) Plan(req syncer.PlanRequest) (*syncer.Plan, error) {
	have := req.Have
	if have == nil {
		have = []syncer.Entry{}
	}
	body := map[string]any{"have": have, "free_bytes": req.FreeBytes, "rules_version": req.RulesVersion}
	if req.FS != "" {
		body["fs"] = req.FS
	}
	var plan syncer.Plan
	if err := s.a.doWith(s.client, 256<<20, "POST", "/api/admin/sync/targets/"+s.targetID+"/plan", true, body, &plan); err != nil {
		return nil, err
	}

	return &plan, nil
}

func (s *cliServer) Report(r syncer.Report) error {
	if r.Applied == nil {
		r.Applied = []string{}
	}
	if r.Released == nil {
		r.Released = []string{}
	}
	if r.Failed == nil {
		r.Failed = []syncer.Failure{}
	}

	return s.a.doWith(s.client, 1<<20, "POST", "/api/admin/sync/targets/"+s.targetID+"/report", true, map[string]any{
		"rules_version": r.RulesVersion, "applied": r.Applied, "failed": r.Failed, "released": r.Released,
		"owned_bytes": r.OwnedBytes, "owned_count": r.OwnedCount, "free_bytes": r.FreeBytes,
	}, nil)
}

// Progress prints a line at most every two seconds; Ctrl-C cancels (the engine finishes the file and writes the manifest).
func (s *cliServer) Progress(p syncer.Progress) (bool, error) {
	if time.Since(s.last) > 2*time.Second {
		s.last = time.Now()
		fmt.Fprintf(s.out, "%d/%d files, %d/%d bytes %s\n", p.FilesDone, p.FilesTotal, p.BytesDone, p.BytesTotal, p.Current)
	}

	return s.ctx.Err() != nil, nil
}

func (s *cliServer) Download(ctx context.Context, url string, offset int64) (int, io.ReadCloser, error) {
	return fetchSyncFile(ctx, &http.Client{Transport: &http.Transport{ResponseHeaderTimeout: 60 * time.Second}}, s.a.base, url, offset)
}
