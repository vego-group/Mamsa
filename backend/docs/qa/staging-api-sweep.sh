#!/usr/bin/env bash
#
# Mamsa — automated /api/v1 endpoint sweep against STAGING.
# Staging is the backend the public site (mamsaa.com) talks to, and it honors the
# fixed OTP 111222 — so no secrets are needed. Reads run live; writes are
# validation-only (expect 422) except a reversible favorite add->delete. All
# accounts are the public seeded test users (database/seeders/DevUsersSeeder.php).
#
# Usage:  bash backend/docs/qa/staging-api-sweep.sh
#
set -u
BASE="${API_BASE:-https://staging.mamsaa.com/api/v1}"
ORIGIN="${ORIGIN:-https://www.mamsaa.com}"
OTP="${OTP_FIXED_CODE:-111222}"
GUEST_PHONE="${GUEST_PHONE:-+966500000004}"
PARTNER_PHONE="${PARTNER_PHONE:-+966500000002}"
OUT="$(mktemp)"
trap 'rm -f "$OUT"' EXIT
pass=0; fail=0; TOKEN=""

hit() { # METHOD PATH EXPECT_REGEX [JSON_BODY]
  local method="$1" path="$2" expect="$3" body="${4:-}"
  local a=(-s -o "$OUT" -w '%{http_code}' -H 'Accept: application/json' -H "Origin: $ORIGIN" -X "$method")
  [ -n "$TOKEN" ] && a+=(-H "Authorization: Bearer $TOKEN")
  [ -n "$body" ] && a+=(-H 'Content-Type: application/json' -d "$body")
  local code; code=$(curl "${a[@]}" "$BASE$path")
  local mark="FAIL"; if [[ "$code" =~ $expect ]]; then mark="ok"; pass=$((pass+1)); else fail=$((fail+1)); fi
  printf '%-4s %-6s %-46s -> %s [%s]\n' "$mark" "$method" "$path" "$code" "$expect"
}
jval() { python3 -c "import json;d=json.load(open('$OUT'));print(eval(\"d$1\"))" 2>/dev/null; }

otp_login() { # phone -> TOKEN
  TOKEN=""
  curl -s -o /dev/null -H 'Content-Type: application/json' -H "Origin: $ORIGIN" \
    -X POST "$BASE/auth/request-otp" -d "{\"phone\":\"$1\"}"
  curl -s -o "$OUT" -H 'Content-Type: application/json' -H "Origin: $ORIGIN" \
    -X POST "$BASE/auth/verify-otp" -d "{\"phone\":\"$1\",\"code\":\"$OTP\"}"
  TOKEN=$(jval "['data']['access_token']"); [ "$TOKEN" = "None" ] && TOKEN=""
  [ -n "$TOKEN" ] && echo "  logged in ($1)" || { echo "  !! login failed $1"; head -c 200 "$OUT"; echo; }
}

echo "############ A. PUBLIC ############"
hit GET  /units '200'
UNIT=$(jval "['data'][0]['id']"); [ -z "$UNIT" -o "$UNIT" = "None" ] && UNIT=1
hit GET  /units/popular '200'
hit GET  /units/categories '200'
hit GET  /units/cities '200'
hit GET  /units/budgets '200'
hit GET  "/units/$UNIT" '200'
hit GET  "/units/$UNIT/reviews" '200'
hit POST "/units/$UNIT/availability" '(200|422)' '{"check_in":"2026-09-01","check_out":"2026-09-03"}'
hit GET  /offers '200'
hit GET  /testimonials '200'
hit POST /contact '(200|201|422)' '{}'
hit GET  /units/99999999 '404'

echo; echo "############ B. GUEST ($GUEST_PHONE) ############"
otp_login "$GUEST_PHONE"
hit GET  /auth/me '200'
hit GET  /user/profile '200'
hit GET  /user/bookings '200'
hit GET  /user/cards '200'
hit GET  /user/transactions '200'
hit GET  /user/favorites '200'
hit GET  /account '200'
hit POST   "/user/favorites/$UNIT" '(200|201|204|409)'
hit DELETE "/user/favorites/$UNIT" '(200|204)'
hit POST /bookings '(422|400)' '{}'
hit POST /reviews '(422|400)' '{}'
hit POST /payments/initiate '(422|400|404)' '{}'
hit GET  /payments/config '200'

echo; echo "############ C. PARTNER ($PARTNER_PHONE) ############"
otp_login "$PARTNER_PHONE"
hit GET  /partner/dashboard '(200|403)'
hit GET  /partner/profile '(200|403)'
hit GET  /partner/units '(200|403)'
PU=$(jval "['data'][0]['id']"); [ "$PU" = "None" ] && PU=""
[ -n "$PU" ] && hit GET "/partner/units/$PU" '200'
[ -n "$PU" ] && hit GET "/partner/units/$PU/calendar" '(200|404)'
hit GET  /partner/bookings '(200|403)'
hit GET  /partner/notifications '(200|403)'
hit GET  /partner/notifications/unread-count '(200|403)'
hit POST /partner/units '(422|400|403)' '{}'

echo; echo "############ D. LOGOUT ############"
hit POST /auth/logout '200'
echo; echo "=================================="
echo "PASS=$pass  FAIL=$fail"
[ "$fail" -eq 0 ]