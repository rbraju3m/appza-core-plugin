# Sourced by run.sh and the area files.

SITE=${A14_SITE:-http://www.tutor-lms-mobile.local}
CORE=${A14_CORE:-http://appza-core.local}
WP_PATH=${A14_WP_PATH:-/var/www/html/wordpress-project/tutor-lms-mobile}
WP_CLI=${A14_WP_CLI:-"php $WP_PATH/wp-content/plugins/appza-builder-capacitor/wp-cli.phar --path=$WP_PATH --skip-plugins=appza-builder,appza-builder-premium,lazytasks-premium,lazytasks-project-task-management,fcom-mobile --user=raju"}
MOBILE=${A14_MOBILE_REPO:-/var/www/html/appza-mobile}
PLUGIN=$(cd "$HERE/../.." && pwd)
API=$SITE/wp-json
PROXY=$API/appza/v1/preview/data
FCM=$API/fcom-mobile/api/v1

b64() { printf '%s' "$1" | base64 -w0; }
check() {
	if [ "$2" = "$3" ]; then echo "PASS $1"; else echo "FAIL $1 (got $2, want $3)"; FAILS=$((FAILS + 1)); fi
}
skip() { echo "SKIP $1"; SKIPS=$((SKIPS + 1)); }
section() { echo; echo "== $1"; }

fx() { A14_ACTION=$1 $WP_CLI eval-file "$HERE/fixtures.php" 2>/dev/null | grep '^{' | tail -1; }
js() { python3 -c "import json,sys; d=json.loads(sys.argv[1]); print(eval(sys.argv[2], {'d': d}))" "$1" "$2"; }

# Auth header for a role; RAJU / STUDENT hold "user:app-password".
auth() {
	case $1 in
		raju) echo "Authorization: Basic $(b64 "$RAJU")" ;;
		student) echo "Authorization: Basic $(b64 "$STUDENT")" ;;
		*) echo "X-A14-Guest: 1" ;;
	esac
}

# pread <who> <ds> [params-json] [extra curl args] — proxy body; `nocache` unless NOCACHE=0.
pread() {
	local nc=(--data-urlencode nocache=1)
	[ "${NOCACHE:-1}" = 0 ] && nc=()
	curl -sS -G "$PROXY" --data-urlencode "ds=$2" --data-urlencode "params=${3:-{\}}" \
		--data-urlencode mode=real "${nc[@]}" -H "$(auth "$1")" "${@:4}"
}
pcode() { curl -s -o /dev/null -w '%{http_code}' -G "$PROXY" --data-urlencode "ds=$1" --data-urlencode nocache=1 "${@:2}"; }
