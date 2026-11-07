# Action layer: design spike (workstream 14.5)

**Status (2026-11-07): spike done and the four additions of section 4 built (see "8. Built"); the prototype (`app/Domain/Actions/`, `ActionLayerSpikeTest`) was deleted on the owner's decision, the findings below stay.** Section 3 describes the prototype as it was; its tests no longer exist.

**Question.** Can an action ("move playback", "activate a source", "send a TV key", later "activate a scene") be defined once (typed input, validation rules, handler, result, name and description) and the REST route, the MCP tool, a scene step, the Home Assistant `rest_command` and the OpenAPI entry derived from it, instead of being written by hand in each place? Scenes (8.1) and the HA package (8a) are not built; they need the answer first.

## Answer in five lines

1. **Do not build an action registry for 1.0.** It can derive the REST route, the OpenAPI operation, the MCP schema, a scene-step schema and an HA snippet for simple actions (prototype: it does), but it saves ~30 lines per action against a ~290-line framework, cannot express what makes the real actions non-trivial (see "What a definition cannot say"), and does not touch the Livewire path, the docs or the tests, which are most of the duplication.
2. **Keep the REST API as the single definition (C), and make `openapi.yaml` the machine-readable index of it.** Scene steps and the HA package refer to an operation by its `operationId` and call it through the REST API (scenes: the existing in-process `ApiBridge`; HA: HTTP).
3. **Add four small things** (about two days): an operation-id convention and an allow-list of scene-capable operations, a contract test (routes ↔ `openapi.yaml`, strict-YAML parse), a generator for the HA package that reads `openapi.yaml`, and scene-step validation from the operation's request schema.
4. **A generator package (B, Scramble/Scribe) is not the source either**: it would replace a hand-written contract the iOS app is checked against with an inferred one, and it does not see error codes raised in catch blocks. It may be used as an optional drift detector (not run in this spike).
5. **Found on the way: `docs/api/openapi.yaml` is not valid YAML for a strict parser** (first error line 1266, plain scalars with `: `; more after it). Symfony YAML and PyYAML refuse it. The contract test would have caught it; fix it first (a real cost for the iOS generator).

## 1. Baseline: where three actions live today

Lines are the lines about this action (not the whole file). "Direct" = the web path calls the domain class or driver itself, not the REST API.

| Place | `move` (`POST /devices/{id}/move`) | `activate source` (`POST /devices/{id}/sources/activate`) | `remote key` (`POST /devices/{id}/keys`) |
|---|---|---|---|
| Route, `routes/api.php` | 1 | 1 | 2 (GET list + POST) |
| Controller + validation | `DeviceController::move` 25, with its guards and 3 catch blocks | `DeviceController::activateSource` 10 (`withDriver()`) | `RemoteKeysController` 42 (`press` ~17) |
| MCP tool (calls REST via `ApiBridge`) | `MovePlayback` 35 (schema 5, `validate()` 1, path + params 6, description 1) | one branch of the 39-line `Sources` tool (~4) | `RemoteKeys` 32 |
| OpenAPI (hand-written) | 39 | 20 | ~44 (GET + POST) |
| Web / Livewire (direct) | `Livewire\Concerns\MovesPlayback` 60 (+ `PlayerBar`) | `DeviceCard::activateSource` 10, `DeviceSourceManager::activateSource` ~15 | `RemoteCard::press` 18 |
| Docs rows | `playback-move.md` 26, `mcp.md` 1, CLAUDE.md 6 | CLAUDE.md 3 | `mcp.md` 1, CLAUDE.md 2 |
| MCP server registration | 1 | 0 (existing tool) | 1 |
| Tests | `MovePlaybackTest` 222 (13 tests), `MoveAndSyncMcpTest` 52 | 2 API tests (~10) | `RemoteKeysTest` 146 (9), `RemoteControlContractTest` 52 |
| Files touched to add it | ~11 | ~8 | ~10 |

What this shows:

