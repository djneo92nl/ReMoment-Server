//go:build !linux

package main

import "fmt"

// readTOC: the ripper role reads the drive through Linux ioctls only; a macOS or Windows node is a later question
// (docs/architecture/node-ripper.md).
func readTOC(device string) (*TOC, error) {
	return nil, fmt.Errorf("reading a CD drive is only supported on Linux")
}
