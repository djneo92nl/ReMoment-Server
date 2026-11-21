package syncer

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"unicode/utf8"
)

// metaDir is where the marker, the manifest and the partial downloads live, under the volume root.
const metaDir = ".remoment-sync"

const (
	maxSegmentBytes = 255
	maxPathBytes    = 1000
)

// SafePath checks a path from a plan (or a manifest) before anything is done with it: relative, "/"-separated, no "." or
// ".." segment, no drive letter or absolute path, no control character or character FAT32 forbids, no segment over 255
// bytes, no trailing dot or space, and never inside the metadata directory. A hostile or buggy server cannot make the
// node write or delete outside the volume with a path that fails this.
func SafePath(p string) error {
	if p == "" {
		return fmt.Errorf("empty path")
	}
	if len(p) > maxPathBytes || !utf8.ValidString(p) {
		return fmt.Errorf("path too long or not UTF-8")
	}
	if strings.HasPrefix(p, "/") || strings.Contains(p, "\\") {
		return fmt.Errorf("absolute path or backslash")
	}
	for i, seg := range strings.Split(p, "/") {
		switch {
		case seg == "" || seg == "." || seg == "..":
			return fmt.Errorf("bad segment %q", seg)
		case len(seg) > maxSegmentBytes:
			return fmt.Errorf("segment too long")
		case strings.HasSuffix(seg, ".") || strings.HasSuffix(seg, " "):
			return fmt.Errorf("trailing dot or space")
		case i == 0 && strings.EqualFold(seg, metaDir):
			return fmt.Errorf("inside %s", metaDir)
		case i == 0 && len(seg) >= 2 && seg[1] == ':':
			return fmt.Errorf("drive letter")
		}
		for _, r := range seg {
			if r < 0x20 || r == 0x7f || strings.ContainsRune(`<>:"|?*`, r) {
				return fmt.Errorf("forbidden character %q", r)
			}
		}
	}
	return nil
}

// abs is the absolute path of a relative, already checked path under root.
func abs(root, rel string) string { return filepath.Join(root, filepath.FromSlash(rel)) }

// existsFold reports whether rel exists under root, comparing every segment case-insensitively (FAT, exFAT and a
// default Mac volume cannot hold "A.mp3" next to "a.mp3").
func existsFold(root, rel string) bool {
	dir := root
	segs := strings.Split(rel, "/")
	for i, seg := range segs {
		entries, err := os.ReadDir(dir)
		if err != nil {
			return false
		}
		found := ""
		for _, e := range entries {
			if strings.EqualFold(e.Name(), seg) {
				found = e.Name()
				break
			}
		}
		if found == "" {
			return false
		}
		if i == len(segs)-1 {
			return true
		}
		dir = filepath.Join(dir, found)
	}
	return false
}

// resolveFree is rel when nothing is there, else "name (2).ext", "name (3).ext" ... (rule 2: never overwrite a file we do
// not own). `taken` holds lower-cased paths that are reserved by entries of the manifest.
func resolveFree(root, rel string, taken map[string]string, key string) (string, bool) {
	free := func(p string) bool {
		if owner, ok := taken[strings.ToLower(p)]; ok && owner != key {
			return false
		}
		return !existsFold(root, p)
	}
	if free(rel) {
		return rel, false
	}
	ext := filepath.Ext(rel)
	base := strings.TrimSuffix(rel, ext)
	for n := 2; n < 1000; n++ {
		if c := fmt.Sprintf("%s (%d)%s", base, n, ext); free(c) {
			return c, true
		}
	}
	return rel, true
}

// pruneEmptyDirs removes the now empty parent directories of rel, up to (not including) the root. Only empty ones: a
// folder with a stranger's file in it stays.
func pruneEmptyDirs(root, rel string) {
	dir := filepath.Dir(abs(root, rel))
	for dir != root && strings.HasPrefix(dir, root) {
		if err := os.Remove(dir); err != nil { // fails when not empty: exactly what we want
			return
		}
		dir = filepath.Dir(dir)
	}
}
