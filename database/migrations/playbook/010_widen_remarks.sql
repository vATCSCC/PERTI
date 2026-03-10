-- Migration 010: Widen remarks columns from VARCHAR(500) to TEXT for multi-line support
-- Both playbook_plays.remarks and playbook_routes.remarks

ALTER TABLE playbook_plays MODIFY COLUMN remarks TEXT NULL DEFAULT NULL;
ALTER TABLE playbook_routes MODIFY COLUMN remarks TEXT NULL DEFAULT NULL;
