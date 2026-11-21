package main

import (
	"encoding/binary"
	"fmt"
	"io"
	"os"
	"strings"
)

// readFlacTags reads the Vorbis comments of a FLAC file (keys upper-cased). Only metadata blocks are read, never audio.
func readFlacTags(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	magic := make([]byte, 4)
	if _, err := io.ReadFull(f, magic); err != nil || string(magic) != "fLaC" {
		return nil, fmt.Errorf("not a FLAC file")
	}
	for {
		var h [4]byte
		if _, err := io.ReadFull(f, h[:]); err != nil {
			return nil, fmt.Errorf("no Vorbis comment block")
		}
		last := h[0]&0x80 != 0
		typ := h[0] & 0x7f
		size := int64(h[1])<<16 | int64(h[2])<<8 | int64(h[3])
		if typ != 4 {
			if _, err := f.Seek(size, io.SeekCurrent); err != nil {
				return nil, err
			}
		} else {
			if size > 1<<20 {
				return nil, fmt.Errorf("Vorbis comment block too large")
			}
			buf := make([]byte, size)
			if _, err := io.ReadFull(f, buf); err != nil {
				return nil, err
			}
			return parseVorbisComments(buf)
		}
		if last {
			return nil, fmt.Errorf("no Vorbis comment block")
		}
	}
}

func parseVorbisComments(b []byte) (map[string]string, error) {
	next := func() ([]byte, error) {
		if len(b) < 4 {
			return nil, fmt.Errorf("truncated")
		}
		n := binary.LittleEndian.Uint32(b[:4])
		b = b[4:]
		if uint32(len(b)) < n {
			return nil, fmt.Errorf("truncated")
		}
		v := b[:n]
		b = b[n:]
		return v, nil
	}
	if _, err := next(); err != nil { // vendor string
		return nil, err
	}
	if len(b) < 4 {
		return nil, fmt.Errorf("truncated")
	}
	count := binary.LittleEndian.Uint32(b[:4])
	b = b[4:]
	tags := map[string]string{}
	for i := uint32(0); i < count; i++ {
		c, err := next()
		if err != nil {
			return tags, err
		}
		if k, v, ok := strings.Cut(string(c), "="); ok {
			tags[strings.ToUpper(k)] = v
		}
	}
	return tags, nil
}
