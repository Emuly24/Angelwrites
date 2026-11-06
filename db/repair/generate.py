"""Generate repair.sql from the production schema (read-only).
Usage: python3 generate.py <site.db> <out.sql>
"""
import re, sqlite3, sys, datetime

src, out = sys.argv[1], sys.argv[2]
d = sqlite3.connect(f'file:{src}?mode=ro', uri=True)

def ddl(t):
    return d.execute("select sql from sqlite_master where type='table' and name=?", (t,)).fetchone()[0]

def cols(t):
    return [c[1] for c in d.execute(f'pragma table_info("{t}")')]

# 1. tables whose foreign keys point at the empty users_old table
fk_old = sorted({t for (t,) in d.execute("select name from sqlite_master where type='table'")
                 for fk in d.execute(f'pragma foreign_key_list("{t}")') if fk[2] == 'users_old'})
# 2. tables rebuilt to relax NOT NULL constraints the code can't satisfy
relax = {
    'book_comments': [(r'paragraph_index INTEGER NOT NULL', 'paragraph_index INTEGER DEFAULT 0'),
                      (r'comment_text TEXT NOT NULL', 'comment_text TEXT'),
                      (r'comment TEXT NOT NULL', 'comment TEXT')],
    'reading_groups': [(r'book_id INTEGER NOT NULL', 'book_id INTEGER')],
}
rebuild = fk_old + [t for t in relax if t not in fk_old]

# columns the code reads/writes that production lacks (aliases excluded after review)
add_cols = [
    ('sessions', 'session_type', "TEXT DEFAULT 'spiritual_guidance'"),
    ('sessions', 'timezone', 'TEXT'),
    ('reflections', 'tags', "TEXT DEFAULT ''"),
    ('reflections', 'slug', 'TEXT'),
    ('reflections', 'featured', 'INTEGER DEFAULT 0'),
    ('reflections', 'comment_count', 'INTEGER DEFAULT 0'),
    ('reflections', 'likes', 'INTEGER DEFAULT 0'),
    ('reflections', 'loves', 'INTEGER DEFAULT 0'),
    ('reflections', 'prays', 'INTEGER DEFAULT 0'),
    ('blog_posts', 'comment_count', 'INTEGER DEFAULT 0'),
    ('blog_posts', 'likes', 'INTEGER DEFAULT 0'),
    ('blog_posts', 'loves', 'INTEGER DEFAULT 0'),
    ('blog_posts', 'prays', 'INTEGER DEFAULT 0'),
    ('book_comments', 'page_num', 'INTEGER'),
    ('book_comments', 'parent_id', 'INTEGER'),
    ('book_comments', 'is_admin_reply', 'INTEGER DEFAULT 0'),
    ('reflection_comments', 'photo_path', 'TEXT'),
    ('reflection_comments', 'voice_path', 'TEXT'),
    ('reflection_comments', 'is_admin_reply', 'INTEGER DEFAULT 0'),
    ('prayer_requests', 'book_id', 'INTEGER'),
    ('prayer_requests', 'request_text', 'TEXT'),
    ('videos', 'views', 'INTEGER DEFAULT 0'),
    ('videos', 'likes', 'INTEGER DEFAULT 0'),
    ('videos', 'dislikes', 'INTEGER DEFAULT 0'),
    ('newsletter', 'updated_at', 'DATETIME'),
    ('newsletter', 'source', "TEXT DEFAULT 'website'"),
    ('bookmarks', 'offset', 'INTEGER DEFAULT 0'),
    ('email_queue', 'segment_id', 'INTEGER'),
    ('reading_challenges', 'completed', 'INTEGER DEFAULT 0'),
    ('reading_goals', 'completed_at', 'DATETIME'),
    ('users', 'is_admin', 'INTEGER DEFAULT 0'),
]
new_tables = {
    'comment_reactions': '''CREATE TABLE comment_reactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    comment_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reaction_type TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (comment_id, user_id, reaction_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)''',
    'review_reactions': '''CREATE TABLE review_reactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    review_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reaction_type TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (review_id, user_id, reaction_type),
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)''',
    'video_comments': '''CREATE TABLE video_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)''',
    'purchases': '''CREATE TABLE purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    amount REAL,
    payment_status TEXT DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
)''',
}

