CREATE TABLE IF NOT EXISTS "chwala_locks" (
    "uri"     varchar(512) NOT NULL,
    "owner"   varchar(256),
    "timeout" integer,
    "expires" timestamp DEFAULT NULL,
    "token"   varchar(256),
    "scope"   smallint,
    "depth"   smallint
);

CREATE INDEX "uri_index" ON "chwala_locks" ("uri", "depth");
CREATE INDEX "expires_index" ON "chwala_locks" ("expires");
CREATE INDEX "token_index" ON "chwala_locks" ("token");

INSERT INTO "system" ("name", "value") VALUES ('chwala-version', '2013111300');
