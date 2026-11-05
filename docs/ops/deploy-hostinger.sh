#!/bin/bash
# Deploy one git revision of the Waoo CRM to Hostinger (owner 2026-11-05). Run from a local checkout.
#   docs/ops/deploy-hostinger.sh test <rev> "<label>"   → crm-test.waooparty.com
#   docs/ops/deploy-hostinger.sh real <rev> "<label>"   → crm.waooparty.com (only with César's «súbelo» for the real CRM)
# Steps: archive → upload → database backup → new release dir with the old .env and build → composer → swap → keep storage
# → migrate → copy public assets (keeps the web root's own .htaccess and index.php) → clear caches → keep 3 old releases.
set -euo pipefail
TARGET="${1:?test|real}"; REV="${2:?git revision}"; LABEL="${3:-$REV}"
case "$TARGET" in
  test) APP=crm-test-app; WEB=crm-test; BK=backups-crm-test; HOST=crm-test.waooparty.com ;;
  real) APP=crm-app; WEB=crm; BK=backups-crm; HOST=crm.waooparty.com
        read -r -p "Esto sube $REV al CRM REAL. Escribe SUBIR para seguir: " OK; [ "$OK" = "SUBIR" ] || exit 1 ;;
  *) echo "test|real"; exit 1 ;;
esac
SSH=(ssh -o ServerAliveInterval=30 -p 65002 -i "$HOME/.ssh/waoo_hostinger" u506936325@217.196.54.217)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
SHORT=$(git rev-parse --short "$REV")
git archive --format=tar.gz -o "$TMP/deploy-$SHORT.tgz" "$SHORT"
scp -q -P 65002 -i "$HOME/.ssh/waoo_hostinger" "$TMP/deploy-$SHORT.tgz" "u506936325@217.196.54.217:$BK/deploy-$SHORT.tgz"
"${SSH[@]}" "set -e; TS=\$(date +%Y%m%d-%H%M%S); cd ~/domains/waooparty.com; NEW=$APP-new-\$TS
echo \"before: \$(cat $APP/REV 2>/dev/null)\"
bash ~/bin/waoo-backup.sh ~/domains/waooparty.com/$APP $WEB-predeploy >/dev/null && echo 'backup ok'
mkdir \$NEW && tar xzf ~/$BK/deploy-$SHORT.tgz -C \$NEW
cp $APP/.env \$NEW/.env; chmod 600 \$NEW/.env; cp -R $APP/public/build \$NEW/public/build 2>/dev/null || true
cd \$NEW && composer install --no-dev --no-interaction --prefer-dist -q 2>&1 | tail -2; php artisan filament:assets -q; cd ..
mv $APP $APP-old-\$TS && mv \$NEW $APP
rm -rf $APP/storage && mv $APP-old-\$TS/storage $APP/storage
cd $APP && php artisan migrate --force 2>&1 | tail -3
cp -R public/css public/js public/build public/fonts public/img ../public_html/$WEB/ 2>/dev/null || true
php artisan optimize:clear -q; echo '$SHORT ($LABEL)' > REV; echo \"after: \$(cat REV)\"
cd ..; ls -1dt $APP-old-* | tail -n +4 | xargs -r rm -rf
cd ~/$BK; ls -1t deploy-*.tgz | tail -n +3 | xargs -r rm -f" < /dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/login"); echo "login $code"; [ "$code" = 200 ]
