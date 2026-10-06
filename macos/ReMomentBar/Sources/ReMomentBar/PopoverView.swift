import SwiftUI

struct PopoverView: View {
    @EnvironmentObject var store: Store
    @State private var showSettings = false

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            header
            if showSettings {
                settings
            } else if store.devices.isEmpty {
                empty
            } else {
                nowPlaying
                transport
                if store.capabilities.contains("volume_control") { volume }
                if store.capabilities.contains("source_activation"), !store.sources.isEmpty { sourceMenu }
            }
            if let error = store.error {
                Text(error).font(.caption).foregroundStyle(.orange)
            }
            Divider()
            footer
        }
        .padding(16)
        .frame(width: 320)
        .onAppear { store.popoverChanged(visible: true) }
        .onDisappear { store.popoverChanged(visible: false); showSettings = false }
    }

    // MARK: Sections

    private var header: some View {
        HStack {
            if store.devices.count > 1 {
                Picker("", selection: $store.selectedID) {
                    ForEach(store.devices) { device in
                        Text(device.device_name + (device.state == "unreachable" ? " (offline)" : ""))
                            .tag(Optional(device.id))
                    }
                }
                .labelsHidden()
                .fixedSize()
            } else {
                Text(store.selected?.device_name ?? "ReMoment").font(.headline)
            }
            Spacer()
            if let state = store.detail?.state, state != "playing" {
                Text(state.capitalized).font(.caption).foregroundStyle(.secondary)
            }
        }
    }

    private var nowPlaying: some View {
        let playing = store.detail?.now_playing
        return HStack(spacing: 12) {
            AsyncImage(url: store.artworkURL) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                ZStack {
                    Color.secondary.opacity(0.15)
                    Image(systemName: "music.note").font(.title).foregroundStyle(.secondary)
                }
            }
            .frame(width: 84, height: 84)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            VStack(alignment: .leading, spacing: 3) {
                Text(playing?.title ?? "Nothing playing")
                    .font(.headline).lineLimit(2)
                if let subtitle = playing?.subtitle {
                    Text(subtitle).font(.subheadline).foregroundStyle(.secondary).lineLimit(1)
                }
                if let album = playing?.album?.name {
                    Text(album).font(.caption).foregroundStyle(.secondary).lineLimit(1)
                }
                if let position = playing?.position, let duration = playing?.track?.duration, duration > 0 {
                    ProgressView(value: min(position, duration), total: duration)
                        .padding(.top, 4)
                }
            }
            Spacer(minLength: 0)
        }
    }

    private var transport: some View {
        let enabled = store.capabilities.contains("media_controls") && store.detail?.state != "unreachable"
        return HStack(spacing: 28) {
            Spacer()
            Button(action: store.previous) { Image(systemName: "backward.fill") }
            Button(action: store.togglePlay) {
                Image(systemName: store.isPlaying ? "pause.fill" : "play.fill").font(.title)
            }
            Button(action: store.next) { Image(systemName: "forward.fill") }
            Spacer()
        }
        .font(.title2)
        .buttonStyle(.plain)
        .disabled(!enabled)
        .opacity(enabled ? 1 : 0.35)
    }

    private var volume: some View {
        HStack(spacing: 8) {
            Button(action: store.toggleMute) {
                Image(systemName: store.muted ? "speaker.slash.fill" : "speaker.fill").frame(width: 18)
            }
            .buttonStyle(.plain)
            Slider(value: $store.volume, in: 0...100) { editing in
                if editing { store.isVisible = true } else { store.commitVolume() }
            }
            Image(systemName: "speaker.wave.3.fill").foregroundStyle(.secondary)
            Text("\(Int(store.volume))").font(.caption.monospacedDigit()).frame(width: 24, alignment: .trailing)
        }
    }

    private var sourceMenu: some View {
        HStack {
            Text("Source").foregroundStyle(.secondary)
            Spacer()
            Menu {
                ForEach(store.sources) { source in
                    Button {
                        store.activate(source)
                    } label: {
                        if source.in_use { Label(source.friendly_name, systemImage: "checkmark") } else { Text(source.friendly_name) }
                    }
                }
            } label: {
                Text(store.sources.first { $0.in_use }?.friendly_name ?? "Choose…")
            }
            .fixedSize()
        }
    }

    private var empty: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(store.error == nil ? "No devices" : "Not connected").font(.headline)
            Text("Check the server address in settings, or find it automatically.")
                .font(.caption).foregroundStyle(.secondary)
        }
    }

    private var settings: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Server address").font(.caption).foregroundStyle(.secondary)
            TextField("http://remoment.local", text: $store.serverURLString)
                .textFieldStyle(.roundedBorder)
                .onSubmit { Task { await store.refresh(full: true) } }
            HStack {
                Button(store.finding ? "Searching…" : "Find automatically") { store.findServer() }
                    .disabled(store.finding)
                Button("Connect") { Task { await store.refresh(full: true) } }
            }
        }
    }

    private var footer: some View {
        HStack {
            Button { showSettings.toggle() } label: { Image(systemName: "gearshape") }
                .buttonStyle(.plain)
            Spacer()
            Button("Quit") { NSApplication.shared.terminate(nil) }
                .buttonStyle(.plain).foregroundStyle(.secondary)
        }
    }
}
