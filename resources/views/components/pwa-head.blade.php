{{-- Installable web app (docs/frontend/pwa.md): manifest, colours, icon, and the script that registers
     the service worker and shows opt-in alert notifications. Deferred, like live-scripts. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="{{ \App\Http\Controllers\PwaController::THEME }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<script defer src="{{ asset('js/remoment-pwa.js') }}"></script>
