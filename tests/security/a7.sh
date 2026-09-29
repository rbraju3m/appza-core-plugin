# A7 / S7 — the JWT auth surface is off.
section "A7 JWT routes"

for r in login refresh logout guest; do
	check "POST /appza/auth/v1/$r -> 404" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/appza/auth/v1/$r" -H 'Content-Type: application/json' -d '{}')" 404
done
check "GET /appza/auth/v1/me -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$API/appza/auth/v1/me")" 404
check "junk Bearer on the proxy reads as a guest" "$(pread guest fc-current-user '{}' -H 'Authorization: Bearer junk.a14.probe')" "[]"
check "Core snapshot has no JWT route" "$(curl -s "$CORE/api/v1/catalog/snapshot?template=fluent-community-default" | grep -o 'appza\\/auth\\/v1' | wc -l)" 0
