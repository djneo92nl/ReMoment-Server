package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
)

// Config is read from a JSON file (default /etc/remoment-node.json) and overridden by REMOMENT_NODE_* variables.
type Config struct {
	// Server is the base address of the ReMoment server, e.g. http://remoment.local (no mDNS discovery yet).
	Server string `json:"server"`
	// StateDir holds the token (mode 0600). Default /var/lib/remoment-node.
	StateDir string `json:"state_dir"`
	// HardwareID identifies this machine across restarts. Default: /etc/machine-id.
	HardwareID string `json:"hardware_id"`
	// Roles the node declares. The admin switches them on. N1 has no role with a job type yet.
	Roles []string `json:"roles"`
	// NoMQTT turns the doorbell off: the node then only polls.
	NoMQTT bool `json:"no_mqtt"`
}

func loadConfig(path string) (*Config, error) {
	c := &Config{StateDir: "/var/lib/remoment-node"}
	if b, err := os.ReadFile(path); err == nil {
		if err := json.Unmarshal(b, c); err != nil {
			return nil, fmt.Errorf("%s: %w", path, err)
		}
	} else if !os.IsNotExist(err) {
		return nil, err
	}
	if v := os.Getenv("REMOMENT_NODE_SERVER"); v != "" {
		c.Server = v
	}
	if v := os.Getenv("REMOMENT_NODE_STATE_DIR"); v != "" {
		c.StateDir = v
	}
	if v := os.Getenv("REMOMENT_NODE_HARDWARE_ID"); v != "" {
		c.HardwareID = v
	}
	if v := os.Getenv("REMOMENT_NODE_ROLES"); v != "" {
		c.Roles = strings.Split(v, ",")
	}
	if os.Getenv("REMOMENT_NODE_NO_MQTT") == "1" {
		c.NoMQTT = true
	}
	c.Server = strings.TrimRight(c.Server, "/")
	if c.Server == "" {
		return nil, fmt.Errorf("no server address: set \"server\" in the config file or REMOMENT_NODE_SERVER")
	}
	if c.HardwareID == "" {
		b, err := os.ReadFile("/etc/machine-id")
		if err != nil || len(strings.TrimSpace(string(b))) == 0 {
			return nil, fmt.Errorf("no hardware_id: set it in the config or REMOMENT_NODE_HARDWARE_ID (no /etc/machine-id)")
		}
		c.HardwareID = strings.TrimSpace(string(b))
	}
	return c, nil
}
