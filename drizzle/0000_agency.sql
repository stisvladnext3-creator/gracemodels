CREATE TABLE models (
  id TEXT PRIMARY KEY NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  name_en TEXT NOT NULL DEFAULT '',
  gender TEXT NOT NULL DEFAULT 'women',
  category TEXT NOT NULL DEFAULT 'main',
  city TEXT NOT NULL DEFAULT '',
  height INTEGER NOT NULL DEFAULT 175,
  bust INTEGER NOT NULL DEFAULT 84,
  waist INTEGER NOT NULL DEFAULT 61,
  hips INTEGER NOT NULL DEFAULT 90,
  shoe INTEGER NOT NULL DEFAULT 39,
  hair TEXT NOT NULL DEFAULT 'brown',
  eyes TEXT NOT NULL DEFAULT 'brown',
  cover TEXT NOT NULL DEFAULT '',
  gallery TEXT NOT NULL DEFAULT '[]',
  bio TEXT NOT NULL DEFAULT '',
  bio_en TEXT NOT NULL DEFAULT '',
  published INTEGER NOT NULL DEFAULT 0,
  featured INTEGER NOT NULL DEFAULT 0,
  demo INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
--> statement-breakpoint
CREATE TABLE inquiries (
  id TEXT PRIMARY KEY NOT NULL,
  kind TEXT NOT NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  city TEXT NOT NULL DEFAULT '',
  age INTEGER,
  height INTEGER,
  instagram TEXT NOT NULL DEFAULT '',
  model_id TEXT NOT NULL DEFAULT '',
  model_name TEXT NOT NULL DEFAULT '',
  company TEXT NOT NULL DEFAULT '',
  shooting_date TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  attachments TEXT NOT NULL DEFAULT '[]',
  status TEXT NOT NULL DEFAULT 'new',
  internal_note TEXT NOT NULL DEFAULT '',
  consent INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
--> statement-breakpoint
CREATE TABLE news (
  id TEXT PRIMARY KEY NOT NULL,
  title TEXT NOT NULL,
  title_en TEXT NOT NULL DEFAULT '',
  summary TEXT NOT NULL DEFAULT '',
  summary_en TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  body_en TEXT NOT NULL DEFAULT '',
  image TEXT NOT NULL DEFAULT '',
  published INTEGER NOT NULL DEFAULT 0,
  published_at TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
--> statement-breakpoint
CREATE TABLE settings (
  key TEXT PRIMARY KEY NOT NULL,
  value TEXT NOT NULL
);
--> statement-breakpoint
CREATE TABLE activity (
  id TEXT PRIMARY KEY NOT NULL,
  action TEXT NOT NULL,
  subject TEXT NOT NULL,
  created_at TEXT NOT NULL
);
--> statement-breakpoint
CREATE TABLE request_limits (
  key TEXT PRIMARY KEY NOT NULL,
  count INTEGER NOT NULL DEFAULT 1,
  expires_at INTEGER NOT NULL
);
--> statement-breakpoint
CREATE INDEX inquiries_created_at_idx ON inquiries(created_at);
--> statement-breakpoint
CREATE INDEX models_public_idx ON models(published, sort_order);
