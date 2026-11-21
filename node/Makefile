# ReMoment Node: static builds, no cgo. Output in dist/.
VERSION ?= $(shell cat ../VERSION 2>/dev/null || echo dev)
BUILD   ?= 0
LDFLAGS := -s -w -X main.version=$(VERSION) -X main.buildNumber=$(BUILD)

.PHONY: test build armv7 arm64 amd64 clean deb deb-armhf deb-arm64
test:
	go vet ./... && go test ./...
build: armv7 arm64 amd64
armv7:
	GOOS=linux GOARCH=arm GOARM=7 CGO_ENABLED=0 go build -ldflags "$(LDFLAGS)" -o dist/remoment-node-linux-armv7 .
arm64:
	GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -ldflags "$(LDFLAGS)" -o dist/remoment-node-linux-arm64 .
amd64:
	GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -ldflags "$(LDFLAGS)" -o dist/remoment-node-linux-amd64 .
# .deb for Raspberry Pi OS / Debian: armhf (GOARM=7) and arm64. Needs dpkg-deb (any Linux CI runner has it).
deb: deb-armhf deb-arm64
deb-armhf: armv7
	sh packaging/build-deb.sh deb armhf dist/remoment-node-linux-armv7 $(VERSION) dist
deb-arm64: arm64
	sh packaging/build-deb.sh deb arm64 dist/remoment-node-linux-arm64 $(VERSION) dist
clean:
	rm -rf dist/remoment-node-* dist/remoment-node_*.deb dist/pkg-*
