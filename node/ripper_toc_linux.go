//go:build linux

package main

import (
	"encoding/binary"
	"fmt"
	"os"
	"syscall"
	"unsafe"
)

// The kernel's CD-ROM ioctls (linux/cdrom.h). Reading the TOC needs no privileges beyond read access to the drive
// (the node user is in the `cdrom` group). Unverified on real hardware.
const (
	cdromReadTocHdr   = 0x5305
	cdromReadTocEntry = 0x5306
	cdromLeadout      = 0xAA
	cdromLBA          = 0x01
	enomedium         = syscall.Errno(123)
)

type cdTocHdr struct{ First, Last uint8 }

// cdTocEntry is struct cdrom_tocentry: track, adr and ctrl nibbles, address format, a 4-byte address union, data mode.
type cdTocEntry struct {
	Track    uint8
	AdrCtrl  uint8
	Format   uint8
	_        uint8
	Addr     [4]byte
	DataMode uint8
	_        [3]uint8
}

func ioctl(fd uintptr, req uintptr, arg unsafe.Pointer) error {
	if _, _, e := syscall.Syscall(syscall.SYS_IOCTL, fd, req, uintptr(arg)); e != 0 {
		return e
	}
	return nil
}

// readTOC reads the TOC of the disc in `device` with the kernel's CDROMREADTOCHDR and CDROMREADTOCENTRY ioctls.
func readTOC(device string) (*TOC, error) {
	f, err := os.OpenFile(device, os.O_RDONLY|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, fmt.Errorf("open %s: %w", device, err)
	}
	defer f.Close()
	fd := f.Fd()

	var hdr cdTocHdr
	if err := ioctl(fd, cdromReadTocHdr, unsafe.Pointer(&hdr)); err != nil {
		if err == enomedium || err == syscall.EIO || err == syscall.ENXIO {
			return nil, errNoDisc
		}
		return nil, fmt.Errorf("read the TOC header: %w", err)
	}

	t := &TOC{First: int(hdr.First), Last: int(hdr.Last), Audio: true}
	entry := func(track uint8) (*cdTocEntry, int, error) {
		e := cdTocEntry{Track: track, Format: cdromLBA}
		if err := ioctl(fd, cdromReadTocEntry, unsafe.Pointer(&e)); err != nil {
			return nil, 0, fmt.Errorf("read TOC entry %d: %w", track, err)
		}
		return &e, int(int32(binary.LittleEndian.Uint32(e.Addr[:]))) + 150, nil
	}
	for tr := hdr.First; tr <= hdr.Last; tr++ {
		e, off, err := entry(tr)
		if err != nil {
			return nil, err
		}
		if e.AdrCtrl>>4&0x4 != 0 { // ctrl bit 2: a data track
			t.Audio = false
		}
		t.Offsets = append(t.Offsets, off)
	}
	_, lead, err := entry(cdromLeadout)
	if err != nil {
		return nil, err
	}
	t.Leadout = lead
	return t, nil
}
