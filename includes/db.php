<?php
/**
 * Database Connection & Setup
 * SQLite – single file database
 */

// Prevent direct access
if (!defined('SITE_NAME') && !defined('SITE_URL')) {
    // If config not loaded, try to require it
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        // Fallback constants
        define('SITE_NAME', 'AngellaWrites');
        define('SITE_URL', 'https://yourdomain.com');
    }
}

// Database file path
// F219: one source for the path. config.php defines DB_PATH (it was unused); this file
// keeps its old value only as a fallback when config.php has not been loaded.
$dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../data/site.db';
$dbDir = dirname($dbPath);

// Create data directory if it doesn't exist
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    // Connect to SQLite database (creates file if it doesn't exist)
    $db = new PDO('sqlite:' . $dbPath);
    
    // Set error mode to exceptions
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');

    // F205: wait up to 5 s for a concurrent writer instead of failing at once with
    // "database is locked" (page views write view/read logs on almost every request).
    $db->exec('PRAGMA busy_timeout = 5000');

    // F205: the CREATE TABLE / CREATE INDEX statements below used to run on every request.
    // They now run once per database file; PRAGMA user_version records that they have run.
    // (The block is deliberately not re-indented, to keep this change small.)
    $aw_schema_version = (int)$db->query('PRAGMA user_version')->fetchColumn();

    // F207: a brand-new database gets the complete schema (97 tables, generated from the repaired
    // production database by tools/generate_schema.php). The 23 definitions below could not run
    // the site on their own and conflicted with what the code inserts. Existing databases (they
    // have a users table) are never touched by this step.
    $aw_has_users = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (!$aw_has_users) {
        $db->beginTransaction();
        foreach (require __DIR__ . '/schema.php' as $sql) {
            $db->exec($sql);
        }
        $db->exec('PRAGMA user_version = 1');
        $db->commit();
        $aw_schema_version = 1;
    }

    if ($aw_schema_version < 1) {
    
    // Create tables if they don't exist
    $tables = [
        // ===== USERS TABLE =====
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'reader',
            avatar TEXT,
            bio TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // ===== BOOKS TABLE =====
        "CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            author TEXT DEFAULT 'Angella Bottoman',
            description TEXT,
            cover_path TEXT,
            file_path TEXT,
            file_type TEXT,
            price REAL DEFAULT 0,
            is_free INTEGER DEFAULT 0,
            is_sale INTEGER DEFAULT 0,
            download_count INTEGER DEFAULT 0,
            view_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // ===== READING STATUS TABLE =====
        "CREATE TABLE IF NOT EXISTS reading_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            progress INTEGER DEFAULT 0,
            last_read_page INTEGER DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE(user_id, book_id)
        )",
        
        // ===== POEMS TABLE =====
        "CREATE TABLE IF NOT EXISTS poems (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            intro TEXT,
            content TEXT NOT NULL,
            audio_path TEXT,
            view_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        // ===== SESSIONS TABLE (1-on-1 bookings) =====
        "CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            date DATE NOT NULL,
            time TEXT NOT NULL,
            duration INTEGER DEFAULT 60,
            status TEXT DEFAULT 'pending',
            message TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )",
        
        // ===== QUESTIONS TABLE (Community Q&A) =====
        "CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            answers_count INTEGER DEFAULT 0,
            is_answered INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )",
        
        // ===== ANSWERS TABLE =====
        "CREATE TABLE IF NOT EXISTS answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            upvotes INTEGER DEFAULT 0,
            is_accepted INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_type TEXT NOT NULL, -- 'poem' or 'book'
            target_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            rating INTEGER DEFAULT 0,
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS poem_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poem_id INTEGER UNIQUE NOT NULL,
            status TEXT DEFAULT 'published',
            reviewed_at DATETIME,
            reviewed_by INTEGER,
            FOREIGN KEY (poem_id) REFERENCES poems (id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        )",
        
        
        // ===== NEWSLETTER TABLE =====
        "CREATE TABLE IF NOT EXISTS newsletter (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            name TEXT,
            is_active INTEGER DEFAULT 1,
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at DATETIME
        )",
        
        // ===== BLOG POSTS TABLE =====
        "CREATE TABLE IF NOT EXISTS blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            content TEXT NOT NULL,
            excerpt TEXT,
            featured_image TEXT,
            category TEXT DEFAULT 'Christian Reflections',
            tags TEXT,
            status TEXT DEFAULT 'draft',
            views INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            published_at DATETIME
        )",
        
        // ===== CONTACT MESSAGES TABLE =====
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            subject TEXT,
            message TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    
    // Execute each CREATE statement
    foreach ($tables as $sql) {
        $db->exec($sql);
    }
    
    // S6 (F108): no seed accounts. A default admin and test reader with fixed passwords
    // used to be created here whenever users was empty (known credentials on a fresh DB).

    // ===== INDEXES FOR PERFORMANCE =====
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)",
        "CREATE INDEX IF NOT EXISTS idx_books_title ON books(title)",
        "CREATE INDEX IF NOT EXISTS idx_reading_status_user ON reading_status(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_reading_status_book ON reading_status(book_id)",
        "CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date)",
        "CREATE INDEX IF NOT EXISTS idx_questions_user ON questions(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_answers_question ON answers(question_id)",
        "CREATE INDEX IF NOT EXISTS idx_blog_posts_slug ON blog_posts(slug)",
        "CREATE INDEX IF NOT EXISTS idx_blog_posts_status ON blog_posts(status)",
        "CREATE INDEX IF NOT EXISTS idx_contact_messages_email ON contact_messages(email)"
    ];
    
    foreach ($indexes as $sql) {
        $db->exec($sql);
    }

    $db->exec('PRAGMA user_version = 1');
    } // end F205 run-once block

    // F28: Bible highlights are stored per member and verse (the page posted add_highlight to a
    // handler that did not exist). Schema version 2 adds the table once on existing databases.
    if ($aw_schema_version < 2) {
        $db->exec("CREATE TABLE IF NOT EXISTS bible_highlights (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book TEXT NOT NULL,
            chapter INTEGER NOT NULL,
            verse INTEGER NOT NULL,
            color TEXT NOT NULL DEFAULT 'yellow',
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, book, chapter, verse)
        )");
        $db->exec('PRAGMA user_version = 2');
    }

    // F233: prayer_requests.reflection_id was NOT NULL (with a foreign key to blog_posts), so a
    // prayer request sent from the book reader, which has no blog post, could never be saved.
    // Schema version 3 rebuilds the table once with reflection_id nullable; rows are kept as they are.
    if ($aw_schema_version < 3) {
        try {
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->exec('BEGIN IMMEDIATE');
            if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 3) {
                $db->exec('CREATE TABLE prayer_requests_v3 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    reflection_id INTEGER,
                    request_type TEXT NOT NULL,
                    message TEXT,
                    status TEXT DEFAULT \'pending\',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, "book_id" INTEGER, "request_text" TEXT,
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    FOREIGN KEY (reflection_id) REFERENCES blog_posts (id) ON DELETE CASCADE
                )');
                $db->exec('INSERT INTO prayer_requests_v3 (id, user_id, reflection_id, request_type, message, status, created_at, book_id, request_text)
                           SELECT id, user_id, reflection_id, request_type, message, status, created_at, book_id, request_text FROM prayer_requests');
                $db->exec('DROP TABLE prayer_requests');
                $db->exec('ALTER TABLE prayer_requests_v3 RENAME TO prayer_requests');
                $db->exec('PRAGMA user_version = 3');
            }
            $db->exec('COMMIT');
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            error_log('F233 prayer_requests migration: ' . $e->getMessage());
        }
        $db->exec('PRAGMA foreign_keys = ON');
    }

    // F76: the phone number a member pays from was only put in the admin email, never stored, so
    // the admin had nothing to match against a mobile-money statement. Schema version 4 adds
    // payments.phone_number (skipped if the column is already there, e.g. on a new install).
    if ($aw_schema_version < 4) {
        $aw_cols = $db->query("PRAGMA table_info(payments)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($aw_cols && !in_array('phone_number', $aw_cols, true)) {
            $db->exec('ALTER TABLE payments ADD COLUMN phone_number TEXT');
        }
        if ($aw_cols) { // without a payments table yet, check again on the next request
            $db->exec('PRAGMA user_version = 4');
        }
    }

} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log('Database Error: ' . $e->getMessage());
    die('Database connection error. Please check the logs.');
}

// Return the database connection
return $db;
?>