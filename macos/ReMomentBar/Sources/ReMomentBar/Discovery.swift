import Foundation

/// Finds the server through its `_remoment._tcp` Bonjour advertisement.
final class Discovery: NSObject, NetServiceBrowserDelegate, NetServiceDelegate {
    private let browser = NetServiceBrowser()
    private var resolving: [NetService] = []
    private var completion: ((URL?) -> Void)?
    private var timeout: DispatchWorkItem?

    func find(timeout seconds: TimeInterval = 6, completion: @escaping (URL?) -> Void) {
        self.completion = completion
        browser.delegate = self
        browser.searchForServices(ofType: "_remoment._tcp.", inDomain: "local.")
        let work = DispatchWorkItem { [weak self] in self?.finish(nil) }
        timeout = work
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds, execute: work)
    }

    private func finish(_ url: URL?) {
        guard let completion else { return }
        self.completion = nil
        timeout?.cancel()
        browser.stop()
        resolving.forEach { $0.stop() }
        resolving.removeAll()
        completion(url)
    }

    func netServiceBrowser(_ browser: NetServiceBrowser, didFind service: NetService, moreComing: Bool) {
        resolving.append(service)
        service.delegate = self
        service.resolve(withTimeout: 4)
    }

    func netServiceDidResolveAddress(_ sender: NetService) {
        guard let host = sender.hostName?.trimmingCharacters(in: CharacterSet(charactersIn: ".")) else { return }
        var components = URLComponents()
        components.scheme = "http"
        components.host = host
        if sender.port != 80, sender.port > 0 { components.port = sender.port }
        finish(components.url)
    }
}
