-- Fixture script for Migration::runSqlScript() tests
CREATE TABLE IF NOT EXISTS notes (id TEXT PRIMARY KEY, body TEXT);
INSERT INTO notes (id, body) VALUES ('n1', 'hello');
INSERT INTO notes (id, body) VALUES ('n2', 'world');
