{{-- MQTT-over-WebSocket push updates (public/js/remoment-live.js). Deferred so
     they run before the Vite module that starts Livewire/Alpine. --}}
<meta name="remoment-mqtt-ws" content="{{ config('mqtt.ws_url') }}">
<script defer src="https://cdn.jsdelivr.net/npm/mqtt@5.16.0/dist/mqtt.min.js"></script>
<script defer src="{{ asset('js/remoment-live.js') }}"></script>
