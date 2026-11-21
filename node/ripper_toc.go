package main

import (
	"crypto/sha1"
	"encoding/base64"
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// TOC is the table of contents of an audio CD as the drive reports it. Offsets are in CD frames from the start of the
// disc including the 150 frames (2 s) of pregap, the unit MusicBrainz uses; Offsets[i] is track First+i.
type TOC struct {
	First, Last int
	Offsets     []int
	Leadout     int
	// Audio is false when any track is a data track: a data or mixed-mode disc is not ripped.
	Audio bool
}

// tocReader reads the TOC of the disc in a drive. It returns errNoDisc for an empty drive. The real one talks to the
// kernel (ripper_toc_linux.go); tests give a fake, so no test ever touches a drive.
type tocReader func(device string) (*TOC, error)

var errNoDisc = errors.New("no disc in the drive")

// tracks is the number of tracks on the disc.
func (t *TOC) tracks() int { return t.Last - t.First + 1 }

// valid says whether the TOC is a sane one: a numbered range and offsets that grow up to the lead-out.
func (t *TOC) valid() bool {
	if t.First < 1 || t.Last > 99 || t.Last < t.First || len(t.Offsets) != t.tracks() {
		return false
	}
	prev := -1
	for _, o := range t.Offsets {
		if o <= prev {
			return false
		}
		prev = o
	}
	return t.Leadout > prev
}

// discID is the MusicBrainz disc id: SHA-1 over the first and last track number (two hex digits each), the lead-out
// offset and the offsets of tracks 1 to 99 (eight hex digits each, 0 for a track the disc has not), base64 with
// "+", "/" and "=" replaced by ".", "_" and "-" (https://musicbrainz.org/doc/Disc_ID_Calculation).
func (t *TOC) discID() string {
	h := sha1.New()
	fmt.Fprintf(h, "%02X%02X%08X", t.First, t.Last, t.Leadout)
	for i := 1; i <= 99; i++ {
		off := 0
		if i >= t.First && i <= t.Last {
			off = t.Offsets[i-t.First]
		}
		fmt.Fprintf(h, "%08X", off)
	}
	s := base64.StdEncoding.EncodeToString(h.Sum(nil))
	return strings.NewReplacer("+", ".", "/", "_", "=", "-").Replace(s)
}

// tocString is the TOC as MusicBrainz takes it for a lookup by TOC: "first last leadout offset1 ... offsetN".
func (t *TOC) tocString() string {
	parts := []string{strconv.Itoa(t.First), strconv.Itoa(t.Last), strconv.Itoa(t.Leadout)}
	for _, o := range t.Offsets {
		parts = append(parts, strconv.Itoa(o))
	}
	return strings.Join(parts, " ")
}
