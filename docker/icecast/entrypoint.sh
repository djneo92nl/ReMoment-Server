#!/bin/sh
# Icecast does not read the environment: fill the passwords in the template, then run in the foreground.
set -e
envsubst '${ICECAST_SOURCE_PASSWORD} ${ICECAST_ADMIN_USER} ${ICECAST_ADMIN_PASSWORD}' \
    < /etc/icecast2/icecast.xml.template > /tmp/icecast.xml
exec icecast2 -c /tmp/icecast.xml
