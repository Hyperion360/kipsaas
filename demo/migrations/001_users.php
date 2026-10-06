<?php // demo/migrations/001_users.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // Exactly the columns DemoApp::createOwner inserts; nothing more.
        $db->query('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            penname TEXT,
            role TEXT NOT NULL DEFAULT \'member\',
            is_admin INTEGER NOT NULL DEFAULT 0
        )');
        // Kip\Auth::attempt() counts and clears rows here on every login:
        // without the table every login attempt fails.
        $db->query('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT NOT NULL,
            attempted_at TEXT NOT NULL
        )');
        $db->query('CREATE INDEX idx_login_attempts_lookup ON login_attempts (email, ip, attempted_at)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE login_attempts');
        $db->query('DROP TABLE users');
    }
};
