#!/usr/bin/env bash
# Full-stack e2e of the conversational email channel against a local Mautic bench.
#
# The bench is a Mautic 7.2 checkout with the Meta, Inbox and Multi Mail plugins installed, served
# over HTTPS by a certificate from a local CA that is trusted in the system store (the plugin and
# this test only speak verified TLS). This script:
#   1. starts GreenMail (bin/greenmail.sh) with the account, lead and operator mailboxes;
#   2. points Mautic's own mailer at GreenMail, so the mobile magic code can be read back;
#   3. runs Tests/e2e/email-thread.php.
# It writes to the bench database. Never point it at a real instance.
#
# Env: MAUTIC_DIR (default ~/mautic-dev), MAUTIC_URL (default https://localhost:8443),
#      GREENMAIL_CA_DIR (CA with ca.crt + ca.key already trusted), E2E_OPERATOR.
set -euo pipefail
here="$(cd "$(dirname "$0")/../.." && pwd)"
export MAUTIC_DIR="${MAUTIC_DIR:-$HOME/mautic-dev}"
export MAUTIC_URL="${MAUTIC_URL:-https://localhost:8443}"
# A fresh operator per run: the magic code allows one request a minute and five an hour per address.
export E2E_OPERATOR="${E2E_OPERATOR:-atendente.$(openssl rand -hex 3)@example.test}"
operator_user="${E2E_OPERATOR%@*}"; operator_domain="${E2E_OPERATOR#*@}"
export GREENMAIL_USERS="contato:secret@macro.test,lead:secret@example.test,${operator_user}:secret@${operator_domain}"

case "$MAUTIC_URL" in https://localhost*|https://127.0.0.1*) ;; *) echo "Refusing to run against $MAUTIC_URL" >&2; exit 2 ;; esac

# GreenMail keeps mailboxes in memory: restart it so this run starts from empty mailboxes.
bash "$here/bin/greenmail.sh" stop >/dev/null 2>&1 || true
bash "$here/bin/greenmail.sh" start

# Mautic's transactional mailer -> GreenMail SMTPS (only for the magic code of the operator).
php -r '
$file = getenv("MAUTIC_DIR")."/config/local.php"; include $file;
$changed = ($parameters["mailer_dsn"] ?? null) !== "smtps://localhost:465" || empty($parameters["api_enabled"]);
$parameters["mailer_dsn"] = "smtps://localhost:465";
// Mautic treats every URL containing "/api" as an API call, the Inbox mobile gateway included.
$parameters["api_enabled"] = true;
$parameters["mailer_from_email"] = $parameters["mailer_from_email"] ?? "noreply@macro.test";
$parameters["mailer_from_name"] = $parameters["mailer_from_name"] ?? "Mautic";
$parameters["messenger_dsn_email"] = "sync://";
file_put_contents($file, "<?php\n\$parameters = ".var_export($parameters, true).";\n");
exit($changed ? 10 : 0);' && changed=0 || changed=$?
if [ "$changed" = 10 ]; then (cd "$MAUTIC_DIR" && php bin/console cache:clear -q); fi

E2E=1 php -d memory_limit=1G -d xdebug.mode=off "$here/Tests/e2e/email-thread.php"
