# WiiM fixtures

Every file here is the example response of "HTTP API for WiiM Products", version 1.2 (https://www.wiimhome.com/pdf/HTTP%20API%20for%20WiiM%20Products.pdf, read 2026-11-15), reduced to the fields the driver reads; quirks such as the leading space in `" status"` and the trailing spaces in `"albumArtURI "` / `"sampleRate "` are kept on purpose. URLs lost their spaces in the PDF text layer. None of them was recorded from a real device: replace them with `php artisan wiim:probe` output when someone has one.
