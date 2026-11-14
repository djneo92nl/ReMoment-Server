# Generated hardware table (workstream 9b)

`docs/user/hardware.md` lists which devices work and how well each is verified. The list is generated, not edited.

- **Models and drivers:** `config/devices.php` through `App\Domain\Device\DeviceModels`.
- **Capabilities:** `Capabilities::forDriver()` from the driver's contracts; the hardware-bound ones (`wireless_speakers`, `wired_speakers`) only for models that declare `wisa` / `speaker => external`, as `HardwareFeatures` does.
- **Verification:** `config/hardware-status.php`, the one hand-written file. `statuses` names the three levels (`verified` = verified on hardware, `spec` = built against the vendor spec, unverified, `fake` = fake-tested only); `drivers` has an entry per driver class, `models` optionally overrides one model. Each entry has a date and a one-line note. A status is `verified` only with a source in the docs or the device-lab notes; otherwise `spec`.
- **Renderer:** `App\Domain\Hardware\HardwareTable` (rows, markdown, JSON, and `render()` which swaps the block between `<!-- hardware-table:start -->` and `<!-- hardware-table:end -->`).

```bash
php artisan hardware:table                  # write the table into docs/user/hardware.md
php artisan hardware:table --format=json    # print the rows
php artisan hardware:table --check          # exit 1 when the committed page is stale
```

`tests/Feature/HardwareTableTest.php` runs the check and fails when a driver of `config/devices.php` has no status, so adding a model or driver forces a status entry and a regenerated page. When hardware is verified, change its entry (status, date, note) and run the command.

Not done: showing "untested model" in the setup wizard and the device-add page from the same data; a WiiM entry once its driver exists.
