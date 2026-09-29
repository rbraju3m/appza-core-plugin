# A2 / S1 — the proxy's viewer is the authenticated user or a guest, nothing else.
section "A2 proxy identity"

has() { grep -o "$2" <<<"$1" | wc -l | tr -d ' ' | sed 's/^[1-9][0-9]*$/yes/;s/^0$/no/'; }
first() { python3 -c "import json,sys; d=json.load(sys.stdin); d=d[0] if isinstance(d,list) and d else d; print(d.get(sys.argv[1]) if isinstance(d,dict) else None)" "$1"; }

check "X-Appza-Auth wrong password -> 400" "$(pcode fc-current-user -H "X-Appza-Auth: Basic $(b64 raju:WRONG)")" 400
check "X-Appza-Auth with a valid password -> 400" "$(pcode fc-current-user -H "X-Appza-Auth: Basic $(b64 "$RAJU")")" 400
check "Authorization wrong password -> 401" "$(pcode fc-current-user -H "Authorization: Basic $(b64 raju:WRONG)")" 401
check "Authorization unknown user -> 401" "$(pcode fc-current-user -H "Authorization: Basic $(b64 nosuchuser:x)")" 401
for ds in fc-bookmarks fc-blocked-users fc-scheduled-posts; do
	check "guest $ds -> []" "$(pread guest $ds '{"member_id":1}')" "[]"
done
check "valid app password -> raju (user 1)" "$(pread raju fc-current-user | first user_id)" 1
check "raju's own profile carries his moderator verdict" "$(pread raju fc-member-profile '{"member_id":1}' | first viewer_is_moderator)" True
check "the student's does not" "$(pread student fc-member-profile '{"member_id":1}' | first viewer_is_moderator)" False

MAIN=$(js "$S" "d['main_id']")
SID=$(js "$S" "d['student_id']")
check "raju sees his marker bookmark" "$(has "$(pread raju fc-bookmarks)" "\"id\":$MAIN,")" yes
check "the student does not" "$(has "$(pread student fc-bookmarks)" "\"id\":$MAIN,")" no
check "raju sees his marker scheduled post" "$(has "$(pread raju fc-scheduled-posts)" A14-PROBE)" yes
check "the student cannot ask for raju's" "$(has "$(pread student fc-scheduled-posts '{"user_id":1}')" A14-PROBE)" no
check "raju sees the marker report" "$(has "$(pread raju fc-moderation-reports '{"status":"pending"}')" A14-PROBE)" yes
check "the student sees no reports" "$(has "$(pread student fc-moderation-reports '{"status":"pending"}')" A14-PROBE)" no
check "raju sees his block list" "$(has "$(pread raju fc-blocked-users '{"member_id":1}')" appza_test_student)" yes
check "the student cannot read raju's" "$(has "$(pread student fc-blocked-users '{"member_id":1}')" appza_test_student)" no

PT=$(js "$S" "d['private_thread']")
check "raju reads his private thread" "$(has "$(pread raju fc-chat-messages "{\"thread_id\":$PT}")" 'private message')" yes
check "the student cannot" "$(has "$(pread student fc-chat-messages "{\"thread_id\":$PT}")" 'private message')" no

LC=$(js "$S" "d['locked_course']")
LL=$(js "$S" "d['locked_lesson']")
if [ -n "$LC" ]; then
	P="{\"course_slug\":\"$LC\",\"lesson_slug\":\"$LL\"}"
	check "locked lesson: the student gets no body" "$(pread student fc-course-lesson "$P" | first content_html)" ""
	check "locked lesson: raju gets the body" "$(has "$(pread raju fc-course-lesson "$P" | first content_html)" '<')" yes
else
	skip "locked lesson: no APPZA-TEST-COURSE the student is not enrolled in"
fi

NOCACHE=0 pread raju fc-current-user >/dev/null
check "cached: raju gets raju" "$(NOCACHE=0 pread raju fc-current-user | first user_id)" 1
check "cached: the student gets the student" "$(NOCACHE=0 pread student fc-current-user | first user_id)" "$SID"
check "cached: a guest gets nobody" "$(NOCACHE=0 pread guest fc-current-user)" "[]"

check "proxy has no username lookup" "$(grep -c "get_user_by( 'login'" "$PLUGIN/src/Rest/PreviewProxyController.php")" 0
check "proxy never switches user" "$(grep -c 'wp_set_current_user' "$PLUGIN/src/Rest/PreviewProxyController.php")" 0
if [ -d "$MOBILE/packages/renderer/src" ]; then
	check "renderer never sends X-Appza-Auth" "$(grep -rl 'X-Appza-Auth' "$MOBILE/packages/renderer/src" | wc -l)" 0
else
	skip "renderer grep: no Mobile repo at $MOBILE"
fi
