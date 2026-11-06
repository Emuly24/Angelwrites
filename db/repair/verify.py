"""Prove repair.sql on copies of the production DB.  python3 -I verify.py <prod.db> <repair.sql> <workdir>"""
import sqlite3, shutil, sys, hashlib
prod, script, wd = sys.argv[1:4]
sql = open(script).read()

def snapshot(path):
    d = sqlite3.connect(path)
    tabs = [r[0] for r in d.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")]
    rows = {}
    for t in tabs:
        c = [x[1] for x in d.execute(f'pragma table_info("{t}")')]
        data = d.execute(f'select {",".join(chr(34)+x+chr(34) for x in c)} from "{t}" order by rowid').fetchall()
        rows[t] = (len(data), hashlib.sha256(repr(data).encode()).hexdigest(), c)
    seq = dict(d.execute("select name, seq from sqlite_sequence"))
    idx = {r[0] for r in d.execute("select name from sqlite_master where type='index' and sql is not null")}
    d.close()
    return rows, seq, idx

before, seq_b, idx_b = snapshot(prod)
print(f"before: {len(before)} tables, {sum(v[0] for v in before.values())} rows, {len(idx_b)} named indexes")

# --- Test 1: guard must abort when foreign keys are ON
p1 = f"{wd}/t1.db"; shutil.copy(prod, p1)
d = sqlite3.connect(p1, isolation_level=None)
guarded = sql.replace("PRAGMA foreign_keys = OFF;", "PRAGMA foreign_keys = ON;", 1)
try:
    d.executescript(guarded); print("TEST 1 FAIL: script ran with foreign keys ON")
except sqlite3.IntegrityError as e:
    print("TEST 1 ok: guard aborted ->", e)
d.close()
after1, _, _ = snapshot(p1)
print("TEST 1 db unchanged:", {k: v[:2] for k, v in after1.items()} == {k: v[:2] for k, v in before.items()})

# --- Test 2: real run
p2 = f"{wd}/repaired.db"; shutil.copy(prod, p2)
d = sqlite3.connect(p2, isolation_level=None)
d.executescript(sql)
fkc = d.execute("pragma foreign_key_check").fetchall()
integ = d.execute("pragma integrity_check").fetchone()[0]
still_old = [t for (t,) in d.execute("select name from sqlite_master where type='table'") for fk in d.execute(f'pragma foreign_key_list("{t}")') if fk[2] == 'users_old']
d.close()
after, seq_a, idx_a = snapshot(p2)
lost = []
for t, (n, h, c) in before.items():
    if t == 'users_old':
        continue
    if t not in after:
        lost.append((t, 'table missing')); continue
    n2 = after[t][0]
    # compare original columns only (new columns were added)
    d = sqlite3.connect(p2)
    data = d.execute(f'select {",".join(chr(34)+x+chr(34) for x in c)} from "{t}" order by rowid').fetchall(); d.close()
    if n2 != n or hashlib.sha256(repr(data).encode()).hexdigest() != h:
        lost.append((t, n, n2))
print("TEST 2 integrity_check:", integ)
print("TEST 2 foreign_key_check rows:", fkc)
print("TEST 2 FKs still pointing at users_old:", still_old)
print("TEST 2 tables whose original data changed:", lost or "none")
print("TEST 2 sequences preserved:", all(seq_a.get(k) == v for k, v in seq_b.items() if k in seq_a))
print("TEST 2 named indexes preserved:", idx_b <= idx_a, "| missing:", idx_b - idx_a)
print("TEST 2 users_old dropped:", 'users_old' not in after)
print(f"after: {len(after)} tables, {sum(v[0] for v in after.values())} rows")

# --- Test 3: running it a second time must fail safely (not corrupt)
p3 = f"{wd}/t3.db"; shutil.copy(p2, p3)
d = sqlite3.connect(p3, isolation_level=None)
try:
    d.executescript(sql); print("TEST 3: second run completed")
except sqlite3.Error as e:
    d.execute("rollback") if d.in_transaction else None
    print("TEST 3 second run stopped:", e)
d.close()
a3, _, _ = snapshot(p3)
print("TEST 3 db identical to first repaired result:", {k: v[:2] for k, v in a3.items()} == {k: v[:2] for k, v in after.items()})
