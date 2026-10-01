#!/usr/bin/env bash
# Security regression suite (go-live A14): S1–S8 over HTTP. Usage: bash run.sh [a2 a3 …]
# Exit code = number of failures. See README.md.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
FAILS=0
SKIPS=0
. "$HERE/lib.sh"

AREAS=${*:-a2 a3 a4 a5 a7 a8 a6 builder}

cleanup() {
	echo
	echo "== cleanup"
	echo "restore student: $(fx restore | cut -c1-120)"
	echo "fixtures: $(fx cleanup)"
	[ -n "${MINTED:-}" ] && echo "app passwords: $(fx revoke)"
}
trap cleanup EXIT

if [ -n "${A14_RAJU_AUTH:-}" ] && [ -n "${A14_STUDENT_AUTH:-}" ]; then
	RAJU=$A14_RAJU_AUTH
	STUDENT=$A14_STUDENT_AUTH
	LOGOUT=${A14_LOGOUT_AUTH:-}
	LOGOUT_UUID=${A14_LOGOUT_UUID:-}
else
	CREDS=$(fx mint)
	if [ -z "$CREDS" ] || [ "$(js "$CREDS" "d.get('error', '')")" != "" ]; then
		echo "could not mint app passwords: ${CREDS:-no output from wp-cli}"
		exit 1
	fi
	MINTED=1
	RAJU="raju:$(js "$CREDS" "d['raju']")"
	STUDENT="appza_test_student:$(js "$CREDS" "d['student']")"
	LOGOUT="appza_test_student:$(js "$CREDS" "d['logout']")"
	LOGOUT_UUID=$(js "$CREDS" "d['logout_uuid']")
fi

fx create >/dev/null
S=$(fx state)
if [ -z "$S" ]; then
	echo "fixtures did not load"
	exit 1
fi

for area in $AREAS; do
	. "$HERE/$area.sh"
done

echo
echo "== $FAILS failed, $SKIPS skipped"
exit $FAILS
