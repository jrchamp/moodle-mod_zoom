# FormaSuisse vendored patch branch

Branch `formasuisse`, based on upstream release tag **v5.5.0** (`2f1e5a8`).
Consumed by `formasuisse/formasuisse_infra` → `docker/docker-moodle/Dockerfile`
at a pinned commit SHA (Renovate cannot track fork branches — the pin is bumped
manually).

Spec and measured evidence: [formasuisse_infra#783](https://github.com/formasuisse/formasuisse_infra/issues/783)
(pilot tests T0–T10 are normative). Ops docs: `docs/moodle-zoom.md` in the infra repo.

## Patch inventory

1. **`with_seat` guard** (`classes/webservice.php`, `db/upgrade.php`) — every
   license-gated Zoom write (meeting create, meeting update, meeting start,
   registrant-add) runs through `with_seat()`: verify/move the host's seat
   under a cluster-wide lock, protect fresh grants with a DB lease
   (`zoom_seat_lease`), never demote a host with a live meeting, refuse+alert
   when nothing is safely movable (reason `pool` or `quota`). Replaces the
   unsafe `get_least_recently_active_paid_user_id()` victim selection
   (upstream [#162](https://github.com/ncstate-delta/moodle-mod_zoom/issues/162)):
   missing `last_login_time` now means *oldest candidate*, not exempt, and a
   per-candidate `GET /users/{id}/meetings?type=live` check is the only
   trusted liveness signal (`last_login_time` is blind to ZAK starts).
2. **Symmetric licensing** — `update_meeting()` guards licensing like
   `create_meeting()` (upstream never re-licenses on update; any-field update
   under a Basic host silently strips registration — measured, T3).
3. **Read-back verification** — after every registration-bearing create/update,
   `GET /meetings/{id}` and fail the Moodle save loudly if Zoom silently
   dropped `approval_type`/`registration_url` or converted the meeting to PMI.
4. **Registrant path + browser join** — registrant creation is seat-guarded
   (T6: license-gated), and the activity page offers a direct
   web-client join button that preserves the personal `?tk=` token (the `/w/`
   launcher's own browser fallback drops it — measured, T9).
5. **Alert log lines** — single greppable prefix
   `mod_zoom_seat_alert reason=<...> host=<...> meeting=<...>` feeding the
   existing log→Grafana→Slack pipeline in the infra repo.

## Rebase policy

Manual. On a new upstream release: rebase this branch onto the release tag,
re-run the patch inventory review, update the base tag above, bump the pin in
`docker-moodle/Dockerfile`. Patches 1–3 are upstream-PR candidates for
upstream [#162](https://github.com/ncstate-delta/moodle-mod_zoom/issues/162).
