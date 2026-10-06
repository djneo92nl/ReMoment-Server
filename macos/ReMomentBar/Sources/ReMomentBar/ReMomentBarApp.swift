import SwiftUI

@main
struct ReMomentBarApp: App {
    @StateObject private var store = Store()

    var body: some Scene {
        MenuBarExtra {
            PopoverView()
                .environmentObject(store)
        } label: {
            HStack(spacing: 4) {
                Image(systemName: store.isPlaying ? "hifispeaker.fill" : "hifispeaker")
                if !store.menuBarTitle.isEmpty { Text(store.menuBarTitle) }
            }
        }
        .menuBarExtraStyle(.window)
    }
}
