# Home Assistant package (`GET /api/admin/ha/package`)

The `rest_command` package for Home Assistant as a download, behind the admin token (see [admin-auth.md](admin-auth.md)). It is the **same YAML** `php artisan ha:package` writes (`App\Domain\Scenes\HaPackage`; what it contains is in [home-assistant.md](../architecture/home-assistant.md#generated-rest_command-package)); the web button is on **Settings › MQTT** (`GET /settings/mqtt/home-assistant/package`, signed in).

```
GET /api/admin/ha/package?base_url=http://remoment.local
```

- `base_url` (optional): the server origin Home Assistant calls, without `/api`; `http` or `https`, otherwise `422`. Default `APP_URL`, else `http://remoment.local`. It must be an address **Home Assistant** can reach (not `localhost` if HA runs elsewhere).
- Answers `200` with `Content-Type: application/yaml` and `Content-Disposition: attachment; filename="remoment.yaml"`. Not JSON, so it is not in the MCP tools.
- Only guest-level operations are in the file (no admin token to configure in HA).
- **Not verified in a running Home Assistant**, as the command (see home-assistant.md).
