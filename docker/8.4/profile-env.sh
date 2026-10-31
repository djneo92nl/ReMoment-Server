#!/usr/bin/env bash
# Sourced by start-container before supervisord starts. Turns PROFILE (control | standard | full) into the START_*
# variables the supervisor programs read (`autostart=%(ENV_START_...)s` in supervisord*.conf): supervisor cannot read the
# app's config, so this table is the shell copy of `workers` in config/profile.php. tests/Unit/ProfileSupervisorTest.php
# fails when the two differ. An unknown PROFILE behaves as standard, like App\Domain\Profile\Profile::current().
#
# Standalone: `PROFILE=control bash docker/8.4/profile-env.sh` prints the effective programs.

PROFILE="$(printf '%s' "${PROFILE:-standard}" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
case "$PROFILE" in
    control | standard | full) ;;
    *)
        echo "profile-env: unknown PROFILE '$PROFILE', using standard" >&2
        PROFILE=standard
        ;;
esac
export PROFILE

# runs_in <profile>...: "true" when the active profile is one of them
runs_in() {
    local p
    for p in "$@"; do
        if [ "$p" = "$PROFILE" ]; then
            echo true
            return
        fi
    done
    echo false
}

# Program -> profiles that start it. Always started: php, queue (health,default), scheduler, listeners, radio-relay.
export START_HISTORY_QUEUE="$(runs_in control)"
export START_STORAGE_QUEUE="$(runs_in standard full)"
export START_HEAVY_QUEUE="$(runs_in standard full)"
export START_ANALYSIS_QUEUE="$(runs_in full)"
export START_STEMS_QUEUE="$(runs_in full)"

# Run directly (not sourced): print the result.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    echo "profile=$PROFILE"
    env | grep '^START_' | sort
fi
