<?php
class Context {
	private
		$tz;

	public
		$f3,
		$eo,
		$forum,
		$user;

	public function rerouteToForum() {
		$this->f3->reroute(
			$this->f3->get('forum.url')
		);
	}

	public function getCookiePrefix() {
		$query = 'SELECT optionValue FROM {wcf}option WHERE optionName = "cookie_prefix" AND packageID = 1';
		return $this->forum->doQuery($query)->fetchColumn();
	}

	public function getUserIDFromSessionID($sessionID) {
		$query = 'SELECT userID FROM {wcf}session WHERE sessionID = ?';
		return $this->forum->doQuery($query, $sessionID)->fetchColumn();
	}

	public function getUserData($userID) {
		$query = 'SELECT userID, username FROM {wcf}user WHERE userID = ?';
		if (empty($user = $this->forum->doQuery($query, $userID)->fetch())) {
			$this->rerouteToForum();
		}

		$user['isAdmin'] = false;
		$user['isSuperAdmin'] = false;

		$query = 'SELECT COUNT(*) FROM {wcf}user_to_group WHERE userID = ? AND groupID = ?';
		if (!empty($this->forum->doQuery($query, $userID, $this->f3->get('eo.adminGroupID'))->fetchColumn())) {
			$user['isAdmin'] = true;
		}
		if (!empty($this->forum->doQuery($query, $userID, $this->f3->get('eo.superAdminGroupID'))->fetchColumn())) {
			$user['isAdmin'] = true;
			$user['isSuperAdmin'] = true;
		}

		return $user;
	}

	public function getUserFromSession() {
		if (empty($cookiePrefix = $this->getCookiePrefix())) {
			$this->rerouteToForum();
		}
		if (empty($sessionID = $this->f3->get(sprintf('COOKIE.%scookieHash', $cookiePrefix)))) {
			$this->rerouteToForum();
		}
		if (empty($userID = $this->getUserIDFromSessionID($sessionID))) {
			$this->rerouteToForum();
		}
		if (empty($user = $this->getUserData($userID))) {
			$this->rerouteToForum();
		}
		return $user;
	}

	public function beforeRoute($f3) {
		$this->f3 =& $f3;
		$this->tz = new DateTimeZone($this->f3->get('TZ'));

		$this->forum = new Database($this->f3->get('forum.db'));
		$this->eo = new Database($this->f3->get('eo.db'));

		$this->user = $this->getUserFromSession();
		$this->f3->set('user', $this->user);
	}

	public function grab($what, $else = null, $from = null) {
		if (empty($from)) {
			$from =& $_REQUEST;
		}
		return $from[$what] ?? $else;
	}

	public function ts2date($timestamp) {
		if(empty($timestamp)) {
			return '';
		}
		$date = new DateTime("@$timestamp");
		$date->setTimezone($this->tz);
		return $date ? $date->format($this->f3->get('eo.dateFormat')) : '';
	}

	public function date2ts($date) {
		$t = DateTime::createFromFormat($this->f3->get('eo.dateFormat'), $date, $this->tz);
		return $t ? $t->getTimestamp() : 0;
	}

	public function getUserByName($name) {
		$query = 'SELECT * FROM {wcf}user WHERE username = ?';
		return $this->forum->doQuery($query, $name)->fetch();
	}

	public function getUsersByName($names) {
		$query = 'SELECT * FROM {wcf}user WHERE username = ?';
		$stmt = $this->forum->doPrepare($query);

		$users = [];

		foreach($names as $name) {
			$stmt->execute([$name]);
			if($user = $stmt->fetch()) {
				$users[] = $user;
			}
		}

		return $users;
	}

	public function getVoteStatus($election) {
		$query = 'SELECT COUNT(*) AS electors, SUM(voteDate IS NOT NULL) as votes FROM {eo}electors WHERE electionID = ?';
		$prelim = $this->eo->doQuery($query, $election['electionID'])->fetch();
		$prelim['perc'] = sprintf('%2.2f', $prelim['votes'] / max([$prelim['electors'], 1]) * 100);
		return $prelim;
	}

