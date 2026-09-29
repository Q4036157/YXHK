#!/usr/bin/env bash
set -euo pipefail
# 只安装营销退订服务及精确 Nginx 路由，不重启 API 站。
release=$1
site=/etc/nginx/conf.d/bepusdt.conf
rollback_site="$site.yxhk-$(date +%Y%m%d%H%M%S).bak"
old_release=$(readlink -f /opt/yxhk-unsubscribe/current || true)
rollback() {
    cp -p "$rollback_site" "$site"
    if test -n "$old_release"; then
        ln -sfn "$old_release" /opt/yxhk-unsubscribe/current
        systemctl restart yxhk-unsubscribe || true
    fi
    nginx -t && systemctl reload nginx || true
}
test -f "$release/unsubscribe_server.py"
test -f "$site"
id yxhk-unsubscribe >/dev/null 2>&1 || useradd --system --home /var/lib/yxhk-unsubscribe --shell /usr/sbin/nologin yxhk-unsubscribe
install -d -m 750 -o yxhk-unsubscribe -g yxhk-unsubscribe /etc/yxhk-unsubscribe /var/lib/yxhk-unsubscribe
if ! test -f /etc/yxhk-unsubscribe/config.json; then
    python3 - <<'PY'
import json, secrets, os
os.umask(0o077)
with open('/etc/yxhk-unsubscribe/config.json','w') as f:
    json.dump({'public_url':'https://zmx.xyz.hr','database':'/var/lib/yxhk-unsubscribe/records.sqlite','tenants':{'owner':secrets.token_urlsafe(48)}}, f)
PY
fi
chown yxhk-unsubscribe:yxhk-unsubscribe /etc/yxhk-unsubscribe/config.json
chmod 600 /etc/yxhk-unsubscribe/config.json
install -m 644 "$release/yxhk-unsubscribe.service" /etc/systemd/system/yxhk-unsubscribe.service
install -m 644 "$release/public-location.conf" /etc/nginx/snippets/yxhk-unsubscribe-public.conf
install -m 644 "$release/internal-server.conf" /etc/nginx/conf.d/yxhk-unsubscribe-internal.conf
cp -p "$site" "$rollback_site"
trap rollback ERR
python3 - "$site" <<'PY'
from pathlib import Path
import sys
path=Path(sys.argv[1])
text=path.read_text()
line='    include /etc/nginx/snippets/yxhk-unsubscribe-public.conf;'
if line not in text:
    import re
    pattern=r'(server\s*\{\s*listen 443[^;]*;[\s\S]*?server_name zmx\.xyz\.hr;)'
    updated,count=re.subn(pattern,lambda m:m.group(0)+'\n'+line,text,count=1)
    if count!=1: raise RuntimeError('未找到 204 真模型 HTTPS server，停止修改')
    path.write_text(updated)
PY
nginx -t
ln -sfn "$release" /opt/yxhk-unsubscribe/current
systemctl daemon-reload
systemctl enable --now yxhk-unsubscribe
systemctl restart yxhk-unsubscribe
systemctl reload nginx
for attempt in {1..15}; do
    if curl -fsS http://127.0.0.1:3192/health; then exit 0; fi
    sleep 1
done
exit 1
