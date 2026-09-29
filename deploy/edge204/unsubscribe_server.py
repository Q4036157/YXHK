"""204 营销退订服务；仅依赖 Python 标准库。"""
import hashlib
import hmac
import html
import json
import os
import re
import secrets
import sqlite3
import time
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit


class Store:
    def __init__(self, path):
        self.path = path
        with self.connect() as db:
            db.executescript("""
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS tokens (
                    digest TEXT PRIMARY KEY, tenant TEXT NOT NULL, email TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS suppressions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL,
                    email TEXT NOT NULL, created INTEGER NOT NULL, UNIQUE(tenant,email));
            """)

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=10)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    @staticmethod
    def email(value):
        value = str(value).strip().lower()
        if len(value) > 254 or not re.fullmatch(r"[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+", value):
            raise ValueError("邮箱地址无效")
        return value

    def blocked(self, tenant, email):
        with self.connect() as db:
            return db.execute("SELECT 1 FROM suppressions WHERE tenant=? AND email=?", (tenant, email)).fetchone() is not None

    def issue(self, tenant, email):
        token = secrets.token_urlsafe(32)
        with self.connect() as db:
            db.execute("INSERT INTO tokens VALUES (?,?,?)", (hashlib.sha256(token.encode()).hexdigest(), tenant, email))
        return token

    def resolve(self, token):
        if not re.fullmatch(r"[A-Za-z0-9_-]{43}", token):
            raise ValueError("退订链接无效")
        with self.connect() as db:
            row = db.execute("SELECT tenant,email FROM tokens WHERE digest=?", (hashlib.sha256(token.encode()).hexdigest(),)).fetchone()
        if row is None:
            raise ValueError("退订链接无效")
        return row["tenant"], row["email"]

    def unsubscribe(self, token):
        tenant, email = self.resolve(token)
        with self.connect() as db:
            db.execute("INSERT OR IGNORE INTO suppressions(tenant,email,created) VALUES (?,?,?)", (tenant, email, int(time.time())))

    def events(self, tenant, after):
        with self.connect() as db:
            rows = db.execute("SELECT id,email,created FROM suppressions WHERE tenant=? AND id>? ORDER BY id LIMIT 200", (tenant, after)).fetchall()
        return [dict(row) for row in rows]

    def latest(self, tenant):
        with self.connect() as db:
            return db.execute("SELECT COALESCE(MAX(id),0) FROM suppressions WHERE tenant=?", (tenant,)).fetchone()[0]


def make_handler(store, config):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            # 不把邮箱、退订令牌或 Authorization 写入访问日志。
            pass

        def reply(self, status, body, content_type="application/json; charset=utf-8"):
            data = json.dumps(body, ensure_ascii=False).encode() if isinstance(body, dict) else body.encode()
            self.send_response(status)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(data)

        def page(self, title, content, status=200):
            self.reply(status, '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+title+'</title><body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:24px"><h1>'+title+'</h1>'+content+'</body></html>', "text/html; charset=utf-8")

        def tenant(self):
            supplied = self.headers.get("Authorization", "").removeprefix("Bearer ")
            for tenant, key in config["tenants"].items():
                if hmac.compare_digest(supplied, key):
                    return tenant
            return None

        def body(self):
            size = int(self.headers.get("Content-Length", "0"))
            if size < 0 or size > 4096:
                raise ValueError("请求过大")
            return self.rfile.read(size).decode("utf-8")

        def do_GET(self):
            self.handle_request(False)

        def do_POST(self):
            self.handle_request(True)

        def handle_request(self, post):
            try:
                url = urlsplit(self.path)
                if url.path == "/health" and not post:
                    with store.connect() as db:
                        db.execute("SELECT 1").fetchone()
                    self.reply(200, {"status": "ok"})
                    return
                if url.path == "/marketing/unsubscribe":
                    fields = parse_qs(self.body()) if post else parse_qs(url.query)
                    token = fields.get("token", parse_qs(url.query).get("token", [""]))[0]
                    store.resolve(token)
                    if post:
                        store.unsubscribe(token)
                        self.page("退订成功", "<p>您已退订此发送方的营销活动邮件，后续将不再向您发送。</p>")
                    else:
                        self.page("退订营销邮件", '<p>确认后，将停止接收此发送方的营销活动邮件。</p><form method="post" action="/marketing/unsubscribe"><input type="hidden" name="token" value="'+html.escape(token, quote=True)+'"><button type="submit">确认退订</button></form>')
                    return
                if url.path.startswith("/internal/"):
                    tenant = self.tenant()
                    if tenant is None:
                        self.reply(401, {"error": "未授权"})
                        return
                    if url.path == "/internal/events" and not post:
                        after = max(0, int(parse_qs(url.query).get("after", ["0"])[0]))
                        events = store.events(tenant, after)
                        self.reply(200, {"events": events, "cursor": events[-1]["id"] if events else min(after, store.latest(tenant))})
                        return
                    if url.path in ("/internal/check", "/internal/prepare") and post:
                        email = store.email(json.loads(self.body())["email"])
                        result = {"blocked": store.blocked(tenant, email)}
                        if url.path.endswith("prepare") and not result["blocked"]:
                            result["url"] = config["public_url"].rstrip("/")+"/marketing/unsubscribe?token="+store.issue(tenant, email)
                        self.reply(200, result)
                        return
                self.reply(404, {"error": "找不到入口"})
            except (ValueError, KeyError, IndexError, TypeError):
                self.page("无法退订", "<p>链接无效，请使用邮件中完整的退订链接。</p>", 400)
            except Exception:
                self.reply(503, {"error": "暂时无法处理，请稍后重试"})

    return Handler


if __name__ == "__main__":
    os.umask(0o077)
    with open(os.environ["YXHK_UNSUBSCRIBE_CONFIG"], encoding="utf-8") as file:
        settings = json.load(file)
    if not settings["public_url"].startswith("https://") or not settings["tenants"] or any(len(key) < 32 for key in settings["tenants"].values()):
        raise ValueError("公开 HTTPS 地址或租户密钥未配置")
    database = Store(settings["database"])
    server = ThreadingHTTPServer((settings.get("bind", "127.0.0.1"), settings.get("port", 3192)), make_handler(database, settings))
    server.daemon_threads = True
    server.serve_forever()
