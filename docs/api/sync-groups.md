# Sync groups ("fake multiroom")

Server-side groups for devices without native multiroom: a leader plays, the members follow it (same queue, state, progress within about two seconds). Concept and limits: `docs/architecture/sync-groups.md`. Guest-accessible like the rest of the API. A device reports the capability `sync_group` when it can follow (plays library tracks or radio).

Shape of a group: `{ "id": 1, "name": "Downstairs"|null, "leader": { "id": 3, "name": "Kitchen" }, "members": [ { "id": 5, "name": "Bedroom", "offset_ms": 0 } ] }`.

```
GET    /api/sync-groups                                 → { data: [Group, …] }
POST   /api/sync-groups                                 { leader_device_id, member_device_ids: [..], name? }   → 201 { data: Group, failed: [{ device_id, message }] }
DELETE /api/sync-groups/{group}                         { stop_members?: bool = true }                          → { status: "ok" }
POST   /api/sync-groups/{group}/members                 { device_id }                                           → 201 { data: Group, failed: [..] }
PUT    /api/sync-groups/{group}/members/{device}        { offset_ms: -30000..30000 }                            → { status: "ok", offset_ms }
DELETE /api/sync-groups/{group}/members/{device}        { stop?: bool = true }                                  → { status: "ok" }
```

- Making a group, or adding a member, starts the members at once when the leader is playing something (`failed` lists members that could not start, with the reason); otherwise they start when the leader does.
- `422 invalid`: a device is already in a group, the leader is also a member, a member can't play the library or radio, or the Spotify device leads.
- Removing the last member ends the group. `stop_members` / `stop` stop the devices that stop following.
- `offset_ms` positive: the member plays ahead of the leader. Effective in whole seconds.
- Spotify can't be shared: a member is not started while the leader plays Spotify (`failed`).

MCP: the `sync_group` tool (list, create, add, remove, offset, dissolve).
