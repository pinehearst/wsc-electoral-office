<?php
class Action extends Context {
	function afterRoute($f3) {
		$f3->reroute('/');
	}

	/**************************************************************************/

	function vote($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election || $this->getElectionState($election) != ElectionState::RUNNING) {
			$f3->error(404);
		}

		$elector = $this->getElector($electionID, $this->user['userID']);

		if(!$elector || !empty($elector['voteDate'])) {
			$f3->error(404);
		}

		$choices = [];
		foreach($this->getChoices($electionID) as $choice) {
			$choices[] = $choice['choiceID'];
		}

		$broken = false;
		$votes = [];
		foreach($_POST as $key => $value) {
			$split = explode('_', $key);

			if(count($split) != 2) {
				continue;
			}

			list($junk, $choice) = $split;

			if($junk != 'choice') {
				continue;
			}

			$choice = trim($choice);
			$value = trim($value);

			if(preg_match('/\D/', $value) || preg_match('/\D/', $choice)) {
				$broken = true;
				break;
			}

			$choice = intval($choice);
			$value = intval($value);
			/*
			if($value < 0) {
				$broken = true;
				break;
			}
			*/
			if(!in_array($choice, $choices)) {
				$broken = true;
				break;
			}

			if(isset($votes[$choice])) {
				$broken = true;
				break;
			}

			if($value > $election['votesPerChoice']) {
				$broken = true;
				break;
			}

			$votes[$choice] = $value;
		}

		if($broken) {
			$votes = [];
		}

		$sumVotes = array_sum(array_values($votes));

		if($sumVotes > $election['votes']) {
			$query = 'UPDATE {eo}electors SET voteDate = ?, votes = NULL WHERE electionID = ? AND userID = ?';
			$this->eo->doQuery($query, time(), $electionID, $this->user['userID']);
		}
		else {
			$query = 'UPDATE {eo}electors SET voteDate = ?, votes = ? WHERE electionID = ? AND userID = ?';
			$this->eo->doQuery($query, time(), $sumVotes, $electionID, $this->user['userID']);

			foreach($votes as $choice => $sum) {
				if($sum > 0) {
					$query = 'INSERT INTO {eo}votes (choiceID, votes) VALUES (?, ?)';
					$this->eo->doQuery($query, $choice, $sum);
				}
			}
		}

		#$this->addHistory($electionID, 'voted');

