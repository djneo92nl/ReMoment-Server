//go:build !linux

package syncer

import "syscall"

// FSType is unknown off Linux except for what Darwin reports; the node targets Linux, this keeps the tests running on a Mac.
func FSType(path string) string {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return ""
	}
	name := make([]byte, 0, 16)
	for _, c := range st.Fstypename {
		if c == 0 {
			break
		}
		name = append(name, byte(c))
	}
	switch string(name) {
	case "msdos":
		return "vfat"
	}
	return string(name)
}

// Usage is the size and the free space of the volume holding path.
func Usage(path string) (size, free int64) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0
	}
	return int64(st.Blocks) * int64(st.Bsize), int64(st.Bavail) * int64(st.Bsize)
}
