<?php // demo/migrations/002_notes.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        $db->query('CREATE TABLE notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        // The list page reads one user newest first: one index shape, the
        // exact WHERE plus ORDER BY it serves.
        $db->query('CREATE INDEX idx_notes_user_id ON notes (user_id, id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE notes');
    }
};