		$f3->reroute("/election/$electionID");
	}

	function start($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}
		if($this->getElectionState($election) != ElectionState::READY) {
			$f3->error(404);
		}

		$this->setElectionStarted($electionID);
		$this->addHistory($electionID, 'started');

		$f3->reroute('/admin');
	}

	function stop($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}
		if($this->getElectionState($election) != ElectionState::UPCOMING) {
			$f3->error(404);
		}

		$this->setElectionStopped($electionID);
		$this->addHistory($electionID, 'stopped');

		$f3->reroute('/admin');
	}

	function cancel($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}

		$this->setElectionCanceled($electionID);
		$this->addHistory($electionID, 'canceled');

		$f3->reroute('/admin');
	}

	function add($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}

		$name = trim($this->grab('name'));

		if(!$name) {
			$f3->reroute("/election/$electionID");
		}

		$user = $this->getUserByName($name);

		if(empty($user)) {
			$f3->reroute("/election/$electionID?saved=-1");
		}

		$query = 'SELECT 1 FROM {eo}electors WHERE electionID = ? AND userID = ?';
		$found = $this->eo->doQuery($query, $electionID, $user['userID'])->fetchColumn();

		if($found) {
			$f3->reroute("/election/$electionID?saved=2");
		}

		$query = 'INSERT INTO {eo}electors (electionID, userID) VALUES (?, ?)';
		$this->eo->goQuery($query, $electionID, $user['userID']);

		$this->addHistory($electionID, sprintf('belated elector %d', $user['userID']));

		$f3->reroute("/election/$electionID?saved=1");
	}

	function edit($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}

		$data = [
			'choices',
			'title',
			'info',
			'votes',
			'votesPerChoice',
			'startDate',
			'endDate'
		];

		foreach($data as & $d) {
			$data[$d] = trim($this->grab($d));
		}
		$data['electors'] = trim($this->grab('electors'));

		if(!$data['title']) {
			$data['title'] = $election['title'];
		}

		$data['votes'] = intval($data['votes']);

		if($data['votes'] < 1) {
			$data['votes'] = 1;
		}

		$data['votesPerChoice'] = intval($data['votesPerChoice']);

		if($data['votesPerChoice'] < 0) {
			$data['votesPerChoice'] = 0;
		}
		if($data['votesPerChoice'] > $data['votes']) {
			$data['votesPerChoice'] = $data['votes'];
		}

		$data['startDate'] = $this->date2ts($data['startDate']);
		$data['endDate'] = $this->date2ts($data['endDate']);

		$data['electors'] = array_unique(explode("\n", str_replace("\r", '', $data['electors'])));
		$data['choices'] = array_unique(explode("\n", str_replace("\r", '', $data['choices'])));

		$choices = [];
		foreach($data['choices'] as $choice) {
			if(empty($choice)) {
				continue;
			}

			$split = explode(':', $choice);
			$title = trim($split[0]);
			$color = trim($split[1] ?? '');

			if(!empty($title)) {
				$choices[$title] = $color;
			}
		}

		$data['choices'] = $choices;

		if($data['votesPerChoice'] > 0) {
			if(count($choices) * $data['votesPerChoice'] < $data['votes']) {
				$data['votes'] = count($choices) * $data['votesPerChoice'];
			}
		}

		$missingElectors = [];
		foreach($data['electors'] as $index => & $elector) {
			if($elector = trim($elector)) {
				$missingElectors[$elector] = true;
			}
			else {
				unset($data['electors'][$index]);
			}
		}
		$electors = [];
		foreach($this->getUsersByName($data['electors']) as $user) {
			$electors[] = $user['userID'];
			unset($missingElectors[$user['username']]);
		}
		$data['electors'] = $electors;

		$this->setElection($electionID, $data);

		if(count($missingElectors)) {
			$f3->reroute("/election/$electionID?saved=-" . count($missingElectors));
		}

		$f3->reroute("/election/$electionID?saved=1");
	}

	function publish($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$this->canEdit($election)) {
			$f3->error(404);
		}

		$this->setElectionPublished($electionID);
		$this->addHistory($electionID, 'published');

		$f3->reroute("/election/$electionID");
	}

	function remove($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election || !$this->canEdit($election) || $this->getElectionState($election) != ElectionState::CANCELED) {
			$f3->error(404);
		}

		$query = 'DELETE FROM {eo}elections WHERE electionID = ?';
		$this->eo->doQuery($query, $electionID);

		$f3->reroute('/archive');
	}

	function klonen($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election || !$this->canEdit($election)) {
			$f3->error(404);
		}

		$newElectionID = $this->newElection('Klon');
		$this->addHistory($newElectionID, 'cloned');

		$query = 'UPDATE {eo}elections SET title = ?, info = ?, startDate = ?, endDate = ?, votes = ?, votesPerChoice = ? WHERE electionID = ?';
		$this->eo->doQuery($query,
			'Klon von ' . $election['title'],
			$election['info'],
			$election['startDate'],
			$election['endDate'],
			$election['votes'],
			$election['votesPerChoice'],
			$newElectionID
		);

		$query = 'INSERT INTO {eo}electors (electionID, userID) SELECT ?, userID FROM {eo}electors WHERE electionID = ?';
		$this->eo->doQuery($query, $newElectionID, $electionID);

		$query = 'INSERT INTO {eo}choices (electionID, title, color) SELECT ?, title, color FROM {eo}choices WHERE electionID = ?';
		$this->eo->doQuery($query, $newElectionID, $electionID);

		$f3->reroute("/election/$newElectionID");
	}

	function close($f3, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election || !$this->canEdit($election) || $this->getElectionState($election) != ElectionState::CLOSEABLE) {
			$f3->error(404);
		}

		$this->setElectionClosed($electionID);
		$this->addHistory($electionID, 'closed');

		$f3->reroute("/election/$electionID");
	}

	function create($f3, $params) {
		$this->canEdit() || $f3->error(404);

		$title = trim($this->grab('title', 'Unbennate Wahl'));

		$electionID = $this->newElection($title);
		$this->addHistory($electionID, 'created');

		$f3->reroute("/election/$electionID");
	}
}
