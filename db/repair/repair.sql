-- AngelWrites database repair. Generated 2026-11-05 from the uploaded production schema.
-- Read REPAIR_README.md before running. BACK UP site.db, site.db-wal and site.db-shm first.
-- Must run with foreign keys OFF: rebuilding parent tables with them ON would CASCADE-DELETE child rows.
-- No BEGIN/COMMIT on purpose: DB Browser for SQLite supplies the transaction ("Write Changes" / close without saving).
-- From the sqlite3 command line, run it as:  sqlite3 site.db ".bail on" "BEGIN" ".read repair.sql" "COMMIT"
PRAGMA foreign_keys = OFF;
PRAGMA legacy_alter_table = ON;
-- Safety guard: aborts here (CHECK constraint failed) if foreign keys are still ON.
CREATE TEMP TABLE _fk_guard (fk INTEGER CHECK (fk = 0));
INSERT INTO _fk_guard SELECT foreign_keys FROM pragma_foreign_keys;
DROP TABLE _fk_guard;

-- Part 1: rebuild 21 tables (repoint users_old -> users; relax NOT NULL traps)
-- activity_logs
CREATE TABLE "activity_logs__new" (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
INSERT INTO "activity_logs__new" ("id", "user_id", "action", "details", "ip_address", "created_at") SELECT "id", "user_id", "action", "details", "ip_address", "created_at" FROM "activity_logs";
DROP TABLE "activity_logs";
ALTER TABLE "activity_logs__new" RENAME TO "activity_logs";

-- answers
CREATE TABLE "answers__new" (
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
        );
INSERT INTO "answers__new" ("id", "question_id", "user_id", "body", "upvotes", "is_accepted", "created_at", "updated_at") SELECT "id", "question_id", "user_id", "body", "upvotes", "is_accepted", "created_at", "updated_at" FROM "answers";
DROP TABLE "answers";
ALTER TABLE "answers__new" RENAME TO "answers";
CREATE INDEX idx_answers_question ON answers(question_id);

-- blog_reads
CREATE TABLE "blog_reads__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        blog_post_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (blog_post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
        UNIQUE(user_id, blog_post_id)
    );
INSERT INTO "blog_reads__new" ("id", "user_id", "blog_post_id", "read_at") SELECT "id", "user_id", "blog_post_id", "read_at" FROM "blog_reads";
DROP TABLE "blog_reads";
ALTER TABLE "blog_reads__new" RENAME TO "blog_reads";
CREATE INDEX idx_blog_reads_user ON blog_reads(user_id);

-- book_highlights
CREATE TABLE "book_highlights__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    highlight_text TEXT NOT NULL,
    start_offset INTEGER DEFAULT 0,
    end_offset INTEGER DEFAULT 0,
    color TEXT DEFAULT '#ffeb3b',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
);
INSERT INTO "book_highlights__new" ("id", "user_id", "book_id", "highlight_text", "start_offset", "end_offset", "color", "created_at") SELECT "id", "user_id", "book_id", "highlight_text", "start_offset", "end_offset", "color", "created_at" FROM "book_highlights";
DROP TABLE "book_highlights";
ALTER TABLE "book_highlights__new" RENAME TO "book_highlights";
CREATE INDEX idx_book_highlights_user ON book_highlights(user_id);
CREATE INDEX idx_book_highlights_book ON book_highlights(book_id);

-- connections
CREATE TABLE "connections__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
        UNIQUE(sender_id, receiver_id)
    );
INSERT INTO "connections__new" ("id", "sender_id", "receiver_id", "status", "created_at", "updated_at") SELECT "id", "sender_id", "receiver_id", "status", "created_at", "updated_at" FROM "connections";
DROP TABLE "connections";
ALTER TABLE "connections__new" RENAME TO "connections";

-- invites
CREATE TABLE "invites__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_email TEXT NOT NULL,
        message TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
    );
INSERT INTO "invites__new" ("id", "sender_id", "receiver_email", "message", "status", "created_at") SELECT "id", "sender_id", "receiver_email", "message", "status", "created_at" FROM "invites";
DROP TABLE "invites";
ALTER TABLE "invites__new" RENAME TO "invites";

-- notifications
CREATE TABLE "notifications__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, link TEXT DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    );
INSERT INTO "notifications__new" ("id", "user_id", "type", "title", "message", "is_read", "created_at", "link") SELECT "id", "user_id", "type", "title", "message", "is_read", "created_at", "link" FROM "notifications";
DROP TABLE "notifications";
ALTER TABLE "notifications__new" RENAME TO "notifications";
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_created_at ON notifications(created_at DESC);
CREATE INDEX idx_notifications_user ON notifications(user_id);

