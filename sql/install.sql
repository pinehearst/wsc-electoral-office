CREATE TABLE `eo_choices` (
  `electionID` int(10) unsigned NOT NULL,
  `choiceID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(32) NOT NULL,
  `color` varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`choiceID`),
  KEY `electionID` (`electionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eo_elections` (
  `electionID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL,
  `info` text NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `startDate` int(10) unsigned NOT NULL DEFAULT '0',
  `endDate` int(10) unsigned NOT NULL DEFAULT '0',
  `votes` int(10) unsigned NOT NULL DEFAULT '1',
  `isStarted` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `isCanceled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `isPublished` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`electionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eo_electors` (
  `electionID` int(10) unsigned NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `voteDate` varchar(16) DEFAULT NULL,
  `votes` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`electionID`,`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eo_history` (
  `historyID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `electionID` int(10) unsigned NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `actionDate` int(10) unsigned NOT NULL,
  `action` varchar(32) NOT NULL,
  PRIMARY KEY (`historyID`),
  KEY `electionID` (`electionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eo_votes` (
  `choiceID` int(10) unsigned NOT NULL,
  `voteID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `votes` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`voteID`,`choiceID`),
  KEY `choiceID` (`choiceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `eo_choices`
  ADD CONSTRAINT `choices_ibfk_1` FOREIGN KEY (`electionID`) REFERENCES `eo_elections` (`electionID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `eo_electors`
  ADD CONSTRAINT `electors_ibfk_1` FOREIGN KEY (`electionID`) REFERENCES `eo_elections` (`electionID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `eo_history`
  ADD CONSTRAINT `history_ibfk_1` FOREIGN KEY (`electionID`) REFERENCES `eo_elections` (`electionID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `eo_votes`
  ADD CONSTRAINT `votes_ibfk_1` FOREIGN KEY (`choiceID`) REFERENCES `eo_choices` (`choiceID`) ON DELETE CASCADE ON UPDATE CASCADE;
