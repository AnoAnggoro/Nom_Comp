ALTER TABLE chem_match_reads ADD COLUMN completion_time INT NULL AFTER read_at;
ALTER TABLE chem_match_reads ADD INDEX idx_chem_match_completion_time (completion_time);

ALTER TABLE word_search_reads ADD COLUMN completion_time INT NULL AFTER read_at;
ALTER TABLE word_search_reads ADD INDEX idx_word_search_completion_time (completion_time);

ALTER TABLE simulation_reads ADD COLUMN completion_time INT NULL AFTER read_at;
ALTER TABLE simulation_reads ADD INDEX idx_simulation_completion_time (completion_time);