-- poem_reads
CREATE TABLE "poem_reads__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        poem_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (poem_id) REFERENCES poems(id) ON DELETE CASCADE,
        UNIQUE(user_id, poem_id)
    );
INSERT INTO "poem_reads__new" ("id", "user_id", "poem_id", "read_at") SELECT "id", "user_id", "poem_id", "read_at" FROM "poem_reads";
DROP TABLE "poem_reads";
ALTER TABLE "poem_reads__new" RENAME TO "poem_reads";
CREATE INDEX idx_poem_reads_user ON poem_reads(user_id);
UPDATE sqlite_sequence SET seq = 2 WHERE name = 'poem_reads';

-- poem_status
CREATE TABLE "poem_status__new" (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poem_id INTEGER UNIQUE NOT NULL,
            status TEXT DEFAULT 'published',
            reviewed_at DATETIME,
            reviewed_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (poem_id) REFERENCES poems (id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        );
INSERT INTO "poem_status__new" ("id", "poem_id", "status", "reviewed_at", "reviewed_by", "created_at") SELECT "id", "poem_id", "status", "reviewed_at", "reviewed_by", "created_at" FROM "poem_status";
DROP TABLE "poem_status";
ALTER TABLE "poem_status__new" RENAME TO "poem_status";
UPDATE sqlite_sequence SET seq = 3 WHERE name = 'poem_status';

-- prayer_requests
CREATE TABLE "prayer_requests__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    reflection_id INTEGER NOT NULL,
    request_type TEXT NOT NULL,
    message TEXT,
    status TEXT DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (reflection_id) REFERENCES blog_posts (id) ON DELETE CASCADE
);
INSERT INTO "prayer_requests__new" ("id", "user_id", "reflection_id", "request_type", "message", "status", "created_at") SELECT "id", "user_id", "reflection_id", "request_type", "message", "status", "created_at" FROM "prayer_requests";
DROP TABLE "prayer_requests";
ALTER TABLE "prayer_requests__new" RENAME TO "prayer_requests";

-- questions
CREATE TABLE "questions__new" (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            answers_count INTEGER DEFAULT 0,
            is_answered INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, status TEXT DEFAULT "pending",
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
INSERT INTO "questions__new" ("id", "user_id", "title", "body", "views", "answers_count", "is_answered", "created_at", "updated_at", "status") SELECT "id", "user_id", "title", "body", "views", "answers_count", "is_answered", "created_at", "updated_at", "status" FROM "questions";
DROP TABLE "questions";
ALTER TABLE "questions__new" RENAME TO "questions";
CREATE INDEX idx_questions_user ON questions(user_id);

-- reading_progress
CREATE TABLE "reading_progress__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    position_offset INTEGER DEFAULT 0,
    position_section TEXT,
    progress_percent INTEGER DEFAULT 0,
    last_accessed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME, completion_email_sent INTEGER DEFAULT 0, chapter_index INTEGER DEFAULT 0, note TEXT, created_at DATETIME, last_read_at DATETIME,
    UNIQUE(user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
);
INSERT INTO "reading_progress__new" ("id", "user_id", "book_id", "position_offset", "position_section", "progress_percent", "last_accessed_at", "finished_at", "completion_email_sent", "chapter_index", "note", "created_at", "last_read_at") SELECT "id", "user_id", "book_id", "position_offset", "position_section", "progress_percent", "last_accessed_at", "finished_at", "completion_email_sent", "chapter_index", "note", "created_at", "last_read_at" FROM "reading_progress";
DROP TABLE "reading_progress";
ALTER TABLE "reading_progress__new" RENAME TO "reading_progress";
CREATE INDEX idx_reading_progress_user ON reading_progress(user_id);
CREATE INDEX idx_reading_progress_book ON reading_progress(book_id);
CREATE INDEX idx_reading_progress_user_book ON reading_progress(user_id, book_id);
CREATE INDEX idx_reading_progress_user_id ON reading_progress(user_id);
CREATE INDEX idx_reading_progress_book_id ON reading_progress(book_id);
UPDATE sqlite_sequence SET seq = 30 WHERE name = 'reading_progress';

-- reading_status
CREATE TABLE "reading_status__new" (
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
        );
INSERT INTO "reading_status__new" ("id", "user_id", "book_id", "status", "progress", "last_read_page", "started_at", "updated_at", "finished_at") SELECT "id", "user_id", "book_id", "status", "progress", "last_read_page", "started_at", "updated_at", "finished_at" FROM "reading_status";
DROP TABLE "reading_status";
ALTER TABLE "reading_status__new" RENAME TO "reading_status";
CREATE INDEX idx_reading_status_user ON reading_status(user_id);
CREATE INDEX idx_reading_status_book ON reading_status(book_id);
CREATE INDEX idx_reading_status_user_book ON reading_status(user_id, book_id);
CREATE INDEX idx_reading_status_user_id ON reading_status(user_id);
CREATE INDEX idx_reading_status_book_id ON reading_status(book_id);
CREATE INDEX idx_reading_status_status ON reading_status(status);
UPDATE sqlite_sequence SET seq = 37 WHERE name = 'reading_status';

-- reflection_comments
CREATE TABLE "reflection_comments__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reflection_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reflection_id) REFERENCES blog_posts (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
INSERT INTO "reflection_comments__new" ("id", "reflection_id", "user_id", "comment", "created_at", "updated_at") SELECT "id", "reflection_id", "user_id", "comment", "created_at", "updated_at" FROM "reflection_comments";
DROP TABLE "reflection_comments";
ALTER TABLE "reflection_comments__new" RENAME TO "reflection_comments";

-- reflection_reads
CREATE TABLE "reflection_reads__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        reflection_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reflection_id) REFERENCES reflections(id) ON DELETE CASCADE,
        UNIQUE(user_id, reflection_id)
    );
