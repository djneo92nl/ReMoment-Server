package main

import (
	"encoding/json"
	"fmt"
	"time"
)

// handlers is the closed list of job types this node runs. A type that is not here fails with unknown_type;
// nothing is ever executed from a job's params (no shell, no free paths). Roles add their types here in later phases.
var handlers = map[string]func(n *Node, params json.RawMessage) (map[string]any, error){
	"line_in.start": lineInStart,
	"line_in.stop":  lineInStop,
	"ping": func(_ *Node, params json.RawMessage) (map[string]any, error) {
		var p struct {
			Message string `json:"message"`
		}
		if len(params) > 0 && string(params) != "null" {
			if err := json.Unmarshal(params, &p); err != nil {
				return nil, fmt.Errorf("bad params: %w", err)
			}
		}
		if len(p.Message) > 100 {
			return nil, fmt.Errorf("message too long")
		}
		return map[string]any{"pong": p.Message, "node_time": time.Now().UTC().Format(time.RFC3339), "version": version}, nil
	},
}

// runJob accepts and runs one job, reporting over REST. One job at a time: the caller serialises.
func (n *Node) runJob(j *Job) {
	if err := n.api.accept(j.ID); err != nil {
		n.logf("job %s: accept: %v", j.ID, err) // a 409 means somebody else, or it expired: nothing to do
		return
	}
	h, ok := handlers[j.Type]
	if !ok {
		n.report(j, false, nil, "unknown_type: "+j.Type)
		return
	}
	if cancel, err := n.api.progress(j.ID); err == nil && cancel {
		n.report(j, false, nil, "cancelled")
		return
	}
	res, err := h(n, j.Params)
	if err != nil {
		n.report(j, false, nil, err.Error())
		return
	}
	n.report(j, true, res, "")
}

// report sends the result, retrying a few times: the server stores the first result only, so a repeat is harmless.
func (n *Node) report(j *Job, ok bool, res map[string]any, msg string) {
	for i := 0; i < 5; i++ {
		err := n.api.result(j.ID, ok, res, msg)
		if err == nil {
			n.logf("job %s (%s): ok=%v %s", j.ID, j.Type, ok, msg)
			return
		}
		n.logf("job %s: result: %v", j.ID, err)
		time.Sleep(time.Duration(i+1) * time.Second)
	}
}
