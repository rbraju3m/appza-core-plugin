# D110 — the builder's layout endpoints: who may write, what they accept, versions, reset.
# Writes only to fluent-community-dark (the app runs on -default) and resets what it wrote.
section "builder layouts"

LT=fluent-community-dark
LAY=$API/appza/v1
SITE_A=site-00000000-0000-4000-8000-00000000000a
SITE_B=site-00000000-0000-4000-8000-00000000000b
LAST=$(mktemp)

lcode() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
lget() { curl -sS "$LAY/$1" -H "$(auth raju)"; }
# lput <path> <json body> [who] — prints the status code
lput() { curl -s -o "$LAST" -w '%{http_code}' -X PUT "$LAY/$1" -H "$(auth "${3:-raju}")" -H 'Content-Type: application/json' --data "$2"; }
last() { js "$(cat "$LAST")" "$1"; }
ldel() { curl -sS -X DELETE "$LAY/$1" -H "$(auth raju)" >/dev/null; }
# bootq <python expr over d> — the bootstrap is too big to pass as an argument.
bootq() { curl -sS "$API/appza/v1/bootstrap?template=$LT" | python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }

PARTS="screen-layout/$LT/home/body screen-layout/$LT/home/appbar chrome-layout/$LT/appbar chrome-layout/$LT/nav chrome-layout/$LT/drawer-menu"
existing=0
for p in $PARTS; do
	[ "$(js "$(lget "$p")" "d.get('version')")" != "None" ] && existing=1
done
if [ "$existing" = 1 ]; then
	skip "builder layouts: $LT already has a saved layout; not touching it"