INSERT INTO "reflection_reads__new" ("id", "user_id", "reflection_id", "read_at") SELECT "id", "user_id", "reflection_id", "read_at" FROM "reflection_reads";
DROP TABLE "reflection_reads";
ALTER TABLE "reflection_reads__new" RENAME TO "reflection_reads";
CREATE INDEX idx_reflection_reads_user ON reflection_reads(user_id);

-- reviews
CREATE TABLE "reviews__new" (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_type TEXT NOT NULL, -- 'poem' or 'book'
            target_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            rating INTEGER DEFAULT 0,
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, voice_path TEXT, is_admin_reply INTEGER DEFAULT 0, review_text TEXT, parent_id INTEGER DEFAULT NULL, is_private INTEGER DEFAULT 0, target_user_id INTEGER DEFAULT NULL, likes_count INTEGER DEFAULT 0, deleted_at TIMESTAMP DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
INSERT INTO "reviews__new" ("id", "target_type", "target_id", "user_id", "rating", "comment", "created_at", "updated_at", "voice_path", "is_admin_reply", "review_text", "parent_id", "is_private", "target_user_id", "likes_count", "deleted_at") SELECT "id", "target_type", "target_id", "user_id", "rating", "comment", "created_at", "updated_at", "voice_path", "is_admin_reply", "review_text", "parent_id", "is_private", "target_user_id", "likes_count", "deleted_at" FROM "reviews";
DROP TABLE "reviews";
ALTER TABLE "reviews__new" RENAME TO "reviews";
CREATE INDEX idx_reviews_target ON reviews(target_type, target_id, created_at DESC);
CREATE INDEX idx_reviews_parent ON reviews(parent_id);
UPDATE sqlite_sequence SET seq = 0 WHERE name = 'reviews';

-- sessions
CREATE TABLE "sessions__new" (
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
        );
INSERT INTO "sessions__new" ("id", "user_id", "date", "time", "duration", "status", "message", "notes", "created_at", "updated_at") SELECT "id", "user_id", "date", "time", "duration", "status", "message", "notes", "created_at", "updated_at" FROM "sessions";
DROP TABLE "sessions";
ALTER TABLE "sessions__new" RENAME TO "sessions";
CREATE INDEX idx_sessions_user ON sessions(user_id);
CREATE INDEX idx_sessions_date ON sessions(date);
CREATE INDEX idx_sessions_user_id ON sessions(user_id);

-- user_tags
CREATE TABLE "user_tags__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tag TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        UNIQUE(user_id, tag)
    );
INSERT INTO "user_tags__new" ("id", "user_id", "tag", "created_at") SELECT "id", "user_id", "tag", "created_at" FROM "user_tags";
DROP TABLE "user_tags";
ALTER TABLE "user_tags__new" RENAME TO "user_tags";

-- video_watches
CREATE TABLE "video_watches__new" (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        video_id INTEGER NOT NULL,
        watched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
        UNIQUE(user_id, video_id)
    );
INSERT INTO "video_watches__new" ("id", "user_id", "video_id", "watched_at") SELECT "id", "user_id", "video_id", "watched_at" FROM "video_watches";
DROP TABLE "video_watches";
ALTER TABLE "video_watches__new" RENAME TO "video_watches";
CREATE INDEX idx_video_watches_user ON video_watches(user_id);

-- book_comments
CREATE TABLE "book_comments__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    paragraph_index INTEGER DEFAULT 0,
    comment_text TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved INTEGER DEFAULT 0, comment TEXT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);
INSERT INTO "book_comments__new" ("id", "book_id", "user_id", "paragraph_index", "comment_text", "created_at", "resolved", "comment") SELECT "id", "book_id", "user_id", "paragraph_index", "comment_text", "created_at", "resolved", "comment" FROM "book_comments";
DROP TABLE "book_comments";
ALTER TABLE "book_comments__new" RENAME TO "book_comments";

