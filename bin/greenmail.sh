#!/usr/bin/env bash
# GreenMail for the conversational email tests: SMTPS 465 and IMAPS 993 on 127.0.0.1.
#
# The plugin only speaks verified TLS (there is no "insecure" switch on purpose), so the
# server certificate comes from a local CA trusted in the system store:
#   - GREENMAIL_CA_DIR with ca.crt + ca.key: reuse that CA (it must already be trusted);
#   - otherwise a throw-away CA is generated in $GREENMAIL_DIR/ca and trusted with
#     update-ca-certificates (passwordless sudo, as on GitHub runners and the WSL bench).
# 465/993 are what the connection validator accepts, so Java runs through sudo.
#
# Usage: bin/greenmail.sh start|stop|status
# Env:   GREENMAIL_JAR    (default ~/.cc/tools/greenmail-standalone.jar)
#        GREENMAIL_DIR    (default ${TMPDIR:-/tmp}/multimail-greenmail)
#        GREENMAIL_CA_DIR (optional, see above)
#        GREENMAIL_USERS  (default contato:secret@macro.test,lead:secret@example.test)
set -euo pipefail

JAR="${GREENMAIL_JAR:-$HOME/.cc/tools/greenmail-standalone.jar}"
DIR="${GREENMAIL_DIR:-${TMPDIR:-/tmp}/multimail-greenmail}"
USERS="${GREENMAIL_USERS:-contato:secret@macro.test,lead:secret@example.test}"
PASSWORD=multimail-greenmail
MARKER=-Dmultimail.greenmail=1

ports_open() {
  # Listening sockets only: a plain TCP probe would show up as a failed TLS handshake in the log.
  ss -Hltn 'sport = :465' | grep -q . && ss -Hltn 'sport = :993' | grep -q .
}

certificates() {
  mkdir -p "$DIR"
  local ca="${GREENMAIL_CA_DIR:-$DIR/ca}"
  if [ ! -f "$ca/ca.crt" ] || [ ! -f "$ca/ca.key" ]; then
    [ -n "${GREENMAIL_CA_DIR:-}" ] && { echo "GREENMAIL_CA_DIR needs ca.crt and ca.key" >&2; exit 1; }
    mkdir -p "$ca" && chmod 700 "$ca"
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=Multi Mail GreenMail Test CA" \
      -keyout "$ca/ca.key" -out "$ca/ca.crt" >/dev/null 2>&1
    sudo -n cp "$ca/ca.crt" /usr/local/share/ca-certificates/multimail-greenmail-test-ca.crt
    sudo -n update-ca-certificates >/dev/null
  fi
  if [ ! -f "$DIR/greenmail.p12" ]; then
    openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout "$DIR/server.key" -out "$DIR/server.csr" >/dev/null 2>&1
    printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n' > "$DIR/server.ext"
    openssl x509 -req -in "$DIR/server.csr" -CA "$ca/ca.crt" -CAkey "$ca/ca.key" -CAcreateserial -days 30 \
      -extfile "$DIR/server.ext" -out "$DIR/server.crt" >/dev/null 2>&1
    openssl pkcs12 -export -in "$DIR/server.crt" -inkey "$DIR/server.key" -certfile "$ca/ca.crt" -name greenmail \
      -out "$DIR/greenmail.p12" -passout "pass:$PASSWORD"
  fi
}

case "${1:-}" in
  start)
    if ports_open; then echo "GreenMail already listening on 465/993"; exit 0; fi
    [ -f "$JAR" ] || { echo "GreenMail jar not found: $JAR" >&2; exit 1; }
    certificates
    sudo -n nohup java "$MARKER" -Dgreenmail.hostname=127.0.0.1 \
      -Dgreenmail.smtps.port=465 -Dgreenmail.imaps.port=993 \
      -Dgreenmail.users="$USERS" -Dgreenmail.users.login=email \
      -Dgreenmail.tls.keystore.file="$DIR/greenmail.p12" -Dgreenmail.tls.keystore.password="$PASSWORD" \
      -jar "$JAR" >"$DIR/greenmail.log" 2>&1 &
    for _ in $(seq 1 60); do
      if ports_open; then echo "GreenMail up: smtps 127.0.0.1:465, imaps 127.0.0.1:993 (log $DIR/greenmail.log)"; exit 0; fi
      sleep 0.5
    done
    echo "GreenMail did not start; see $DIR/greenmail.log" >&2
    tail -20 "$DIR/greenmail.log" >&2 || true
    exit 1
    ;;
  stop)
    sudo -n pkill -f -- "$MARKER" || true
    for _ in $(seq 1 20); do ports_open || { echo "GreenMail stopped"; exit 0; }; sleep 0.5; done
    echo "GreenMail still listening" >&2; exit 1
    ;;
  status)
    if ports_open; then echo "running"; else echo "stopped"; exit 1; fi
    ;;
  *)
    echo "Usage: $0 start|stop|status" >&2; exit 2
    ;;
esac
