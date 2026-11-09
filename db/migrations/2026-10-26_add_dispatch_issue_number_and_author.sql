-- The Journal (what used to be "Dispatches") is a series of numbered issues, each with a named author: the public list
-- shows "Issue 04 - <title>", the summary, the author line and the date, newest issue number first.
--   issue_number  optional, and UNIQUE (two issues cannot both be Issue 02; many entries may have no number: NULLs
--                 do not collide). The column is named in the UNIQUE so MySQL/MariaDB call the key `issue_number`, which
--                 is what the admin editor looks for to say "Issue 02 already exists" under the field.
--   author        the byline shown under the title, e.g. "Dr. Namita Mitra, BVSc & AH, MVSc, PhD, Diplomate ACVM (Virology)".
--                 Not the staff member who typed it in (that is still author_staff_id, shown in the admin list as "Created by").
--
-- MUST be run on the production database BEFORE the code that needs it is deployed: the editor and the public Journal
-- both name these columns, so without them the Journal page and the admin issue editor fail.
ALTER TABLE dispatches
    ADD COLUMN issue_number SMALLINT UNSIGNED NULL UNIQUE AFTER excerpt,
    ADD COLUMN author       VARCHAR(255)       NULL        AFTER issue_number;
