# A3 / S2 — no cross-user or host poisoning through the proxy cache.
section "A3 proxy cache"

M=a14-probe.invalid
rows() { js "$(fx transients)" "d['rows']"; }
gget() { curl -s -G "$PROXY" --data-urlencode "ds=$1" "${@:2}"; }

gget fc-feed --data-urlencode nocache=1 -H "X-Forwarded-Host: $M" >/dev/null
check "poisoned write does not reach the next guest" "$(gget fc-feed | grep -o "$M" | wc -l)" 0
check "unlisted forwarded host is not reflected" "$(gget fc-feed --data-urlencode nocache=1 -H "X-Forwarded-Host: $M" | grep -o "$M" | wc -l)" 0
host=${SITE#*://}
n=$(gget fc-feed --data-urlencode nocache=1 -H "X-Forwarded-Proto: https" | grep -oE "https:(\\\\?/){2}$host" | wc -l)
check "own host still rewritten (https)" "$([ "$n" -gt 0 ] && echo yes || echo "no ($n)")" yes

b=$(rows); gget zz-a14-probe-nonexistent >/dev/null; check "unknown ds not cached" "$(( $(rows) - b ))" 0
b=$(rows); gget fc-feed --data-urlencode template=a14-probe-tpl >/dev/null; check "bogus template not cached" "$(( $(rows) - b ))" 0
b=$(rows); gget fc-feed --data-urlencode mode=demo >/dev/null; check "demo mode not cached" "$(( $(rows) - b ))" 0
b=$(rows)
r=$(date +%s%N)
gget fc-space-members --data-urlencode "params={\"a14a\":$r,\"a14b\":2}" >/dev/null
gget fc-space-members --data-urlencode "params={\"a14b\":2,\"a14a\":$r}" >/dev/null
check "param key order shares one row" "$(( $(rows) - b ))" 1