else
	H=screen-layout/$LT/home/body
	g=$(lget "$H")
	check "GET: Core keys are derived" "$(js "$g" "[c['key'] for c in d['core']]")" "['fc-feed-toolbar', 'fc-post-card']"
	check "GET: no site layout yet" "$(js "$g" "(d['site'], d['version'], d['drift'])")" "(None, None, False)"
	check "GET: the palette carries placeable:any" "$(js "$g" "'fc-feed-banner' in d['palette']")" True

	check "guest cannot read" "$(lcode "$LAY/$H")" 401
	check "the student cannot read" "$(lcode "$LAY/$H" -H "$(auth student)")" 403
	check "the student cannot write" "$(lput "$H" '{"items":[],"base_version":null}' student)" 403
	check "unknown screen -> 404" "$(lcode "$LAY/screen-layout/$LT/nope/body" -H "$(auth raju)")" 404
	check "unknown template -> 404" "$(lcode "$LAY/screen-layout/nope/home/body" -H "$(auth raju)")" 404
	check "unknown part -> 404" "$(lcode "$LAY/screen-layout/$LT/home/nope" -H "$(auth raju)")" 404

	CV="(d['versions']['customizations_version'] if 'versions' in d else d.get('customizations_version'))"
	v0=$(bootq "$CV")
	BODY='{"base_version":null,"items":[
		{"key":"fc-post-card","props_override":{"show_menu":false}},
		{"key":"'$SITE_A'","appzet_slug":"fc-feed-banner","slot":"footer","visible_when":{"binding":"state.x","op":"==","value":1}},
		{"key":"'$SITE_B'","appzet_slug":"fc-feed-banner","visibility":"signed_in"},
		{"key":"fc-feed-toolbar"}]}'
	check "save: 200" "$(lput "$H" "$BODY")" 200
	check "save: version 1" "$(last "d['version']")" 1
	g=$(lget "$H")
	check "GET: the saved order" "$(js "$g" "[i['key'] for i in d['site']]")" "['fc-post-card', '$SITE_A', '$SITE_B', 'fc-feed-toolbar']"
	check "a site row is always main" "$(js "$g" "d['site'][1]['slot']")" main
	check "a raw visible_when is dropped" "$(js "$g" "'visible_when' in d['site'][1]")" False
	check "a preset becomes its clause" "$(js "$g" "d['site'][2]['visible_when']['binding']")" state.viewer.is_logged_in
	check "the same AppZet twice (not single)" "$(js "$g" "[i.get('appzet_slug') for i in d['site'][1:3]]")" "['fc-feed-banner', 'fc-feed-banner']"
	check "bootstrap: keyed <template>:<screen>" "$(bootq "len(d['customizations']['template_screen']['$LT:home']['placements']['items'])")" 4
	check "bootstrap: customizations_version bumped" "$(bootq "$CV > $v0")" True

	check "stale base (null) -> 409" "$(lput "$H" '{"items":[],"base_version":null}')" 409
	check "stale base (0) -> 409" "$(lput "$H" '{"items":[],"base_version":0}')" 409
	check "current base -> 200" "$(lput "$H" '{"items":[{"key":"fc-post-card"}],"base_version":1}')" 200
	check "version 2" "$(last "d['version']")" 2

	bad() { check "refused: $1" "$(lput "$H" "{\"base_version\":2,\"items\":$2}")" 422; }
	bad "outside the palette" '[{"key":"'$SITE_A'","appzet_slug":"fc-profile-about"}]'
	bad "a single AppZet twice" '[{"key":"fc-post-card"},{"key":"'$SITE_A'","appzet_slug":"fc-post-card"}]'
	bad "an unknown key" '[{"key":"bogus","appzet_slug":"fc-feed-banner"}]'
	bad "a duplicate key" '[{"key":"fc-post-card"},{"key":"fc-post-card"}]'
	bad "an unknown prop" '[{"key":"fc-post-card","props_override":{"no_such_prop":1}}]'
	bad "a non-exposed prop" '[{"key":"fc-post-card","props_override":{"comment_review_action_slug":"x"}}]'
	bad "a data source switch" '[{"key":"fc-post-card","props_override":{"data_source_slug":"fc-bookmarks"}}]'
	bad "a colour that is CSS" '[{"key":"fc-feed-toolbar","props_override":{"sort_text_color":"red;x:url(y)"}}]'
	bad "an unknown visibility" '[{"key":"fc-post-card","visibility":"admins"}]'
	bad "a layout it does not have" '[{"key":"fc-post-card","layout":"entity-card-compact"}]'
	bad "items not a list" '{"a":1}'
	check "refused: over 50 entries" "$(lput "$H" "{\"base_version\":2,\"items\":$(python3 -c "import json;print(json.dumps([{'key':'site-00000000-0000-4000-8000-%012d'%i,'appzet_slug':'fc-feed-banner'} for i in range(51)]))")}")" 422
	check "a 422 names the field" "$(last "list(d['data']['errors'].keys())")" "['items']"
	check "a refused save changes nothing" "$(js "$(lget "$H")" "d['version']")" 2

	# Drift: Core's list is not what the site saved against.
	A14_LAYOUT_TEMPLATE=$LT $WP_CLI eval-file "$HERE/builder-drift.php" >/dev/null 2>&1
	check "drift is reported" "$(js "$(lget "$H")" "d['drift']")" True

	check "generic endpoint refuses a layout column" "$(lcode -X POST "$LAY/customizations" -H "$(auth raju)" -H 'Content-Type: application/json' --data "{\"scope\":\"template_screen\",\"target_slug\":\"home\",\"target_slug_composite\":\"$LT\",\"target_column\":\"placements\",\"override_value\":{\"items\":[]}}")" 400

	check "chrome nav: one entry only" "$(lput "chrome-layout/$LT/nav" '{"base_version":null,"items":[{"key":"fc-bottom-nav"},{"key":"'$SITE_A'","appzet_slug":"fc-bottom-nav"}]}')" 422
	check "chrome appbar: saved" "$(lput "chrome-layout/$LT/appbar" '{"base_version":null,"items":[{"key":"'$SITE_A'","appzet_slug":"fc-feed-appbar"}]}')" 200
	check "chrome appbar: in bootstrap" "$(bootq "d['customizations']['template']['$LT']['appbar_placements']['items'][0]['appzet_slug']")" fc-feed-appbar
	check "screen appbar: saved" "$(lput "screen-layout/$LT/home/appbar" '{"base_version":null,"items":[{"key":"'$SITE_A'","appzet_slug":"fc-feed-appbar"}]}')" 200
	check "drawer menu: saved" "$(lput "chrome-layout/$LT/drawer-menu" '{"base_version":null,"items":[{"screen_slug":"feed","hidden":true,"label":"News"}]}')" 200
	check "drawer menu: a screen outside it is refused" "$(lput "chrome-layout/$LT/drawer-menu" '{"base_version":1,"items":[{"screen_slug":"nope"}]}')" 422

	for p in $PARTS; do ldel "$p"; done
	left=0
	for p in $PARTS; do
		[ "$(js "$(lget "$p")" "d.get('version')")" != "None" ] && left=1
	done
	check "reset: every row is gone" "$left" 0
	check "reset: bootstrap has no $LT layout" "$(bootq "'$LT:home' in (d.get('customizations') or {}).get('template_screen', {})")" False
fi

section "builder validator"
out=$($WP_CLI eval-file "$HERE/builder-validator.php" 2>/dev/null | grep -E '^(PASS|FAIL)')
echo "$out"
FAILS=$((FAILS + $(grep -c '^FAIL' <<<"$out")))
