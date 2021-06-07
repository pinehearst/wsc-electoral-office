ALTER TABLE `eo_elections` ADD `needsMaxVotes` TINYINT UNSIGNED NOT NULL DEFAULT '0' AFTER `votesPerChoice`;