L = []
w = L.append
w(f"-- AngelWrites database repair. Generated {datetime.date.today()} from the uploaded production schema.")
w("-- Read REPAIR_README.md before running. BACK UP site.db, site.db-wal and site.db-shm first.")
w("-- Must run with foreign keys OFF: rebuilding parent tables with them ON would CASCADE-DELETE child rows.")
w("-- No BEGIN/COMMIT on purpose: DB Browser for SQLite supplies the transaction (\"Write Changes\" / close without saving).")
w("-- From the sqlite3 command line, run it as:  sqlite3 site.db \".bail on\" \"BEGIN\" \".read repair.sql\" \"COMMIT\"")
w("PRAGMA foreign_keys = OFF;")
w("PRAGMA legacy_alter_table = ON;")
w("-- Safety guard: aborts here (CHECK constraint failed) if foreign keys are still ON.")
w("CREATE TEMP TABLE _fk_guard (fk INTEGER CHECK (fk = 0));")
w("INSERT INTO _fk_guard SELECT foreign_keys FROM pragma_foreign_keys;")
w("DROP TABLE _fk_guard;")
w("")
w(f"-- Part 1: rebuild {len(rebuild)} tables (repoint users_old -> users; relax NOT NULL traps)")
for t in rebuild:
    sql = ddl(t)
    new = re.sub(r'(?i)REFERENCES\s+"?users_old"?', 'REFERENCES users', sql)
    for a, b in relax.get(t, []):
        assert re.search(a, new), (t, a)
        new = re.sub(a, b, new, count=1)
    new = re.sub(r'(?i)^CREATE TABLE\s+"?' + re.escape(t) + r'"?', f'CREATE TABLE "{t}__new"', new, count=1)
    assert f'"{t}__new"' in new and 'users_old' not in new, t
    c = ', '.join(f'"{x}"' for x in cols(t))
    seq = d.execute("select seq from sqlite_sequence where name=?", (t,)).fetchone()
    idx = [r[0] for r in d.execute("select sql from sqlite_master where type='index' and tbl_name=? and sql is not null", (t,))]
    w(f"-- {t}")
    w(new + ";")
    w(f'INSERT INTO "{t}__new" ({c}) SELECT {c} FROM "{t}";')
    w(f'DROP TABLE "{t}";')
    w(f'ALTER TABLE "{t}__new" RENAME TO "{t}";')
    for s in idx:
        w(s + ";")
    if seq:
        w(f"UPDATE sqlite_sequence SET seq = {seq[0]} WHERE name = '{t}';")
    w("")
w("-- Part 2: add missing columns")
for t, cname, typ in add_cols:
    if cname in cols(t):
        continue
    w(f'ALTER TABLE "{t}" ADD COLUMN "{cname}" {typ};')
w("UPDATE users SET is_admin = CASE WHEN role = 'admin' THEN 1 ELSE 0 END;")
w("")
w("-- Part 3: create missing tables")
for t, s in new_tables.items():
    if not d.execute("select 1 from sqlite_master where name=?", (t,)).fetchone():
        w(s + ";")
w("")
w("-- Part 4: remove the empty table the broken foreign keys pointed at")
w("DROP TABLE IF EXISTS users_old;")
w("")
w("-- Part 5: checks. foreign_key_check must return no rows; integrity_check must return 'ok'.")
w("PRAGMA legacy_alter_table = OFF;")
w("PRAGMA foreign_key_check;")
w("PRAGMA integrity_check;")
open(out, 'w').write('\n'.join(L) + '\n')
print(f"rebuild={len(rebuild)} tables, add_cols={sum(1 for t,c,_ in add_cols if c not in cols(t))}, new_tables={sum(1 for t in new_tables if not d.execute('select 1 from sqlite_master where name=?',(t,)).fetchone())}, lines={len(L)}")
