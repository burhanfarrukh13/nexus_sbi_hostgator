#!/usr/bin/env bash
#
# Nightly backup for the Nexus SBI HostGator account (run it ON the server, from cPanel Cron Jobs).
#
#   backup-hostgator.sh            make a backup (database dump + private uploads) and prune old ones
#   backup-hostgator.sh verify     check that the newest backups can be read back (no data is changed)
#
# Setup (once, over SSH / cPanel Terminal):
#   1. Create ~/.nexus-backup.cnf with chmod 600, so the password is never on a command line:
#        [client]
#        user=CPANELUSER_dbuser
#        password=THE_DB_PASSWORD
#        host=localhost
#   2. Upload this script to ~/nexus-api/scripts/ and run: chmod 700 ~/nexus-api/scripts/backup-hostgator.sh
#   3. Cron (cPanel > Cron Jobs), once a day at a quiet hour:
#        30 2 * * * DB_NAME=CPANELUSER_nexus ~/nexus-api/scripts/backup-hostgator.sh >> ~/logs/nexus-backup.log 2>&1
#
# Settings (environment variables):
#   DB_NAME               required - the cPanel database name (prefix included)
#   PRIVATE_STORAGE_PATH  default $HOME/private/nexus - must match the value in the production .env
#   BACKUP_DIR            default $HOME/backups/nexus - keep it OUTSIDE public_html and the API document root
#   MYSQL_DEFAULTS        default $HOME/.nexus-backup.cnf
#   KEEP_DAYS             default 14 - older backups are deleted
#
# Backups stay on the same account, so they protect against mistakes and bad deployments, not against
# losing the account. Download a copy off-server regularly (cPanel File Manager, SFTP, or rclone).

set -euo pipefail
umask 077

MODE="${1:-backup}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/nexus}"
PRIVATE_STORAGE_PATH="${PRIVATE_STORAGE_PATH:-$HOME/private/nexus}"
MYSQL_DEFAULTS="${MYSQL_DEFAULTS:-$HOME/.nexus-backup.cnf}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fail() { log "FAIL: $*"; exit 1; }

mkdir -p "$BACKUP_DIR"

if [ "$MODE" = "verify" ]; then
    latest_db="$(ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | head -n 1 || true)"
    latest_files="$(ls -1t "$BACKUP_DIR"/private-*.tar.gz 2>/dev/null | head -n 1 || true)"
    [ -n "$latest_db" ] || fail "no database backup found in $BACKUP_DIR"
    gzip -t "$latest_db" || fail "$latest_db is corrupt"
    gunzip -c "$latest_db" | grep -q 'CREATE TABLE `users`' || fail "$latest_db does not contain the users table"
    log "OK: $latest_db is readable and contains the schema"
    if [ -n "$latest_files" ]; then
        tar -tzf "$latest_files" > /dev/null || fail "$latest_files is corrupt"
        log "OK: $latest_files is readable"
    else
        log "WARN: no private-storage backup found"
    fi
    log "A real restore test is still required once: import the dump into a scratch database in phpMyAdmin and open the app against it."
    exit 0
fi

[ "$MODE" = "backup" ] || fail "unknown mode '$MODE' (use: backup | verify)"
[ -n "${DB_NAME:-}" ] || fail "DB_NAME is not set"
[ -r "$MYSQL_DEFAULTS" ] || fail "$MYSQL_DEFAULTS is missing (see the setup notes at the top of this script)"
command -v mysqldump > /dev/null || fail "mysqldump is not available on this account"

db_file="$BACKUP_DIR/db-$STAMP.sql.gz"
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS" --single-transaction --no-tablespaces --routines=false "$DB_NAME" \
    | gzip > "$db_file.partial" || { rm -f "$db_file.partial"; fail "mysqldump failed"; }
mv "$db_file.partial" "$db_file"
log "database backup written: $db_file ($(du -h "$db_file" | cut -f1))"

if [ -d "$PRIVATE_STORAGE_PATH" ]; then
    files_file="$BACKUP_DIR/private-$STAMP.tar.gz"
    tar -czf "$files_file.partial" -C "$(dirname "$PRIVATE_STORAGE_PATH")" "$(basename "$PRIVATE_STORAGE_PATH")" \
        || { rm -f "$files_file.partial"; fail "tar of $PRIVATE_STORAGE_PATH failed"; }
    mv "$files_file.partial" "$files_file"
    log "private storage backup written: $files_file ($(du -h "$files_file" | cut -f1))"
else
    log "WARN: $PRIVATE_STORAGE_PATH does not exist; uploads were not backed up"
fi

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'private-*.tar.gz' \) -mtime "+$KEEP_DAYS" -print -delete \
    | sed 's/^/pruned: /' || true

"$0" verify