	public function getChoices($electionID) {
		$query = 'SELECT * FROM {eo}choices WHERE electionID = ?';
		return $this->eo->doQuery($query, $electionID)->fetchAll();
	}

	public function getElectors($electionID) {
		$query = 'SELECT * FROM {eo}electors WHERE electionID = ?';
		return $this->eo->doQuery($query, $electionID)->fetchAll();
	}

	public function getElection($electionID) {
		$query = 'SELECT * FROM {eo}elections WHERE electionID = ?';
		return $this->eo->doQuery($query, $electionID)->fetch();
	}

	// TODO this seems strange, follow up
	public function canEdit($election = null) {
		if(empty($election)) {
			return $this->user['isAdmin'];
		}
		if($this->user['isSuperAdmin']) {
			return true;
		}
		return $election['userID'] == $this->user['userID'];
	}

	private function updateElectors($electionID, $electors) {
		$query = 'DELETE FROM {eo}electors WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);

		$query = 'INSERT INTO {eo}electors (electionID, userID) VALUES (?, ?)';
		foreach ($electors as $userID) {
			$this->eo->doQuery($query, $electionID, $userID);
		}
	}

	private function updateChoices($electionID, $choices) {
		$query = 'DELETE FROM {eo}choices WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);

		$query = 'INSERT INTO {eo}choices (electionID, title, color) VALUES (?, ?, ?)';
		foreach ($choices as $title => $color) {
			$this->eo->doQuery($query, $electionID, $title, $color);
		}
	}

	public function setElection($electionID, $data) {
		$query = 'UPDATE {eo}elections SET title = ?, info = ?, startDate = ?, endDate = ?, votes = ? WHERE electionID = ?';
		$this->eo->doQuery($query,
			$data['title'],
			$data['info'],
			$data['startDate'],
			$data['endDate'],
			$data['votes'],
			//$data['votesPerChoice'],
			$electionID
		);

		$this->updateElectors($electionID, $data['electors']);
		$this->updateChoices($electionID, $data['choices']);
		$this->addHistory($electionID, 'updated');
	}

	public function addHistory($electionID, $action) {
		$query = 'INSERT INTO {eo}history (electionID, userID, actionDate, action) VALUES (?, ?, ?, ?)';
		$this->eo->doQuery($query, $electionID, $this->user['userID'], time(), $action);
	}

	public function getGroupMap() {
		$groups = [];

		$query = 'SELECT groupID, groupName FROM {wcf}user_group';
		foreach($this->forum->doQuery($query) as $group) {
			$groups[$group['groupID']] = $group['groupName'];
		}

		return $groups;
	}

	public function getElector($electionID, $userID) {
		$query = 'SELECT * FROM {eo}electors WHERE electionID = ? AND userID = ?';
		return $this->eo->doQuery($query, $electionID, $userID)->fetch();
	}

	public function setElectionStarted($electionID) {
		$query = 'UPDATE {eo}elections SET isStarted = 1 WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);
	}

	public function setElectionStopped($electionID) {
		$query = 'UPDATE {eo}elections SET isStarted = 0 WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);
	}

	public function setElectionCanceled($electionID) {
		$query = 'UPDATE {eo}elections SET isCanceled = 1 WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);
	}

	public function setElectionPublished($electionID) {
		$query = 'UPDATE {eo}elections SET isPublished = 1 WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);
	}

	public function setElectionClosed($electionID) {
		$query = 'UPDATE {eo}elections SET endDate = ? WHERE electionID = ?';
		$this->eo->doQuery($query, time(), $electionID);
	}

