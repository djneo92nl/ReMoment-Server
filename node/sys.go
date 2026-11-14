package main

import (
	"os"
	"runtime"
	"strconv"
	"strings"
	"syscall"
)

func osName() string { return runtime.GOOS + "/" + runtime.GOARCH }

func atoiOr0(s string) int {
	n, err := strconv.Atoi(s)
	if err != nil {
		return 0
	}
	return n
}

func loadAvg() float64 {
	b, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0
	}
	f := strings.Fields(string(b))
	if len(f) == 0 {
		return 0
	}
	v, _ := strconv.ParseFloat(f[0], 64)
	return v
}

func diskFree(path string) uint64 {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0
	}
	return uint64(st.Bavail) * uint64(st.Bsize)
}
