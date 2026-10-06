import Foundation

struct APIError: LocalizedError {
    let message: String
    var errorDescription: String? { message }
}

/// Thin client for the ReMoment REST API (`/api`, no authentication).
struct API {
    let baseURL: URL

    private static let session: URLSession = {
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 5
        return URLSession(configuration: config)
    }()

    private func url(_ path: String) -> URL {
        baseURL.appendingPathComponent("api" + path)
    }

    @discardableResult
    private func send(_ method: String, _ path: String, body: [String: Any]? = nil) async throws -> Data {
        var request = URLRequest(url: url(path))
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        let (data, response) = try await Self.session.data(for: request)
        if let http = response as? HTTPURLResponse, !(200..<300).contains(http.statusCode) {
            let message = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["message"] as? String
            throw APIError(message: message ?? "HTTP \(http.statusCode)")
        }
        return data
    }

    private struct Wrapped<T: Decodable>: Decodable { let data: T }

    func info() async throws -> ServerInfo {
        try JSONDecoder().decode(ServerInfo.self, from: await send("GET", "/info"))
    }

    func devices() async throws -> [DeviceSummary] {
        try JSONDecoder().decode(Wrapped<[DeviceSummary]>.self, from: await send("GET", "/devices")).data
    }

    func device(_ id: Int) async throws -> DeviceDetail {
        try JSONDecoder().decode(Wrapped<DeviceDetail>.self, from: await send("GET", "/devices/\(id)")).data
    }

    func volume(_ id: Int) async throws -> Int {
        struct R: Decodable { let volume: Int }
        return try JSONDecoder().decode(R.self, from: await send("GET", "/devices/\(id)/volume")).volume
    }

    func mute(_ id: Int) async throws -> Bool {
        struct R: Decodable { let muted: Bool }
        return try JSONDecoder().decode(R.self, from: await send("GET", "/devices/\(id)/mute")).muted
    }

    func sources(_ id: Int) async throws -> [AvailableSource] {
        struct R: Decodable { let sources: [AvailableSource] }
        return try JSONDecoder().decode(R.self, from: await send("GET", "/devices/\(id)/sources")).sources
    }

    func action(_ id: Int, _ action: String) async throws {
        try await send("POST", "/devices/\(id)/\(action)")
    }

    func setVolume(_ id: Int, _ volume: Int) async throws {
        try await send("PUT", "/devices/\(id)/volume", body: ["volume": volume])
    }

    func setMute(_ id: Int, _ muted: Bool) async throws {
        try await send("PUT", "/devices/\(id)/mute", body: ["muted": muted])
    }

    func activateSource(_ id: Int, _ sourceId: String) async throws {
        try await send("POST", "/devices/\(id)/sources/activate", body: ["source_id": sourceId])
    }
}
