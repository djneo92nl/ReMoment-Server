#!/bin/sh
# Build the package tree and, when asked, the .deb. Needs only POSIX sh, install, sed and (for the .deb) dpkg-deb.
#   build-deb.sh tree <deb-arch> <binary> <version> <outdir>   -> <outdir>/pkg-<arch>/ (DEBIAN/ and the files)
#   build-deb.sh deb  <deb-arch> <binary> <version> <outdir>   -> <outdir>/remoment-node_<version>_<arch>.deb
# <version> is the app version (1.0.0-dev); a pre-release dash becomes a tilde so it sorts before the release.
set -eu

mode=$1 arch=$2 bin=$3 version=$4 out=$5
here=$(cd "$(dirname "$0")" && pwd)
dver=$(printf '%s' "$version" | sed 's/^v//; s/-/~/')-1
root="$out/pkg-$arch"

rm -rf "$root"
install -d -m 0755 "$root/DEBIAN" "$root/usr/bin" "$root/lib/systemd/system/remoment-node.service.d" "$root/etc/remoment-node" "$root/usr/share/doc/remoment-node"
install -m 0755 "$bin" "$root/usr/bin/remoment-node"
install -m 0644 "$here/remoment-node.service" "$root/lib/systemd/system/remoment-node.service"
install -m 0644 "$here/ripper.conf.disabled" "$here/line-in.conf.disabled" "$root/lib/systemd/system/remoment-node.service.d/"
install -m 0644 "$here/config.json" "$root/etc/remoment-node/config.json"
install -m 0644 "$here/copyright" "$root/usr/share/doc/remoment-node/copyright"
install -m 0644 "$here/conffiles" "$root/DEBIAN/conffiles"
install -m 0755 "$here/postinst" "$here/prerm" "$here/postrm" "$root/DEBIAN/"

size=$(du -sk "$root" | cut -f1)
sed -e "s/@VERSION@/$dver/" -e "s/@ARCH@/$arch/" -e "s/@SIZE@/$size/" "$here/control.in" >"$root/DEBIAN/control"

if [ "$mode" = deb ]; then
	command -v dpkg-deb >/dev/null || { echo "dpkg-deb not found (apt install dpkg on Debian/Ubuntu)" >&2; exit 1; }
	dpkg-deb --root-owner-group -Zxz --build "$root" "$out/remoment-node_${dver}_${arch}.deb"
fi
