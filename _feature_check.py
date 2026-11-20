import os
import re
import sqlite3
import tomllib

root = r"D:\weather.yousb.top"
issues = []

with open(os.path.join(root, 'config.toml'), 'rb') as f:
    cfg = tomllib.loads(f.read().decode('utf-8'))
with open(os.path.join(root, 'example_config.toml'), 'rb') as f:
    tpl = tomllib.loads(f.read().decode('utf-8'))
print('config keys:', len(cfg), '| template keys:', len(tpl), '| diff:', sorted(set(cfg) ^ set(tpl)) or '无')
print('site_icon:', cfg.get('site_icon'), '| admin_email:', repr(cfg.get('admin_email')))

# 新库 schema
sql = open(os.path.join(root, 'schema.sql'), encoding='utf-8').read()
con = sqlite3.connect(':memory:')
con.executescript(sql)
cols = [r[1] for r in con.execute('PRAGMA table_info(users)')]
print('new users columns:', cols)
for name in ['email', 'account_state', 'warning', 'warned_at']:
    if name not in cols:
        issues.append('schema 缺少 users.' + name)

# 旧库 v2 -> v3 迁移模拟
old = sqlite3.connect(':memory:')
old.executescript('''
CREATE TABLE users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 username TEXT NOT NULL UNIQUE,
 password_hash TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'user',
 status INTEGER NOT NULL DEFAULT 1,
 fingerprint TEXT DEFAULT '',
 last_ip TEXT DEFAULT '',
 last_login_at TEXT DEFAULT '',
 created_at TEXT NOT NULL
);
CREATE INDEX idx_users_role ON users (role);
CREATE INDEX idx_users_status ON users (status);
INSERT INTO users (username,password_hash,role,status,created_at) VALUES ('a','h','user',1,'t');
INSERT INTO users (username,password_hash,role,status,created_at) VALUES ('b','h','user',0,'t');
''')
for stmt in [
    "ALTER TABLE users ADD COLUMN email TEXT DEFAULT ''",
    "ALTER TABLE users ADD COLUMN account_state TEXT NOT NULL DEFAULT 'active'",
    "ALTER TABLE users ADD COLUMN warning TEXT DEFAULT ''",
    "ALTER TABLE users ADD COLUMN warned_at TEXT DEFAULT ''",
    "UPDATE users SET account_state = CASE WHEN status = 0 THEN 'banned' ELSE 'active' END WHERE account_state IS NULL OR account_state = '' OR (status = 0 AND account_state = 'active')",
    "CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)",
]:
    old.execute(stmt)
states = dict(old.execute('SELECT username, account_state FROM users'))
print('migrated states:', states)
if states.get('a') != 'active' or states.get('b') != 'banned':
    issues.append('旧库 account_state 迁移结果异常')

# PHP 括号检查
def strip_php(src):
    out = []
    i = 0
    n = len(src)
    state = 'code'
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ''
        if state == 'code':
            if c in ("'", '"'):
                state = c; i += 1; continue
            if c == '/' and nxt == '/':
                state = 'line'; i += 2; continue
            if c == '/' and nxt == '*':
                state = 'block'; i += 2; continue
            if c == '#':
                state = 'line'; i += 1; continue
            out.append(c); i += 1; continue
        if state in ("'", '"'):
            q = state
            if c == '\\':
                i += 2; continue
            if c == q:
                state = 'code'; i += 1; continue
            i += 1; continue
        if state == 'line':
            if c == '\n':
                state = 'code'; out.append('\n')
            i += 1; continue
        if state == 'block':
            if c == '*' and nxt == '/':
                state = 'code'; i += 2; continue
            i += 1; continue
    return ''.join(out)


def balance(text, name):
    stack = []
    pairs = {')': '(', ']': '[', '}': '{'}
    for ch in text:
        if ch in '([{':
            stack.append(ch)
        elif ch in ')]}':
            if not stack or stack[-1] != pairs[ch]:
                return name + ': 括号不匹配'
            stack.pop()
    return None if not stack else name + ': 未闭合括号'


for fn in ['api/db.php', 'api/auth.php', 'api/api_auth.php', 'api/api_admin.php', 'api/toml.php']:
    p = balance(strip_php(open(os.path.join(root, fn), encoding='utf-8').read()), fn)
    print(('OK  ' if not p else 'FAIL') + ' ' + fn + ('' if not p else ' -> ' + p))
    if p:
        issues.append(p)

# 关键字段/路由/元素
admin_php = open(os.path.join(root, 'api', 'api_admin.php'), encoding='utf-8').read()
auth_php = open(os.path.join(root, 'api', 'auth.php'), encoding='utf-8').read()
auth_api = open(os.path.join(root, 'api', 'api_auth.php'), encoding='utf-8').read()
admin_html = open(os.path.join(root, 'admin.html'), encoding='utf-8').read()
user_html = open(os.path.join(root, 'user.html'), encoding='utf-8').read()
checks = {
    'admin 新建用户': 'function adm_user_create' in admin_php and "'admin_user_create'" in admin_php,
    'admin 状态操作': 'function adm_user_action' in admin_php,
    'admin 警告': 'function adm_user_warn' in admin_php,
    'admin 重置密码': 'function adm_user_reset_password' in admin_php,
    'admin 列表含邮箱/状态': 'u.email, u.role, u.status, u.account_state' in admin_php,
    'auth 账号状态校验': 'assert_account_active' in auth_php,
    'config_public 返回图标': "'site_icon'" in auth_api,
    '后台配置含图标/邮箱': 'data-cfg="site_icon"' in admin_html and 'data-cfg="admin_email"' in admin_html,
    '后台用户新增表单': 'id="userCreate"' in admin_html,
    '后台用户操作按钮': "data-act=\"warn\"" in admin_html and "data-act=\"reset\"" in admin_html,
    '用户警告条': 'id="warningBanner"' in user_html and 'renderWarning' in user_html,
    '页面图标配置': 'id="siteFavicon"' in admin_html and 'id="siteFavicon"' in user_html,
    'favicon.svg 存在': os.path.isfile(os.path.join(root, 'favicon.svg')),
}
for name, ok in checks.items():
    print(('OK  ' if ok else 'FAIL') + ' ' + name)
    if not ok:
        issues.append('检查失败：' + name)

print('\n'.join(issues) if issues else '静态检查: OK')