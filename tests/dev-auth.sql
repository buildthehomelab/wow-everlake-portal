-- The few auth tables the portal touches, as SQLite, for trying the portal locally without a server.
-- Columns follow TrinityCore's sql/base/auth_database.sql (forever @ 5206a72); the rest are left out.
CREATE TABLE IF NOT EXISTS battlenet_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  srp_version INTEGER NOT NULL DEFAULT 1,
  salt BLOB NOT NULL,
  verifier BLOB NOT NULL,
  joindate TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS account (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  salt BLOB NOT NULL,
  verifier BLOB NOT NULL,
  email TEXT NOT NULL DEFAULT '',
  reg_mail TEXT NOT NULL DEFAULT '',
  joindate TEXT NOT NULL,
  battlenet_account INTEGER,
  battlenet_index INTEGER,
  UNIQUE (battlenet_account, battlenet_index)
);
CREATE TABLE IF NOT EXISTS realmcharacters (
  realmid INTEGER NOT NULL,
  acctid INTEGER NOT NULL,
  numchars INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (realmid, acctid)
);
CREATE TABLE IF NOT EXISTS battlenet_account_bans (
  id INTEGER NOT NULL,
  bandate INTEGER NOT NULL DEFAULT 0,
  unbandate INTEGER NOT NULL DEFAULT 0,
  bannedby TEXT NOT NULL,
  banreason TEXT NOT NULL,
  PRIMARY KEY (id, bandate)
);
CREATE TABLE IF NOT EXISTS realmlist (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);
INSERT OR IGNORE INTO realmlist (id, name) VALUES (1, 'Everlake'), (2, 'Everlake Two');