- **MCP is not really duplicated.** `ApiBridge` dispatches the REST route in-process, so validation, capability checks, errors and the admin guard are the API's. A tool is a schema (field names, descriptions) plus a path. That is 30-35 lines, of which the field descriptions are real writing, and it is also where tools are *grouped*: `sources` and `queue` are one tool for several routes, which no per-route derivation produces.
- **The Livewire path is the real second implementation.** It does not go through REST or the controller; it calls `PlaybackMover` or the driver directly, with its own error handling (`DeviceCard::activateSource` swallows every `Throwable`). A definition would help only if the web path ran it too, which is the part that needs a result type instead of a `JsonResponse`.
- **OpenAPI is the biggest hand-written block per action** (20-44 lines) and the one that drifts: of 151 `/api` routes, 117 have an entry and **35 have none** (measured with `route:list` against the file; one entry, `/stream/{id}`, is not under `/api`).
- Docs and tests are about half of the lines and no definition removes them.

## 2. Evaluation

Package facts read from Packagist and the projects' release pages and docs on 2026-11-05. Latest-commit dates were not read; "release" is the freshness signal. Nothing was installed or run: this is from the packages' documentation, and everything marked *unverified* is untested against this code.

| | A. In-house action registry | B. Generate OpenAPI from code (Scramble, Scribe) | C. REST API is the single definition + conventions |
|---|---|---|---|
| What it is | `Action` class per action; generators for route, OpenAPI, MCP schema, scene-step schema, HA snippet | `dedoc/scramble` v0.13.53 (released 2026-11-03, MIT, PHP ^8.1, Laravel 10-13); `knuckleswtf/scribe` 5.11.0 (2026-06-08, MIT, Laravel 9-13) | `operationId` convention, an allow-list, a contract test, a generator reading `openapi.yaml` |
| New dependency | none | one dev dependency, or none if only used to check drift | none |
| Request body from code | from `rules()` (prototype: yes, types, `required`, defaults only by a separate `defaults()`) | both read inline `$request->validate([...])` and `FormRequest::rules()`; Scramble also `$request->integer()` calls; both skip rules built by concatenation or put in a variable before `validate` (Scramble warns about local variables) | hand-written, tested |
| Responses from code | declared in `outputs()` | Scramble infers from return statements, API Resources and `response()->json([...])`; Scribe needs `@response` examples or `@apiResource` | hand-written |
| Error shapes (`unsupported`, `not_movable` …) | `errors()` declares codes; the runner reproduces the guard order | Scramble documents `validate`, `abort`, and `@throws`; **catch-block answers like `response()->json(['error' => 'not_movable'], 422)` are not inferred** (not documented; *unverified* against this code); Scribe: manual only | hand-written (`DeviceError` component) |
| OpenAPI version | 3.0.3 as today | Scramble 3.1.0 by default (3.2 opt-in), `scramble:export` writes `api.json`; Scribe 3.0.3 or 3.1.0 (export command *unverified*) | 3.0.3 as today |
| operationId | from the definition | Scramble: from the route name, else controller + method, override with `@operationId`; Scribe: not found in its docs (*unverified*) | the file's camelCase ids (115 today) |
| Keeps hand-written prose, tags, shared components, descriptions | prose duplicated per action | no: output is inferred; descriptions move into PHPDoc; the file the iOS app is generated against would be replaced | yes |
| Scene step | derived schema | none | the operation's request schema from `openapi.yaml` |
| HA package | derived | from the exported file | derived from `openapi.yaml` |
| Livewire path | not helped unless web calls the runner | not helped | not helped |
| Cost to adopt | ~290 lines + migrate routes one at a time + a second source of truth while migrating | install, configure the 151 routes (controllers use `withDriver()` closures), annotate ~35 undocumented and the catch-block errors, and reconcile with the hand file | ~2 days |
| Risk | two definitions of the guard order; every capability quirk needs a hook | inferred contract differs silently; the 3.1 output vs 3.0.3 generators | hand-written file can still drift between test runs, narrowed by the test |

