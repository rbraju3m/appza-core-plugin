# Friendly v2 P2 — the site's remembered template and the builder tour: who may read / write, what is accepted.
# Restores the option and raju's tour flag to what they were.
section "site template (P2)"

ST=$API/appza/v1/site-template
TOUR=$API/appza/v1/onboarding/tour-seen
scode() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
before=$(curl -sS "$ST" -H "$(auth raju)")
was_saved=$(js "$before" "d.get('saved')")
was_tpl=$(js "$before" "d.get('template')")

check "guest cannot read the site template" "$(scode "$ST" -H "$(auth guest)")" "401"
check "a member cannot read it" "$(scode "$ST" -H "$(auth student)")" "403"
check "a member cannot set it" "$(scode -X POST "$ST" -H "$(auth student)" --data template=fluent-community-default)" "403"
check "an unknown template is refused" "$(scode -X POST "$ST" -H "$(auth raju)" --data template=a14-probe-nope)" "400"
check "a template of a platform the site did not pick is refused" \
	"$(scode -X POST "$ST" -H "$(auth raju)" --data template=woocommerce-default)" "400"
check "the admin can set an offered template" "$(scode -X POST "$ST" -H "$(auth raju)" --data template=fluent-community-default)" "200"
check "it is remembered" "$(js "$(curl -sS "$ST" -H "$(auth raju)")" "(d['template'], d['saved'])")" "('fluent-community-default', True)"
check "guest cannot mark the tour seen" "$(scode -X POST "$TOUR" -H "$(auth guest)")" "401"
CC=$API/appza/v1/core-connection
check "guest cannot read the Core connection" "$(scode "$CC" -H "$(auth guest)")" "401"
check "a member cannot change the Core address" "$(scode -X POST "$CC" -H "$(auth student)" --data url=https://a14-probe.example)" "403"
check "a bad Core address is refused" "$(scode -X POST "$CC" -H "$(auth raju)" --data url=ftp://a14-probe)" "400"

if [ "$was_saved" = True ]; then
	curl -sS -X POST "$ST" -H "$(auth raju)" --data "template=$was_tpl" >/dev/null
else
	A14_ACTION=p2_forget $WP_CLI eval-file "$HERE/fixtures.php" >/dev/null 2>&1
fi
