//go:build linux

package syncer

import "syscall"

// FSType names the filesystem of a path: "vfat", "exfat", "ntfs", "ext4" ... or "" when unknown. Only FAT matters to the
// server (a file over 4 GB cannot be held), the rest is informational.
func FSType(path string) string {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return ""
	}
	switch uint32(st.Type) {
	case 0x4d44:
		return "vfat"
	case 0x2011BAB0:
		return "exfat"
	case 0x5346544e, 0x7366746e:
		return "ntfs"
	case 0xEF53:
		return "ext4"
	case 0x65735546:
		return "fuseblk"
	case 0x6969:
		return "nfs"
	case 0xFF534D42:
		return "cifs"
	}
	return ""
}

// Usage is the size and the free space (for an unprivileged user) of the volume holding path.
func Usage(path string) (size, free int64) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, 0
	}
	return int64(st.Blocks) * int64(st.Bsize), int64(st.Bavail) * int64(st.Bsize)
}