	public function getVotes($election) {
		$votes = [
			'invalids'		=> 0,
			'abstentions'	=> 0,
			'voters'		=> 0,
			'voted'			=> 0,
			'choices'		=> [],
		];

		$query = 'SELECT * FROM {eo}electors WHERE electionID = ?';
		foreach($this->eo->doQuery($query, $election['electionID']) as $elector) {
			$votes['voters'] += 1;

			if(intval($elector['voteDate']) > 0) {
				$votes['voted'] += 1;

				if($elector['votes'] === null) {
					$votes['invalids'] += 1;
				}
				else {
					$votes['abstentions'] += ($election['votes'] - $elector['votes']);
				}
			}
		}

		$votes['perc'] = sprintf('%2.2f', $votes['voted'] / max([$votes['voters'], 1]) * 100);

		$query = 'SELECT * FROM {eo}votes WHERE choiceID = ?';
		$stmt = $this->eo->doPrepare($query);

		$sumVotes = 0;
		$query = 'SELECT * FROM {eo}choices WHERE electionID = ?';
		foreach($this->eo->doQuery($query, $election['electionID']) as $choice) {
			$stmt->execute([$choice['choiceID']]);
			$votes['choices'][$choice['choiceID']] = [
				'sum' => 0,
				'perc' => 0,
			];
			foreach($stmt->fetchAll() as $vote) {
				$votes['choices'][$choice['choiceID']]['sum'] += $vote['votes'];
				$sumVotes += $vote['votes'];
			}
		}

		$max = max([1, $sumVotes]);
		foreach($votes['choices'] as $key => & $value) {
			$value['perc'] = sprintf('%2.2f', $value['sum'] / $max * 100);
		}

		return $votes;
	}

	public function newElection($title) {
		$start = time();
		$start -= $start % 60;
		$start += 60 * 60;

		$end = $start + 60 * 60 * 24 * 5;
		/*
		$date = new DateTime();
		$start = $date->getTimestamp() + 60 * 60;
		$end = $start + 60 * 60 * 24 * 5;
		*/
		$query = 'INSERT INTO {eo}elections (title, userID, startDate, endDate) VALUES (?, ?, ?, ?)';
		$this->eo->doQuery($query, $title, $this->user['userID'], $start, $end);
		return $this->eo->lastInsertId();
	}

	public function getHistory($electionID) {
		$history = [];
		$query = 'SELECT * FROM {eo}history WHERE electionID = ? ORDER BY actionDate DESC';
		foreach($this->eo->doQuery($query, $electionID) as $entry) {
			$query = 'SELECT username FROM {wcf}user WHERE userID = ?';
			$history[] = $entry + [
				'username' => $this->forum->doQuery($query, $entry['userID'])->fetchColumn(),
				'actionDateFormatted' => $this->ts2date($entry['actionDate']),
			];
		}
		return $history;
	}

	public function getElectionState($election) {
		$now = time();

		if($election['isCanceled']) {
			return ElectionState::CANCELED;
		}
		else
		if($election['isPublished']) {
			return ElectionState::PUBLISHED;
		}
		else
		if(!$election['isStarted']) {
			$query = 'SELECT COUNT(*) FROM {eo}electors WHERE electionID = ?';
			$electors = $this->eo->doQuery($query, $election['electionID'])->fetchColumn();
			$query = 'SELECT COUNT(*) FROM {eo}choices WHERE electionID = ?';
			$choices = $this->eo->doQuery($query, $election['electionID'])->fetchColumn();

			if($electors) {
				if($choices) {
					if($election['votes'] > 0) {
						if($election['startDate'] > $now) {
							if($election['endDate'] > $election['startDate']) {
								return ElectionState::READY;
							}
						}
					}
				}
			}

			return ElectionState::INPREP;
		}
		else
		if($now < $election['startDate']) {
			return ElectionState::UPCOMING;
		}
		else
		if($now < $election['endDate']) {
			$query = 'SELECT COUNT(*) AS electors, SUM(voteDate IS NOT NULL) as votes FROM {eo}electors WHERE electionID = ?';
			$prelim = $this->eo->doQuery($query, $election['electionID'])->fetch();

			if($prelim['electors'] == $prelim['votes']) {
				return ElectionState::CLOSEABLE;
			}

			return ElectionState::RUNNING;
		}
		else {
			return ElectionState::CLOSED;
		}
	}
}
