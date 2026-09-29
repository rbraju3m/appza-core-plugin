# A4 / S3 — the FeedUpdate enricher writes nothing FC itself refused.
section "A4 FeedUpdate enricher"

A4=$(js "$S" "d['a4_feed_id']")
URL=$(js "$S" "d['a4_media_url']")
EVIL=${URL/${SITE#*://}/anything.example}
a4post() {
	curl -s -o /dev/null -w '%{http_code}' -X POST "$FCM/feeds/update" -H 'Content-Type: application/json' -H "$(auth "$1")" \
		-d "{\"id\":$A4,\"title\":\"A14 probe\",\"message\":\"[A14-PROBE] a4 edited\",\"media_images\":[{\"url\":\"$EVIL\"}]}"
}
a4state() { S4=$(fx state); echo "$(js "$S4" "d['a4_media_active']")/$(js "$S4" "d['a4_meta_preview']")"; }

fx a4-reset >/dev/null
check "guest edit refused" "$(a4post guest)" 401
check "guest: media inactive, meta untouched" "$(a4state)" "0/False"
c=$(a4post student)
check "non-owner edit refused" "$([ "$c" -ge 400 ] && echo yes || echo "no ($c)")" yes
check "non-owner: media inactive, meta untouched" "$(a4state)" "0/False"
check "owner edit accepted" "$(a4post raju)" 200
check "owner: image restored, meta rebuilt" "$(a4state)" "1/True"
