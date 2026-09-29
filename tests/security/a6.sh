# A6 / S5–S6 — users/search and the portal guards match FC's own gates. Runs last: it blocks the student.
section "A6 search + portal guards"

SPACE=$(js "$S" "d['community_space']")
T6=$(mktemp)
code() { curl -s -o "$T6" -w '%{http_code}' -G "$FCM$1" "${@:2}"; }

check "student search, no space -> 403" "$(code /users/search -H "$(auth student)")" 403
check "student search in $SPACE (not admin) -> 403" "$(code /users/search --data-urlencode space_slug=$SPACE -H "$(auth student)")" 403
check "raju search in $SPACE -> 200" "$(code /users/search --data-urlencode space_slug=$SPACE --data-urlencode per_page=5 -H "$(auth raju)")" 200
for r in /leaderboard/ranks /members/directory /courses/catalog; do
	check "guest $r (public portal) -> 200" "$(code $r)" 200
done
check "student active: courses/catalog -> 200" "$(code /courses/catalog -H "$(auth student)")" 200
check "student active: invitations -> 200" "$(code /spaces/invitations --data-urlencode space_slug=$SPACE -H "$(auth student)")" 200

fx block >/dev/null
check "student is blocked for the next checks" "$(js "$(fx state)" "d['student_status']")" blocked
for r in /courses/catalog /spaces/invitations /spaces/documents /chat/thread /members/directory; do
	check "student blocked: $r -> 403" "$(code $r --data-urlencode space_slug=$SPACE --data-urlencode user_id=1 -H "$(auth student)")" 403
done
fx restore >/dev/null
check "student restored: courses/catalog -> 200" "$(code /courses/catalog -H "$(auth student)")" 200
rm -f "$T6"
