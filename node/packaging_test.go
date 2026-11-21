package main

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// The .deb itself needs dpkg-deb (CI has it); the tree it is made from is checked here, on any machine with sh.
func buildTree(t *testing.T, arch, version string) (root, dver string) {
	t.Helper()
	sh, err := exec.LookPath("sh")
	if err != nil {
		t.Skip("no sh")
	}
	out := t.TempDir()
	bin := filepath.Join(out, "fakebin")
	if err := os.WriteFile(bin, []byte("\x7fELF"), 0o644); err != nil {
		t.Fatal(err)
	}
	if b, err := exec.Command(sh, "packaging/build-deb.sh", "tree", arch, bin, version, out).CombinedOutput(); err != nil {
		t.Fatalf("build-deb.sh: %v\n%s", err, b)
	}
	return filepath.Join(out, "pkg-"+arch), ""
}

func readFile(t *testing.T, p string) string {
	t.Helper()
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestDebTreeControl(t *testing.T) {
	root, _ := buildTree(t, "armhf", "1.0.0-dev")
	c := readFile(t, filepath.Join(root, "DEBIAN/control"))
	fields := map[string]string{}
	for _, l := range strings.Split(c, "\n") {
		if l != "" && !strings.HasPrefix(l, " ") {
			k, v, _ := strings.Cut(l, ": ")
			fields[k] = v
		}
	}
	for _, k := range []string{"Package", "Version", "Architecture", "Maintainer", "Installed-Size", "Section", "Priority", "Description"} {
		if fields[k] == "" {
			t.Errorf("control lacks %s:\n%s", k, c)
		}
	}
	if fields["Package"] != "remoment-node" || fields["Architecture"] != "armhf" {
		t.Errorf("package/arch: %v", fields)
	}
	if fields["Version"] != "1.0.0~dev-1" {
		t.Errorf("a pre-release dash must become a tilde plus a revision: %q", fields["Version"])
	}
	if !regexp.MustCompile(`^[0-9][A-Za-z0-9.+~-]*$`).MatchString(fields["Version"]) {
		t.Errorf("not a Debian version: %q", fields["Version"])
	}
	if regexp.MustCompile(`@[A-Z]+@`).MatchString(c) {
		t.Errorf("unfilled placeholder:\n%s", c)
	}
	if !strings.Contains(c, "\n Registers") || !strings.HasSuffix(c, "\n") {
		t.Errorf("long description must be indented continuation lines, file ends with a newline")
	}
	root2, _ := buildTree(t, "arm64", "v1.2.3")
	if !strings.Contains(readFile(t, filepath.Join(root2, "DEBIAN/control")), "Version: 1.2.3-1\n") {
		t.Error("tag v1.2.3 must give 1.2.3-1")
	}
}

func TestDebTreeFiles(t *testing.T) {
	root, _ := buildTree(t, "arm64", "1.0.0")
	wantMode := map[string]os.FileMode{
		"usr/bin/remoment-node":                                            0o755,
		"lib/systemd/system/remoment-node.service":                         0o644,
		"lib/systemd/system/remoment-node.service.d/ripper.conf.disabled":  0o644,
		"lib/systemd/system/remoment-node.service.d/line-in.conf.disabled": 0o644,
		"etc/remoment-node/config.json":                                    0o644,
		"usr/share/doc/remoment-node/copyright":                            0o644,
		"DEBIAN/postinst":                                                  0o755,
		"DEBIAN/prerm":                                                     0o755,
		"DEBIAN/postrm":                                                    0o755,
		"DEBIAN/conffiles":                                                 0o644,
	}
	for f, m := range wantMode {
		st, err := os.Stat(filepath.Join(root, f))
		if err != nil {
			t.Errorf("missing %s", f)
			continue
		}
		if st.Mode().Perm() != m {
			t.Errorf("%s mode %o, want %o", f, st.Mode().Perm(), m)
		}
	}
	// every conffile is a file of the package
	for _, l := range strings.Fields(readFile(t, filepath.Join(root, "DEBIAN/conffiles"))) {
		if _, err := os.Stat(filepath.Join(root, l)); err != nil {
			t.Errorf("conffile %s not in the package", l)
		}
	}
	// the shipped config is valid, has no server (so the node discovers it) and no roles
	var c Config
	if err := json.Unmarshal([]byte(readFile(t, filepath.Join(root, "etc/remoment-node/config.json"))), &c); err != nil {
		t.Fatalf("config.json: %v", err)
	}
	if c.Server != "" || len(c.Roles) != 0 {
		t.Errorf("default config must have no server and no roles: %+v", c)
	}
	// the drop-ins are inert until the admin copies them (they widen the confinement)
	ents, _ := os.ReadDir(filepath.Join(root, "lib/systemd/system/remoment-node.service.d"))
	for _, e := range ents {
		if strings.HasSuffix(e.Name(), ".conf") {
			t.Errorf("drop-in %s is active by default", e.Name())
		}
	}
}

func TestDebUnitAndScripts(t *testing.T) {
	root, _ := buildTree(t, "armhf", "1.0.0")
	unit := readFile(t, filepath.Join(root, "lib/systemd/system/remoment-node.service"))
	for _, want := range []string{
		"ExecStart=/usr/bin/remoment-node -config /etc/remoment-node/config.json",
		"User=remoment", "SupplementaryGroups=audio cdrom", "StateDirectory=remoment-node", "StateDirectoryMode=0700",
		"NoNewPrivileges=yes", "ProtectSystem=strict", "RestartPreventExitStatus=2", "WantedBy=multi-user.target",
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q", want)
		}
	}
	if strings.Contains(unit, "DynamicUser") {
		t.Error("the packaged unit uses the remoment user, not DynamicUser")
	}
	sh, err := exec.LookPath("sh")
	if err != nil {
		t.Skip("no sh")
	}
	for _, s := range []string{"postinst", "prerm", "postrm"} {
		p := filepath.Join(root, "DEBIAN", s)
		if b, err := exec.Command(sh, "-n", p).CombinedOutput(); err != nil {
			t.Errorf("%s: %v\n%s", s, err, b)
		}
		if !strings.HasPrefix(readFile(t, p), "#!/bin/sh\nset -e\n") {
			t.Errorf("%s must start with #!/bin/sh and set -e", s)
		}
	}
	post := readFile(t, filepath.Join(root, "DEBIAN/postinst"))
	for _, want := range []string{"adduser --system --group", "audio cdrom", "systemctl enable remoment-node.service", "try-restart"} {
		if !strings.Contains(post, want) {
			t.Errorf("postinst lacks %q", want)
		}
	}
	var cmds []string
	for _, l := range strings.Split(post, "\n") {
		if !strings.Contains(l, "echo") { // the hint to the admin names the start command
			cmds = append(cmds, l)
		}
	}
	if regexp.MustCompile(`systemctl (start|restart)|enable --now`).MatchString(strings.Join(cmds, "\n")) {
		t.Error("postinst must enable but never start on install")
	}
	if !strings.Contains(readFile(t, filepath.Join(root, "DEBIAN/prerm")), "systemctl stop remoment-node.service") {
		t.Error("prerm must stop the service")
	}
}
