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
$dbPath = __DIR__ . '/../data/site.db';
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
    
    // ===== CHECK AND CREATE DEFAULT ADMIN (optional) =====
    // Check if there are any users
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    
    if ($userCount == 0) {
        // No users exist – create a default admin account
        // You can change this email/password or comment out this section
        $defaultAdmin = [
            'name' => 'Angella Bottoman',
            'email' => 'admin@angelawrites.com',
            'password' => password_hash('ChangeMe123!', PASSWORD_DEFAULT),
            'role' => 'admin'
        ];
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$defaultAdmin['name'], $defaultAdmin['email'], $defaultAdmin['password'], $defaultAdmin['role']]);
        
        // Also create a sample reader account for testing
        $sampleReader = [
            'name' => 'Test Reader',
            'email' => 'reader@example.com',
            'password' => password_hash('Reader123!', PASSWORD_DEFAULT),
            'role' => 'reader'
        ];
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sampleReader['name'], $sampleReader['email'], $sampleReader['password'], $sampleReader['role']]);
    }
    
    
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
    
} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log('Database Error: ' . $e->getMessage());
    die('Database connection error. Please check the logs.');
}

// Return the database connection
return $db;
?>