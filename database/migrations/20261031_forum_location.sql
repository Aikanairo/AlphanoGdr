-- Existing installations: preserve all boards and allow full city names.
ALTER TABLE luoghi MODIFY COLUMN location varchar(255) NOT NULL DEFAULT '';