Laravel Data (`spatie/laravel-data` 4.23.1, 2026-10-26): Scramble reads Data objects only in its paid PRO tier, so it is no route to a typed action input here. `laravel/mcp` is already in use (v1.0.2 in the lock; latest v1.1.2, 2026-10-26, needs Laravel framework 12.41.1 or later: check the lock's framework version before updating).

## 3. Prototype

`app/Domain/Actions/` (about 290 lines of framework + 73 and 101 lines per action definition) and `tests/Feature/Actions/ActionLayerSpikeTest.php` (11 tests). Two definitions, `MovePlaybackAction` and `ActivateSourceAction`, hold: name, operationId, summary, description, path, `rules()`, defaults, field descriptions, output schema, error codes, contract and capability, reachability, handler. `Generators` derives: the OpenAPI operation, the MCP tool schema (the same `JsonSchema` types a `Tool::schema()` returns), a scene-step JSON Schema, an HA `rest_command` snippet and a Laravel route (registered by the tests on a throwaway `/api/_spike` prefix; real routes untouched). `ActionRunner` is the shared pipeline: validate, 503, 422 `unsupported`, handler, error mapping.

### Equivalence that holds (asserted by tests)

- **OpenAPI:** `operationId`, `summary`, request `required` list, request property names and types (including `stop_source` default `true`), and the whole 200 response property map are identical to the hand-written entries for both actions.
- **MCP (move):** field names, types, required flags and descriptions equal `MovePlayback::schema()`, except `device_id`'s description.
- **REST behaviour:** the derived route and the real controller give the same status and JSON for: move not movable (422 `not_movable`), missing target (validation), non-boolean `stop_source`, unknown target, unreachable target (503); activate source: ok, missing source (validation), unreachable device (both still try, `reachable: false`), a device without the capability (422 `unsupported`). Validation uses the same rules through `Validator`, so the same error bodies come out.

### Differences found

| Difference | Why |
|---|---|
| `Rule::notIn([$device->id])` (cannot move onto itself) became a handler check; real answer is a 422 with `errors`, derived is 422 `{error: invalid}` (asserted) | `rules()` has no access to the route's device; fixing it needs context-aware rules, which makes the OpenAPI generator unable to read them as data |
| `exists:devices,id` is carried over but OpenAPI cannot say it | rules map to types, `required` and `enum` only |
| Defaults (`stop_source` true) need a second method | a rule cannot state a default |
| Hand-written descriptions are longer prose than one string | a definition holds one text for all outputs; per-output wording (MCP says when to call, OpenAPI says what `skipped` means) would be three fields |
| MCP `device_id` description ("the device that plays now") is generic | same |
| `sources` is one MCP tool for list, activate and controls; the derived tool is `activate_source` | tool grouping is an authoring decision |
| Derived 200/422/502/503 responses vs hand-written 200/422/503 | the real file documents fewer errors than the controller can answer (the derived set is arguably more correct) |
| `tags`, the path-parameter block and component refs (`RemoteKey` enum) are not in a definition | file structure, not action data; `RemoteKey` as an enum rule would have to map to a component |
| The runner repeats `GuardsDeviceAccess::withDriver()` (private to API controllers) | the layer needs that trait moved out first |

### What a definition cannot say (or says only with hooks)

Error mapping that depends on exceptions thrown inside the handler (catch blocks like `NotMovableException`), capability checks through `withDriver()` including `SpotifyRouting::driverFor()` (the Spotify driver executes playback commands for a routed speaker) and `HardwareFeatures::allows()`, `reachable: false` quirks, admin-token gating by the `admin.token` middleware group (the definition has a flag, the route registration honours it, but token revocation and 401 bodies stay in the middleware), Livewire state (`$moved`, `$moveError`, the target picker `moveTargets()`), MQTT or cache side effects (they happen inside `PlaybackMover` and listeners, not in the action), the MCP grouping into multi-action tools, tool descriptions as prompts for an agent, and `GET` actions whose result is a resource shape (`DeviceDetailResource`), which a result array does not describe.

### Measured savings

Per additional simple action, the definition is 73-101 lines (about 60-80 without docblocks). It replaces the controller method (10-25), the route (1), the MCP schema (~30) and the OpenAPI block (20-39): about 60-95 lines. **Net saving 0-30 lines per action; roughly 30 on average, against ~290 lines of framework**, so break-even around ten migrated actions, and only for the simple ones. It saves no Livewire, docs or test lines. For an action like `move` (three catch blocks, a validation rule that needs the route device, hand-written prose, a Livewire twin) the saving is about zero.

## 4. Recommendation: C, with four small additions

Keep each REST operation as the definition of an action and make that definition addressable and checkable.

1. **Operation-id convention.** The `operationId` in `openapi.yaml` is the action's name for every consumer (`movePlayback`, `activateSource`, `pressRemoteKey`). One per route, camelCase, unique; a route without one is not scene-capable, not in the HA package and not in the contract. The MCP tool names stay as they are (they are grouped and written for agents).
2. **Contract test (the 8b test, extended):** (a) `openapi.yaml` parses with a strict YAML parser (today it does not); (b) every `/api` route with a documented operation exists and the other way round; (c) operationIds are unique; (d) each operation's request-body property names exist in the controller's validation (read through the route's `validate()` is not possible statically, so test it by calling the route with the documented example and asserting no 422 on a field); start with (a) to (c) and add (d) per area. Today 35 of 151 routes have no entry: the test starts with an explicit known-missing list that shrinks.
3. **Scene-capable operations: `config/scene-operations.php`**, a list of operationIds a scene step may call (device-scoped actions: `movePlayback`, `activateSource`, `pressRemoteKey`, play playlist/album/radio, volume, power, multiroom join, sync groups) with a label and which parameter is the device. A helper reads `openapi.yaml` for the request schema. Admin-token operations are not in the list (a scene runs for guests too).
4. **HA package generator** reading the same list and `openapi.yaml`: one `rest_command` per operation (URL from the path, payload from the request schema's property names, required first), written as a downloadable file. Prototype of the snippet shape is in `Generators::haRestCommand()` (*unverified in a running Home Assistant*). Scene activation (`POST /api/scenes/{id}/activate`) is itself an operation in the list.

### What a scene step is

`{ "operation": "movePlayback", "device_id": 3, "params": { "to_device_id": 5 } }`, stored as JSON on the scene row. **A scene step is an operation reference executed through `ApiBridge`.** The step then gets, for free and identically to a client calling the API: validation, capability checks, the `422 unsupported` and `503 unreachable` answers, `GuardsDeviceAccess` order, and Spotify routing. Alternatives and why not: a Laravel job per step (a second copy of the guard order and of error mapping, the thing the spike showed is the expensive part); an action class (option A, rejected above).

**Validation of a step:** at save time against the operation's request schema from `openapi.yaml` (required fields, types, `enum`); at run time by the route's own validation, whose failure is recorded per step in the scene's result (`{step, status, error}`), and a failing step does not stop the others unless the scene says so. A step with an unknown operationId is refused on save. The scene editor builds its form from the same schema (field list, types), so a new operation needs only a line in the allow-list.

Constraint to design in: the in-process `ApiBridge` forwards the caller's bearer token and uses `app('request')`; a scene run from the scheduler or MQTT has no request, so the activator must set up a clean request (the bridge already builds one; it only reads the token from the original request, which is null there).

## 5. Migration path (stops at any point)

Nothing has to be migrated for C. If the owner later wants A for some actions:

1. Pick one simple device action that has no catch blocks and no route-device rule (e.g. `activateSource`).
2. Move `GuardsDeviceAccess::withDriver()` into a shared class that both the trait and `ActionRunner` call (removes the duplicated guard order).
3. Write the definition; register its route in place of the controller route (`Generators::registerRoute`); keep the controller class until the test comparing both has run once (the test in this spike is the template).
4. Replace the hand OpenAPI block with the generated one (a `docs:openapi` command that fills marked sections of the file), keep everything else by hand.
5. Stop whenever the saving stops: a mixed repo (some routes from definitions, most controllers) works because the route table and the file are the only shared artefacts. Cost per route: ~1 hour for a simple one, half a day for one like `move`.

## 6. What we should NOT build

- A general action registry and generators for all 151 routes, or a base class every controller extends.
- Derivation of MCP tools (their grouping and descriptions are authored for agents; they already reuse REST through `ApiBridge`).
- A second validator for scene steps or HA payloads: the route's validation is the validator.
- A scene step as a Laravel job, event or action class that re-implements guard order and error mapping.
- Replacing `openapi.yaml` by an inferred spec (Scribe/Scramble) as the source; Scramble PRO for Laravel Data.
- Code generation of Livewire behaviour from definitions.
- An MQTT command path or HA `media_player` entities because actions exist (decisions of 8a stand).
- Per-action docs generation for the prose in `docs/api/*.md` and CLAUDE.md.

## 7. Open questions for the owner

1. **Fix `openapi.yaml` so it parses as strict YAML** (first at line 1266, a plain scalar containing `: `; more follow) as part of 8b? The iOS side generates from it; if its generator is lenient this has gone unseen. Proposed: yes, first, together with the strict-parse test.
2. Should the 35 undocumented routes be listed in a known-missing list in the contract test (shrinks over time) or documented now?
3. Scenes: may a step call any operation of the allow-list, or should it be a curated subset in the first version (suggested: device playback, volume, power, source, move, multiroom presets, sync groups, remote keys)?
4. Should the Livewire device pages move to the REST operations over time (so one path remains), or stay on the domain classes as today? The spike assumes they stay.
5. Does a scene run need the admin token in any case (e.g. a step that changes a setting)? Suggested no: only guest-level operations are scene-capable.
6. HA: is a downloadable YAML file enough, or should HA discover the operations by reading `GET /api/scene-operations` (a generated list, would let HA services be built on startup)? Unverified how HA custom services would consume it.

## 8. Built (2026-11-07)

The four additions of section 4, as small commits. No new Composer dependency.

| Addition | Where |
|---|---|
| `openapi.yaml` parses with a strict YAML parser | nine plain scalars quoted (lines 1266, 1348, 2186, 3214, 3256, 3257, 3278, 3389, 3403 of the file before commit `3b56b70`) and one duplicate path key merged (line 1970, below); the text is unchanged |
| Operation-id convention | every operation has a unique camelCase `operationId` (117 of 117; `listAlerts` and `acknowledgeAlert` were the two missing); see "Operation ids" |
| Contract test | `tests/Feature/ApiContractTest.php`, `tests/Support/openapi-known-missing.php` |
| Scene-capable operations | `config/scene-operations.php` (26 operations), `App\Domain\Scenes\SceneOperations` (list + `validateStep`), `App\Domain\Scenes\OpenApiSpec` (strict load, `$ref`s resolved, operation lookup, flattened inputs) |
| HA package | `php artisan ha:package [--output=] [--base-url=]`, `App\Domain\Scenes\HaPackage`; see [home-assistant.md](home-assistant.md) |

### What the strict parse found

Besides the quoting, a real bug: **`/library/playlists` was a path key twice** (the `post` of `createPlaylist` and the `get` of `listPlaylists`). A lenient parser keeps only the last one, so `POST /library/playlists` was invisible to every consumer that loaded the file. The two blocks are one path item now. Two kinds of quoted line also changed what a lenient parse made of them: `{ description: Validation error, or not_connected }` and `{ description: string or integer, depending on the field }` parsed as a description plus a junk key (`or not_connected: null`); quoted, they say what they meant. Symfony YAML and PyYAML (6.0.3, from an existing Python, nothing installed) both parse the file now. PyYAML is stricter in one place: a flow mapping whose plain scalar contains `: ` (`description: ... copy: the wish ...`) passes Symfony and fails PyYAML, so those two lines are quoted as well.

### Operation ids

- One per operation, **verb + resource in camelCase** (`movePlayback`, `activateSource`, `pressRemoteKey`, `setVolume`, `playAlbum`), unique across the file, matching `^[a-z][a-zA-Z0-9]*$`. Existing ids were kept; the names are a stable API for scene steps and the HA package, so renaming one is a breaking change (rename in the config and any stored scene too).
- A route without one is not scene-capable, not in the HA package and fails the contract test. MCP tool names are separate (they are grouped and written for agents).

### The contract test

`tests/Feature/ApiContractTest.php` (6 tests). **Public API = every route whose URI starts with `api/`** (the admin ones under `api/admin` included, they are documented with `security`); web pages, Livewire, `/mcp`, `/stream` and framework routes are not. It asserts: (a) the file parses strictly; (b) every `/api` route has an operation, except the entries of `tests/Support/openapi-known-missing.php` (`missing`); (c) every documented operation is an `/api` route, except `stale` entries, which must be served somewhere else; (d) unique camelCase operationIds; (e) the documented body fields of `movePlayback`, `activateSource` and `pressRemoteKey` reach the routes' validation (the routes are called with a payload built from the schema, through `FakePlayerDriver`, `FakeSourceDriver`, `FakeRemoteDriver`: no 422 on a documented field). **The lists only shrink**: a listed entry that is documented (or no longer routed) fails the test until removed, a new undocumented route fails it until documented.

**Numbers:** 117 documented operations (117 operationIds), 151 `/api` routes, **35 known-missing** (admin file and storage management 15, `bluetooth`/`info`/`network`/`sound-adjustment`/`wired-speakers`/`wireless-speakers` per-device settings 15, source controls 2, `GET /library/tracks/{id}/waveform`, `POST /librespot/event`, `GET /radio/{id}/now`), **1 known-stale** (`GET /stream/{track}` is documented under the `/api` server but served at `/stream/{track}`; changing the file's server base would change the iOS contract, so it is listed).

### Scene operations and the step validator

`config/scene-operations.php` lists the operationIds a scene step may call, with `label`, `device_param` (the input, a path parameter or a body property, that is the device; null when the operation is not about one device) and `enabled`. The first version is the curated guest-level subset of section 7 question 3: playback controls (`transport`, `setVolume`, `setMute`, `setShuffle`, `setRepeat`, `setPower`, `activateSource`, `movePlayback`), queue (`addToQueue`, `clearQueue`), multiroom and sync groups (`multiroomJoin`, `multiroomLeave`, `createSyncGroup`, `deleteSyncGroup`, `addSyncGroupMember`, `removeSyncGroupMember`), TV (`pressRemoteKey`, `sendDigits`) and play on a device (`playRadio`, `playPlaylist`, `playAlbum`, `playArtist`, `playTrack`, `playTracks`, `playListeningMode`, `playExternal`): 26. Not listed: anything with `security: adminToken` (a test checks the OpenAPI entry and the route's `admin.token` middleware for each listed operation), multiroom presets (web only, no REST operation) and scene activation (scenes are not built).

`SceneOperations::validateStep(array $step): array` returns `field => message` (empty = valid) for a step `{operation, device_id, params}`: unknown, unlisted or disabled operation; the device (`device_id`, integer, required exactly when `device_param` is set, never also in `params`); required inputs, unknown parameters, type, `enum`, `minimum`/`maximum`, `maxLength`, `pattern`, `minItems` and array items of the operation's path, query and body inputs. It is the save-time check; at run time the route's own validation stays the validator. The scene editor, the runner and the scene tables are not built.

### Owner's answers used (open questions of section 7)

The owner had not answered 1 to 6 when this was built; the defaults suggested above were implemented: (1) yes, fixed first; (2) known-missing list, may only shrink; (3) curated subset; (4) Livewire stays on the domain classes; (5) guest-level operations only; (6) a downloadable generated YAML file, no `GET /api/scene-operations`. The prototype question (7 of the task) was asked: delete, done.

### Unverified

The generated `rest_command` package has never been loaded by a running Home Assistant (the payload Jinja, `dict(p, key=value)` and `tojson`, follows HA's documentation). The contract test checks routes and three operations' request field names, not response shapes.
