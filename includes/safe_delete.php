<?php
/**
 * Deleting something that other records point to.
 *
 * The database checks foreign keys, so "DELETE FROM users WHERE id = ?" fails for any member who has
 * ever commented, read, saved or joined a group (and the page stopped with an error). This deletes the
 * row and then tidies everything that pointed to it, following the database's own foreign keys:
 *   - a pointer that may be empty is emptied (posts stay, shown as from a deleted member);
 *   - a pointer that must be filled means the row belongs to the deleted one, so it is deleted too;
 *   - and so on, until nothing points to a missing row.
 * All of it happens in one transaction: either everything is done, or nothing changes.
 * Returns ['ok' => true, 'tidied' => n] or ['ok' => false, 'error' => message]. Nothing throws.
 */

function aw_safe_delete(PDO $db, $table, $id) {
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', (string)$table)) return ['ok' => false, 'error' => 'Unknown table.'];
    $id = (int)$id;
    $fk_was = 1;
    try { $fk_was = (int)$db->query('PRAGMA foreign_keys')->fetchColumn(); } catch (Throwable $e) {}
    $tidied = 0;
    try {
        if ($db->inTransaction()) return ['ok' => false, 'error' => 'Please try again.'];
        $db->exec('PRAGMA foreign_keys = OFF'); // must be set outside a transaction
        $db->beginTransaction();
        // broken links that were already there are left exactly as they were
        $before = [];
        foreach ($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) as $b) $before[$b[0] . '#' . $b[1] . '#' . $b[3]] = true;
        $st = $db->prepare("DELETE FROM \"$table\" WHERE id = ?");
        $st->execute([$id]);
        if ($st->rowCount() === 0) { $db->rollBack(); $db->exec('PRAGMA foreign_keys = ' . $fk_was); return ['ok' => false, 'error' => 'It was already deleted.']; }

        $cols = []; // [table][fk id] => [column, nullable]
        for ($round = 0; $round < 25; $round++) {
            $bad = array_filter($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM), function ($b) use ($before) { return !isset($before[$b[0] . '#' . $b[1] . '#' . $b[3]]); }); // table, rowid, parent, fkid
            if (!$bad) break;
            foreach ($bad as $b) {
                [$t, $rowid, , $fkid] = $b;
                if ($rowid === null) continue; // WITHOUT ROWID tables are not used here
                if (!isset($cols[$t])) {
                    $cols[$t] = [];
                    $notnull = [];
                    foreach ($db->query("PRAGMA table_info(\"$t\")") as $c) $notnull[$c['name']] = (int)$c['notnull'] === 1;
                    foreach ($db->query("PRAGMA foreign_key_list(\"$t\")") as $f) $cols[$t][(int)$f['id']] = [$f['from'], empty($notnull[$f['from']])];
                }
                [$col, $nullable] = $cols[$t][(int)$fkid] ?? [null, false];
                if ($col !== null && $nullable) $db->prepare("UPDATE \"$t\" SET \"$col\" = NULL WHERE rowid = ?")->execute([$rowid]);
                else $db->prepare("DELETE FROM \"$t\" WHERE rowid = ?")->execute([$rowid]);
                $tidied++;
            }
        }
        foreach ($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) as $b) {
            if (!isset($before[$b[0] . '#' . $b[1] . '#' . $b[3]])) throw new RuntimeException('records still point to the deleted row');
        }
        $db->commit();
        $db->exec('PRAGMA foreign_keys = ' . $fk_was);
        return ['ok' => true, 'tidied' => $tidied];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        try { $db->exec('PRAGMA foreign_keys = ' . $fk_was); } catch (Throwable $e2) {}
        error_log('safe delete ' . $table . ' ' . $id . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'It could not be deleted just now. Nothing was changed; please try again.'];
    }
}
