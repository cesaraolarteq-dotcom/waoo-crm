#!/bin/bash
# Daily encrypted database backup for the Waoo CRM (owner 2026-11-01).
# Usage (hPanel cron, once a day): bash ~/bin/waoo-backup.sh ~/domains/waooparty.com/<app-dir> <label>
# - dump with a consistent snapshot, gzip, encrypt with AES-256 (passphrase file ~/.waoo-backup-pass, chmod 600,
#   copy kept in the owner's Mac Keychain), checksum in a manifest;
# - keeps the last 14 daily files here; copies to Google Drive when an rclone remote "waoo-drive" exists.
set -euo pipefail
APP="${1:?app directory}"; LABEL="${2:-crm}"
OUT="$HOME/backups-$LABEL-daily"; PASS="$HOME/.waoo-backup-pass"
umask 077; mkdir -p "$OUT"
[ -s "$PASS" ] || { echo "missing $PASS" >&2; exit 1; }
env_get() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST=$(env_get DB_HOST); DB_DATABASE=$(env_get DB_DATABASE); DB_USERNAME=$(env_get DB_USERNAME); DB_PASSWORD=$(env_get DB_PASSWORD)
STAMP=$(date -u +%Y%m%d-%H%M%S); FILE="$OUT/db-$LABEL-$STAMP.sql.gz.enc"
CNF=$(mktemp); trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USERNAME" "$DB_PASSWORD" > "$CNF"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB_DATABASE" \
  | gzip -6 | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$PASS" -out "$FILE"
# Proof it opens: decrypt, decompress and require the dump's completion line.
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$PASS" -in "$FILE" | gunzip | tail -1 | grep -q 'Dump completed' \
  || { echo "backup check failed: $FILE" >&2; exit 1; }
echo "$(sha256sum "$FILE" | cut -d' ' -f1)  $(basename "$FILE")  $(stat -c %s "$FILE")" >> "$OUT/MANIFEST"
find "$OUT" -name "db-$LABEL-*.sql.gz.enc" -mtime +14 -delete
# Off-site copy (Google Drive of txwaooparty@gmail.com, drive.file scope); a failed upload never fails the local backup.
RCLONE="$HOME/bin/rclone"
if [ -x "$RCLONE" ] && "$RCLONE" listremotes 2>/dev/null | grep -qx 'waoo-drive:'; then
  "$RCLONE" copy "$FILE" "waoo-drive:Waoo CRM backups/$LABEL" --no-traverse -q || echo "drive upload failed: $(basename "$FILE")" >&2
  "$RCLONE" delete "waoo-drive:Waoo CRM backups/$LABEL" --min-age 30d --include "db-$LABEL-*.sql.gz.enc" -q || true
fi
echo "ok $(basename "$FILE")"