-- reading_groups
CREATE TABLE "reading_groups__new" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER,
    creator_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    invite_code TEXT UNIQUE NOT NULL,
    is_private INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, content_type VARCHAR(20) DEFAULT 'book', content_id INT DEFAULT 0,
    FOREIGN KEY (book_id) REFERENCES books(id),
    FOREIGN KEY (creator_id) REFERENCES users(id)
);
INSERT INTO "reading_groups__new" ("id", "book_id", "creator_id", "name", "description", "invite_code", "is_private", "created_at", "content_type", "content_id") SELECT "id", "book_id", "creator_id", "name", "description", "invite_code", "is_private", "created_at", "content_type", "content_id" FROM "reading_groups";
DROP TABLE "reading_groups";
ALTER TABLE "reading_groups__new" RENAME TO "reading_groups";
CREATE INDEX idx_reading_groups_content ON reading_groups (content_type, content_id);

-- Part 2: add missing columns
ALTER TABLE "sessions" ADD COLUMN "session_type" TEXT DEFAULT 'spiritual_guidance';
ALTER TABLE "sessions" ADD COLUMN "timezone" TEXT;
ALTER TABLE "reflections" ADD COLUMN "tags" TEXT DEFAULT '';
ALTER TABLE "reflections" ADD COLUMN "slug" TEXT;
ALTER TABLE "reflections" ADD COLUMN "featured" INTEGER DEFAULT 0;
ALTER TABLE "reflections" ADD COLUMN "comment_count" INTEGER DEFAULT 0;
ALTER TABLE "reflections" ADD COLUMN "likes" INTEGER DEFAULT 0;
ALTER TABLE "reflections" ADD COLUMN "loves" INTEGER DEFAULT 0;
ALTER TABLE "reflections" ADD COLUMN "prays" INTEGER DEFAULT 0;
ALTER TABLE "blog_posts" ADD COLUMN "likes" INTEGER DEFAULT 0;
ALTER TABLE "blog_posts" ADD COLUMN "loves" INTEGER DEFAULT 0;
ALTER TABLE "blog_posts" ADD COLUMN "prays" INTEGER DEFAULT 0;
ALTER TABLE "book_comments" ADD COLUMN "page_num" INTEGER;
ALTER TABLE "book_comments" ADD COLUMN "parent_id" INTEGER;
ALTER TABLE "book_comments" ADD COLUMN "is_admin_reply" INTEGER DEFAULT 0;
ALTER TABLE "reflection_comments" ADD COLUMN "photo_path" TEXT;
ALTER TABLE "reflection_comments" ADD COLUMN "voice_path" TEXT;
ALTER TABLE "reflection_comments" ADD COLUMN "is_admin_reply" INTEGER DEFAULT 0;
ALTER TABLE "prayer_requests" ADD COLUMN "book_id" INTEGER;
ALTER TABLE "prayer_requests" ADD COLUMN "request_text" TEXT;
ALTER TABLE "videos" ADD COLUMN "views" INTEGER DEFAULT 0;
ALTER TABLE "videos" ADD COLUMN "likes" INTEGER DEFAULT 0;
ALTER TABLE "videos" ADD COLUMN "dislikes" INTEGER DEFAULT 0;
ALTER TABLE "newsletter" ADD COLUMN "updated_at" DATETIME;
ALTER TABLE "newsletter" ADD COLUMN "source" TEXT DEFAULT 'website';
ALTER TABLE "bookmarks" ADD COLUMN "offset" INTEGER DEFAULT 0;
ALTER TABLE "email_queue" ADD COLUMN "segment_id" INTEGER;
ALTER TABLE "reading_challenges" ADD COLUMN "completed" INTEGER DEFAULT 0;
ALTER TABLE "reading_goals" ADD COLUMN "completed_at" DATETIME;
ALTER TABLE "users" ADD COLUMN "is_admin" INTEGER DEFAULT 0;
UPDATE users SET is_admin = CASE WHEN role = 'admin' THEN 1 ELSE 0 END;

-- Part 3: create missing tables
CREATE TABLE comment_reactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    comment_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reaction_type TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (comment_id, user_id, reaction_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE review_reactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    review_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reaction_type TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (review_id, user_id, reaction_type),
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE video_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    amount REAL,
    payment_status TEXT DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- Part 4: remove the empty table the broken foreign keys pointed at
DROP TABLE IF EXISTS users_old;

-- Part 5: checks. foreign_key_check must return no rows; integrity_check must return 'ok'.
PRAGMA legacy_alter_table = OFF;
PRAGMA foreign_key_check;
PRAGMA integrity_check;
