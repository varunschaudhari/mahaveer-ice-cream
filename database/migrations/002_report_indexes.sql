-- 002 (M9): indexes for the reports and the day close.
-- ReportService finds bills by the time they were issued: orders.completed_at and
-- table_sessions.closed_at. Without these, every report reads the whole table, which gets slow
-- after a year of orders. No columns or tables change.

ALTER TABLE orders ADD INDEX idx_completed_at (completed_at);
ALTER TABLE table_sessions ADD INDEX idx_closed_at (closed_at);
