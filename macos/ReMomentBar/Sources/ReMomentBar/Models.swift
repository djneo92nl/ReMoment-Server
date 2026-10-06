import Foundation

struct DeviceSummary: Decodable, Identifiable, Equatable {
    let id: Int
    let device_name: String
    let state: String
    let capabilities: [String]
}

struct DeviceDetail: Decodable {
    let id: Int
    let device_name: String
    let state: String
    let capabilities: [String]
    let now_playing: NowPlaying?
}

struct NowPlaying: Decodable {
    struct Artist: Decodable { let name: String? }
    struct Track: Decodable {
        let name: String?
        let duration: Double?
        let artist: Artist?
    }
    struct Album: Decodable { let name: String? }
    struct Radio: Decodable { let name: String? }
    struct Source: Decodable { let name: String? }
    struct Artwork: Decodable { let proxy_320: String?; let proxy_512: String? }

    let track: Track?
    let album: Album?
    let radio: Radio?
    let source: Source?
    let state: String?
    let position: Double?
    let artwork: Artwork?

    var title: String? { track?.name ?? radio?.name ?? source?.name }
    var subtitle: String? {
        if let artist = track?.artist?.name { return artist }
        if track != nil { return nil }
        return radio != nil ? source?.name : nil
    }
}

struct AvailableSource: Decodable, Identifiable, Equatable {
    let source_id: String
    let friendly_name: String
    let in_use: Bool
    var id: String { source_id }
}

struct ServerInfo: Decodable {
    let name: String?
    let artwork_base_url: String?
}
