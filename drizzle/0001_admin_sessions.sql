CREATE TABLE admin_sessions (
  token_hash TEXT PRIMARY KEY NOT NULL,
  credential_version TEXT NOT NULL,
  created_at TEXT NOT NULL
);
