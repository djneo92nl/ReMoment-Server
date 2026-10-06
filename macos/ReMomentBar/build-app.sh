#!/bin/bash
# Builds ReMomentBar.app (a menu bar app) into ./build — no Xcode needed, only the Command Line Tools.
set -euo pipefail
cd "$(dirname "$0")"
swift build -c release
APP=build/ReMomentBar.app
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS"
cp .build/release/ReMomentBar "$APP/Contents/MacOS/ReMomentBar"
cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleIdentifier</key><string>nl.remoment.ReMomentBar</string>
  <key>CFBundleName</key><string>ReMoment</string>
  <key>CFBundleExecutable</key><string>ReMomentBar</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>1.0</string>
  <key>LSMinimumSystemVersion</key><string>13.0</string>
  <key>LSUIElement</key><true/>
  <key>NSLocalNetworkUsageDescription</key><string>ReMoment finds and talks to your ReMoment server on the local network.</string>
  <key>NSBonjourServices</key><array><string>_remoment._tcp</string></array>
  <key>NSAppTransportSecurity</key><dict><key>NSAllowsLocalNetworking</key><true/><key>NSAllowsArbitraryLoads</key><true/></dict>
</dict></plist>
PLIST
codesign --force --sign - "$APP" >/dev/null 2>&1 || true
echo "Built $APP"
