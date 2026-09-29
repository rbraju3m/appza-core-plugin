# A5 / S4 — chat media and thread deletes respect ownership.
section "A5 chat ownership"

DM=$(js "$S" "d['dm_id']")
GR=$(js "$S" "d['group_id']")
BAIT="http://x.example/img.png?media_key=$(js "$S" "d['a5_key']")"
T5=$(mktemp)
# Our chat routes answer 200 with the real status in response.status; prefer that.
jpost() {
	local h
	h=$(curl -s -o "$T5" -w '%{http_code}' -X POST "$API$1" -H "$(auth student)" -H 'Content-Type: application/json' -d "$2")
	python3 -c "import json,sys; d=json.load(open(sys.argv[2])); print((d.get('response') or {}).get('status', sys.argv[1]) if isinstance(d, dict) else sys.argv[1])" "$h" "$T5" 2>/dev/null || echo "$h"
}
jfield() { python3 -c "import json,sys; print(eval(sys.argv[2], {'d': json.load(open(sys.argv[1]))}))" "$T5" "$1"; }

check "our send refuses another user's media" "$(jpost /fcom-mobile/api/v1/chat/messages/send "{\"thread_id\":$DM,\"text\":\"\",\"media\":[\"$BAIT\"]}")" 403
check "FC's own chat route refuses it too" "$(jpost /fluent-community/v2/chat/messages/$DM "{\"text\":\"a14\",\"mediaItems\":[\"$BAIT\"]}")" 403
check "the bait is still raju's feed image" "$(js "$(fx state)" "d['a5_owner_source']")" "1/feed"

PNG=$(mktemp --suffix=.png)
python3 -c "import zlib,struct,sys;c=lambda t,d:struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d));open(sys.argv[1],'wb').write(b'\x89PNG\r\n\x1a\n'+c(b'IHDR',struct.pack('>IIBBBBB',1,1,8,2,0,0,0))+c(b'IDAT',zlib.compress(b'\x00\xff\x00\x00'))+c(b'IEND',b''))" "$PNG"
check "own upload accepted" "$(curl -s -o "$T5" -w '%{http_code}' -X POST "$FCM/chat/messages/upload?thread_id=$DM" -H "$(auth student)" -F "file=@$PNG;type=image/png")" 200
URL5=$(jfield "d['data']['url']")
check "own upload can be sent" "$(jpost /fcom-mobile/api/v1/chat/messages/send "{\"thread_id\":$DM,\"text\":\"[A14-PROBE] a5\",\"media\":[\"$URL5\"]}")" 200
MID=$(jfield "d['data']['id']")
check "own message removed" "$(jpost /fcom-mobile/api/v1/chat/messages/remove "{\"message_id\":$MID}")" 200
check "own image swept with it" "$(jfield "d['data']['media_swept']")" 1

check "group thread delete refused" "$(jpost /fcom-mobile/api/v1/chat/threads/delete "{\"thread_id\":$GR}")" 400
check "group thread still there" "$(js "$(fx state)" "d['group_id']")" "$GR"
check "DM delete by a participant works" "$(jpost /fcom-mobile/api/v1/chat/threads/delete "{\"thread_id\":$DM}")" 200
rm -f "$PNG" "$T5"
