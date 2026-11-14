package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"
)

// version is set at build time: -ldflags "-X main.version=1.2.3".
var version = "dev"

// buildNumber is set at build time: -ldflags "-X main.buildNumber=42".
var buildNumber = "0"

var errUnauthorized = errors.New("the server does not know this token (revoked or removed)")

// API talks to the server. REST is authoritative; nothing here trusts MQTT.
type API struct {
	base  string
	token string
	http  *http.Client
}

func newAPI(base string) *API {
	return &API{base: base, http: &http.Client{Timeout: 20 * time.Second}}
}

type httpError struct {
	Status int
	Body   string
}

func (e *httpError) Error() string { return fmt.Sprintf("HTTP %d: %s", e.Status, e.Body) }

func (a *API) do(method, path string, bearer bool, in, out any) error {
	var body io.Reader
	if in != nil {
		b, err := json.Marshal(in)
		if err != nil {
			return err
		}
		body = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, a.base+path, body)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	if in != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if bearer {
		req.Header.Set("Authorization", "Bearer "+a.token)
	}
	resp, err := a.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode == http.StatusUnauthorized || (resp.StatusCode == http.StatusNotFound && !bearer && method == "PUT") {
		return errUnauthorized
	}
	if resp.StatusCode >= 300 {
		return &httpError{resp.StatusCode, string(bytes.TrimSpace(data))}
	}
	if out != nil && len(data) > 0 {
		return json.Unmarshal(data, out)
	}
	return nil
}

type registration struct {
	RegistrationToken string `json:"registration_token"`
	PairingCode       string `json:"pairing_code"`
	Status            string `json:"status"`
}

func (a *API) register(c *Config, caps map[string]any) (*registration, error) {
	var r registration
	err := a.do("POST", "/api/clients/register", false, map[string]any{
		"kind": "node", "hardware_id": c.HardwareID, "firmware_version": version,
		"build_number": atoiOr0(buildNumber), "roles": c.Roles, "capabilities": caps,
		"metadata": map[string]any{"os": osName()},
	}, &r)
	return &r, err
}

type statusAnswer struct {
	Status   string   `json:"status"`
	APIToken string   `json:"api_token"`
	ClientID int      `json:"client_id"`
	Roles    []string `json:"roles"`
}

func (a *API) status(registrationToken string) (*statusAnswer, error) {
	var s statusAnswer
	err := a.do("GET", "/api/clients/status/"+registrationToken, false, nil, &s)
	return &s, err
}

type info struct {
	MQTT struct {
		Host   string `json:"host"`
		Port   int    `json:"port"`
		Prefix string `json:"topic_prefix"`
	} `json:"mqtt"`
}

func (a *API) info() (*info, error) {
	var i info
	err := a.do("GET", "/api/info", false, nil, &i)
	return &i, err
}

type roleState struct {
	State  string `json:"state"`
	Detail string `json:"detail,omitempty"`
}

type nodeStatus struct {
	Roles         map[string]roleState `json:"roles"`
	UptimeSeconds int64                `json:"uptime_seconds"`
	Load          float64              `json:"load,omitempty"`
	DiskFree      uint64               `json:"disk_free_bytes,omitempty"`
}

type heartbeatAnswer struct {
	Roles []string `json:"roles"`
}

func (a *API) heartbeat(st nodeStatus) (*heartbeatAnswer, error) {
	var h heartbeatAnswer
	err := a.do("PUT", "/api/clients/"+a.token+"/heartbeat", false, map[string]any{
		"firmware_version": version, "build_number": atoiOr0(buildNumber), "status": st,
	}, &h)
	return &h, err
}

// Job is what the server hands out; Params is untrusted until the handler for the type has validated it.
type Job struct {
	ID              string          `json:"id"`
	Type            string          `json:"type"`
	Role            *string         `json:"role"`
	Params          json.RawMessage `json:"params"`
	State           string          `json:"state"`
	CancelRequested bool            `json:"cancel_requested"`
}

func (a *API) nextJob() (*Job, error) {
	var r struct {
		Job *Job `json:"job"`
	}
	err := a.do("GET", "/api/nodes/job", true, nil, &r)
	return r.Job, err
}

func (a *API) accept(id string) error {
	return a.do("POST", "/api/nodes/jobs/"+id+"/accept", true, map[string]any{}, nil)
}

func (a *API) progress(id string) (cancel bool, err error) {
	var r struct {
		Cancel bool `json:"cancel_requested"`
	}
	err = a.do("POST", "/api/nodes/jobs/"+id+"/progress", true, map[string]any{}, &r)
	return r.Cancel, err
}

func (a *API) result(id string, ok bool, result map[string]any, errMsg string) error {
	body := map[string]any{"ok": ok}
	if result != nil {
		body["result"] = result
	}
	if errMsg != "" {
		body["error"] = errMsg
	}
	return a.do("POST", "/api/nodes/jobs/"+id+"/result", true, body, nil)
}
