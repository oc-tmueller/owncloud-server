#!/usr/bin/env bash
# Application-layer readiness polls. Container "healthy" precedes full app
# readiness, so we poll the actual interfaces the test relies on.
#
# Source common.sh before this file.

# ownCloud Classic: occ runs and the instance reports installed.
wait_oc10() {
  log "waiting for ownCloud Classic to be installed..."
  retry 60 5 _oc10_installed
}
_oc10_installed() {
  occ status 2>/dev/null | grep -q 'installed: true'
}

# oCIS: Graph API answers with admin credentials.
wait_ocis() {
  log "waiting for oCIS graph API..."
  retry 60 5 _ocis_graph_up
}
_ocis_graph_up() {
  in_ocis sh -c "curl -ksf -u '${OCIS_ADMIN}:${OCIS_PW}' https://localhost:9200/graph/v1.0/users >/dev/null"
}

# oCIS auth-app: minting a token must NOT 502 (502 == app-auth disabled). This
# catches a misconfigured auth-app env early instead of failing mid-migration.
wait_ocis_authapp() {
  log "waiting for oCIS auth-app (token mint)..."
  retry 30 5 _ocis_authapp_up
}
_ocis_authapp_up() {
  local code
  code=$(in_ocis sh -c "curl -ks -o /dev/null -w '%{http_code}' -u '${OCIS_ADMIN}:${OCIS_PW}' -X POST 'https://localhost:9200/auth-app/tokens?userName=${OCIS_ADMIN}&expiry=1h'")
  [ "$code" = "200" ] || [ "$code" = "201" ]
}
