import Foundation
import SwiftUI

@MainActor
final class Store: ObservableObject {
    @Published var serverURLString: String {
        didSet { UserDefaults.standard.set(serverURLString, forKey: "serverURL") }
    }
    @Published var devices: [DeviceSummary] = []
    @Published var selectedID: Int? {
        didSet {
            guard selectedID != oldValue else { return }
            UserDefaults.standard.set(selectedID, forKey: "deviceID")
            detail = nil
            sources = []
            Task { await refresh(full: true) }
        }
    }
    @Published var detail: DeviceDetail?
    @Published var volume: Double = 0
    @Published var muted = false
    @Published var sources: [AvailableSource] = []
    @Published var artworkBase: URL?
    @Published var error: String?
    @Published var finding = false

    var isVisible = false
    private var volumeHoldUntil = Date.distantPast
    private var pollTask: Task<Void, Never>?
    private let discovery = Discovery()

    init() {
        let defaults = UserDefaults.standard
        serverURLString = defaults.string(forKey: "serverURL") ?? "http://remoment.local"
        let saved = defaults.integer(forKey: "deviceID")
        selectedID = saved == 0 ? nil : saved
        startPolling()
    }

    var api: API? {
        guard let url = URL(string: serverURLString.trimmingCharacters(in: .whitespaces)), url.host != nil else { return nil }
        return API(baseURL: url)
    }

    var selected: DeviceSummary? { devices.first { $0.id == selectedID } }
    var capabilities: Set<String> { Set(detail?.capabilities ?? selected?.capabilities ?? []) }
    var isPlaying: Bool { (detail?.now_playing?.state ?? detail?.state) == "playing" }

    var artworkURL: URL? {
        guard let path = detail?.now_playing?.artwork?.proxy_320, let range = path.range(of: "/storage/") else { return nil }
        let tail = String(path[range.lowerBound...])
        let base = artworkBase ?? api?.baseURL
        return base.flatMap { URL(string: $0.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/")) + tail) }
    }

    var menuBarTitle: String {
        guard isPlaying, let title = detail?.now_playing?.title else { return "" }
        let text = [title, detail?.now_playing?.track?.artist?.name].compactMap { $0 }.joined(separator: " – ")
        return text.count > 40 ? String(text.prefix(39)) + "…" : text
    }

    // MARK: Polling

    func startPolling() {
        pollTask?.cancel()
        pollTask = Task { [weak self] in
            var tick = 0
            while !Task.isCancelled {
                guard let self else { return }
                await self.refresh(full: tick == 0)
                tick += 1
                try? await Task.sleep(nanoseconds: self.isVisible ? 2_000_000_000 : 10_000_000_000)
            }
        }
    }

    func popoverChanged(visible: Bool) {
        isVisible = visible
        if visible { Task { await refresh(full: true) } }
    }

    func refresh(full: Bool = false) async {
        guard let api else { error = "Invalid server address"; return }
        do {
            if full || devices.isEmpty {
                if artworkBase == nil, let info = try? await api.info(), let base = info.artwork_base_url {
                    artworkBase = URL(string: base)
                }
                devices = try await api.devices()
                if selectedID == nil || selected == nil {
                    let pick = devices.first { $0.state == "playing" } ?? devices.first { $0.state != "unreachable" } ?? devices.first
                    selectedID = pick?.id
                }
            }
            guard let id = selectedID else { error = nil; return }
            let detail = try await api.device(id)
            self.detail = detail
            error = nil
            if detail.capabilities.contains("volume_control"), detail.state != "unreachable", Date() > volumeHoldUntil {
                if let v = try? await api.volume(id) { volume = Double(v) }
                if let m = try? await api.mute(id) { muted = m }
            }
            if full, detail.capabilities.contains("source_activation"), detail.state != "unreachable" {
                sources = (try? await api.sources(id)) ?? sources
            }
        } catch {
            self.error = "Can't reach \(serverURLString)"
        }
    }

    // MARK: Commands

    private func run(_ work: @escaping (API, Int) async throws -> Void, then: Bool = true) {
        guard let api, let id = selectedID else { return }
        Task {
            do { try await work(api, id) } catch { self.error = error.localizedDescription }
            if then { try? await Task.sleep(nanoseconds: 400_000_000); await refresh() }
        }
    }

    func togglePlay() { let action = isPlaying ? "pause" : "play"; run { try await $0.action($1, action) } }
    func next() { run { try await $0.action($1, "next") } }
    func previous() { run { try await $0.action($1, "previous") } }

    func commitVolume() {
        volumeHoldUntil = Date().addingTimeInterval(3)
        let value = Int(volume.rounded())
        run({ try await $0.setVolume($1, value) }, then: false)
    }

    func toggleMute() {
        muted.toggle()
        volumeHoldUntil = Date().addingTimeInterval(3)
        let value = muted
        run({ try await $0.setMute($1, value) }, then: false)
    }

    func activate(_ source: AvailableSource) {
        sources = sources.map { AvailableSource(source_id: $0.source_id, friendly_name: $0.friendly_name, in_use: $0.source_id == source.source_id) }
        run { api, id in
            try await api.activateSource(id, source.source_id)
            let fresh = try? await api.sources(id)
            await MainActor.run { if let fresh { self.sources = fresh } }
        }
    }

    func findServer() {
        finding = true
        discovery.find { [weak self] url in
            guard let self else { return }
            self.finding = false
            if let url {
                self.serverURLString = url.absoluteString
                self.artworkBase = nil
                self.devices = []
                Task { await self.refresh(full: true) }
            } else {
                self.error = "No ReMoment server found on the network"
            }
        }
    }
}
