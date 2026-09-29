# A8 / S8 — logout revokes, comment HTML is sanitized, release builds are locked down.
section "A8 mobile auth hygiene"

if [ -n "$LOGOUT" ] && [ -n "$LOGOUT_UUID" ]; then
	LH="Authorization: Basic $(b64 "$LOGOUT")"
	check "throwaway password works before logout" "$(pcode fc-current-user -H "$LH")" 200
	check "logout accepted" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$FCM/logout" -H "$LH" -H 'Content-Type: application/json' -d "{\"uuid\":\"$LOGOUT_UUID\"}")" 200
	check "the same header after logout -> 401" "$(pcode fc-current-user -H "$LH")" 401
else
	skip "logout revoke: no throwaway password (set A14_LOGOUT_AUTH + A14_LOGOUT_UUID)"
fi

MAIN=$(js "$S" "d['main_id']")
XSS='[A14-PROBE] xss <img src=x onerror=alert(1)>'
curl -s -o /dev/null -X POST "$FCM/feeds/comments/create" -H "$(auth raju)" -H 'Content-Type: application/json' \
	-d "$(python3 -c "import json,sys; print(json.dumps({'feed_id': int(sys.argv[1]), 'comment': sys.argv[2]}))" "$MAIN" "$XSS")"
C=$(pread raju fc-post-comments "{\"feed_id\":$MAIN}")
R=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d=d if isinstance(d,list) else []; m=[c for c in d if 'A14-PROBE' in str(c.get('message_rendered',''))]; print(len(m), sum('onerror' in str(c.get('message_rendered','')) for c in m))" "$C")
check "marker comment is readable" "${R% *}" 1
check "its message_rendered has no onerror" "${R#* }" 0

SRC=$MOBILE/packages
if [ -d "$SRC/mobile/src" ]; then
	check "no HTML sink fed from a raw .message" "$(grep -rnE '__html:[^}]*\.message([^_a-zA-Z]|$)' "$SRC/mobile/src" "$SRC/plugin-admin/src" "$SRC/renderer/src" | wc -l)" 0
	check "no window.__appzaAuthHeader" "$(grep -rn '__appzaAuthHeader' "$SRC/mobile/src" "$SRC/renderer/src" | wc -l)" 0
	check "release build: cleartext off" "$(grep -A1 'release {' "$SRC/mobile/android/app/build.gradle" | grep -c 'usesCleartextTraffic: "false"')" 1
	check "allowBackup off" "$(grep -c 'android:allowBackup="false"' "$SRC/mobile/android/app/src/main/AndroidManifest.xml")" 1
	check "mixed content only on request" "$(grep -c "allowMixedContent: process.env.APPZA_ALLOW_MIXED_CONTENT === '1'" "$SRC/mobile/capacitor.config.ts")" 1
else
	skip "Mobile repo checks: no Mobile repo at $MOBILE"
fi
