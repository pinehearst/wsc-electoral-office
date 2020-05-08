<?php
class Page extends Context {
	function afterRoute($framework) {
		echo Template::instance()->render('layout.html');
	}

	/**************************************************************************/

	// MAPPING HANDLER

	function index($framework) {
		$this->defaultPage(true);
	}

	function overview($framework) {
		$this->defaultPage(false);
	}

	function archive($framework) {
		$framework->set('pageTitle', 'Archiv');
		$framework->set('content', 'archive.html');

		$elections = [];

		$query = 'SELECT COUNT(*) FROM {eo}elections WHERE (isStarted = 1 AND endDate <= ?) OR (isCanceled = 1)';
		$numElections = $this->eo->doQuery($query, time())->fetchColumn();

		$query = 'SELECT * FROM {eo}electors WHERE userID = ? AND electionID = ?';
		$electors = $this->eo->doPrepare($query, $this->user['userID']);

		$query = 'SELECT * FROM {eo}history WHERE action = ? AND electionID = ?';
		$history = $this->eo->doPrepare($query, 'canceled');

		$query = 'SELECT * FROM {wcf}user WHERE userID = ?';
		$stmt = $this->forum->doPrepare($query);

		$perPage = intval($framework->get('eo.paginationSize'));
		$curPage = min([ceil($numElections / $perPage), max([1, intval($this->grab('page', 1))])]);
		$firstEntry = max([0, $perPage * ($curPage - 1) + 1]);
		$lastEntry = max([0, $firstEntry + $perPage - 1]);

		$cur = 0;
		$query = 'SELECT * FROM {eo}elections WHERE (isStarted = 1 AND endDate <= ?) OR (isCanceled = 1) ORDER BY electionID DESC';
		foreach($this->eo->doQuery($query, time()) as $election) {
			$cur += 1;

			if($cur < $firstEntry) {
				continue;
			}
			if($cur > $lastEntry) {
				break;
			}

			$electors->execute([$election['electionID']]);
			$elector = $electors->fetch();

			$election += [
				'startDateFormatted'	=> $this->ts2date($election['startDate']),
				'endDateFormatted'		=> $this->ts2date($election['endDate']),
				'canceledBy'			=> 0,
				'canceledByName'		=> 'unbekannt',
				'canceledOn'			=> 'unbekannt',
				'canVote'				=> $elector !== false,
				'hasVoted'				=> $elector !== false ? !empty($elector['voteDate']) : false,
			];

			if($election['isCanceled']) {
				$history->execute([$election['electionID']]);
				$entry = $history->fetch();

				if($entry) {
					$stmt->execute([$entry['userID']]);
					$user = $stmt->fetch();

					$election['canceledBy']		= $entry['userID'];
					$election['canceledByName']	= $user['username'];
					$election['canceledOn']		= $this->ts2date($entry['actionDate']);
				}
			}

			$elections[] = $election;
		}

		$framework->mset(
			[
				'elections'		=> $elections,
				'numElections'	=> $numElections,
				'curPage'		=> $curPage,
				'perPage'		=> $perPage,
				'prevPage'		=> $firstEntry > 1 ? ($curPage - 1) : false,
				'nextPage'		=> $lastEntry < $numElections ? ($curPage + 1) : false,
				'lastPage'		=> $numElections > $lastEntry ? (ceil($numElections / $perPage)) : false,
				'firstEntry'	=> count($elections) == 0 && $firstEntry == 1 ? 0 : $firstEntry,
				'lastEntry'		=> min([$lastEntry, $firstEntry + count($elections) - 1]),
			]
		);
	}

	function admin($framework) {
		$this->canEdit() || $framework->error(404);

		$closed = [];
		$inprep = [];
		$upcoming = [];

		$query = 'SELECT * FROM {wcf}user WHERE userID = ?';
		$stmt = $this->forum->doPrepare($query);

		$query = 'SELECT * FROM {eo}elections';
		foreach($this->eo->doQuery($query) as $election) {
			$election['startDateFormatted'] = $this->ts2date($election['startDate']);
			$election['endDateFormatted'] = $this->ts2date($election['endDate']);
			$election['isReady'] = false;
			$election['canEdit'] = $this->canEdit($election);

			$stmt->execute([$election['userID']]);
			$user = $stmt->fetch();

			$election['creator'] = $user['username'];

			switch($this->getElectionState($election)) {
				case ElectionState::READY:
					$election['isReady'] = true;
				case ElectionState::INPREP:
					$inprep[] = $election;
					break;

				case ElectionState::UPCOMING:
					$upcoming[] = $election;
					break;

				case ElectionState::CLOSED:
					$closed[] = $election;
					break;
			}
		}

		$framework->mset(
			[
				'pageTitle'	=> 'Wahladministration',
				'content'	=> 'admin.html',
				'inprep'	=> $inprep,
				'closed'	=> $closed,
				'upcoming'	=> $upcoming,
			]
		);
	}

	function fix($framework, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election) {
			$framework->error(404);
		}
	}

	function election($framework, $params) {
		$electionID = intval($params['id']);
		$election = $this->getElection($electionID);

		if(!$election) {
			$framework->error(404);
		}

		$query = 'SELECT * FROM {wcf}user WHERE userID = ?';
		$stmt = $this->forum->doPrepare($query);

		$electors = [];
		foreach($this->getElectors($election['electionID']) as $elector) {
			$stmt->execute([$elector['userID']]);
			$user = $stmt->fetch();

			$electors[] = $user['username'];
		}

		$now = new DateTime();

		$election['currentDateFormatted'] = $this->ts2date($now->getTimestamp());
		$election['startDateFormatted'] = $this->ts2date($election['startDate']);
		$election['endDateFormatted'] = $this->ts2date($election['endDate']);
		$election['choices'] = $this->getChoices($election['electionID']);
		$election['state'] = $this->getElectionState($election);
		$election['stateName'] = ElectionState::getName($election['state']);
		$election['electors'] = $electors;

		if($election['canEdit'] = $this->canEdit($election)) {
			if($election['state'] == ElectionState::INPREP ||
				$election['state'] == ElectionState::READY) {
				return $this->electionEditPage($election);
			}
		}

		$this->electionShowPage($election);
	}

	/**************************************************************************/

	// ADDITIONAL FUNCTIONS

	function defaultPage($personal = false) {
		$upcoming = [];
		$running = [];
		$closed = [];

		if($personal) {
			$query = 'SELECT * FROM {eo}elections JOIN {eo}electors USING (electionID) WHERE {eo}electors.userID = ?';
			$elections = $this->eo->doQuery($query, $this->user['userID']);
		}
		else {
			$query = 'SELECT * FROM {eo}elections';
			$elections = $this->eo->doQuery($query);

			$query = 'SELECT * FROM {eo}electors WHERE userID = ? AND electionID = ?';
			$stmt = $this->eo->doPrepare($query, $this->user['userID']);
		}

		foreach($elections->fetchAll() as $election) {
			$election += [
				'startDateFormatted'	=> $this->ts2date($election['startDate']),
				'endDateFormatted'		=> $this->ts2date($election['endDate']),
			];

			if($personal) {
				$election['hasVoted'] = !empty($election['voteDate']);
			}
			else {
				$stmt->execute([$election['electionID']]);
				$elector = $stmt->fetch();

				$election += [
					'canVote'	=> $elector !== false,
					'hasVoted'	=> $elector !== false ? !empty($elector['voteDate']) : false,
				];
			}

			switch($this->getElectionState($election)) {
				case ElectionState::RUNNING:
				case ElectionState::CLOSEABLE:
					$running[] = $election;
					break;

				case ElectionState::UPCOMING:
					$upcoming[] = $election;
					break;

				case ElectionState::CLOSED:
				case ElectionState::PUBLISHED:
					if(time() <= $election['endDate'] + $this->f3->get('eo.recentlyClosed')) {
						$closed[] = $election;
					}
					break;
			}
		}

		$this->f3->mset(
			[
				'content' => $personal ? 'index.html' : 'overview.html',
				'pageTitle' => $personal ? 'Mein Wahlamt' : 'Übersicht',

				'running'	=> $running,
				'upcoming'	=> $upcoming,
				'closed'	=> $closed,
			]
		);
	}

	function electionEditPage($election) {
		$choices = [];
		foreach($election['choices'] as $choice) {
			if($choice['color']) {
				$choices[] = sprintf('%s:%s', $choice['title'], $choice['color']);
			}
			else {
				$choices[] = $choice['title'];
			}
		}

		sort($election['electors'], SORT_STRING);

		$this->f3->mset(
			[
				'content'			=> 'edit.html',
				'pageTitle'			=> 'Wahl bearbeiten',
				'election'			=> $election,
				'electorsString'	=> implode("\n", $election['electors']),
				'choicesString'		=> implode("\n", $choices),
				'userGroups'		=> $this->getGroupMap(),
				'canStart'			=> $election['state'] == ElectionState::READY,
				'history'			=> $this->getHistory($election['electionID']),
				'saved'				=> $this->grab('saved'),
			]
		);
	}

	function electionShowPage($election) {
		if($elector = $this->getElector($election['electionID'], $this->user['userID'])) {
			$elector['voteDateFormatted'] = $this->ts2date($elector['voteDate']);
			$elector['secretCode'] = substr(sha1($election['electionID'] . $this->user['userID']), 0, 10);
		}

		$this->f3->mset(
			[
				'content'			=> 'show.html',
				'pageTitle'			=> 'Wahl' . ($election['state'] == ElectionState::PUBLISHED ? 'ergebnisse' : 'kabine'),
				'election'			=> $election,
				'elector'			=> $elector,
				'canVote'			=> empty($elector) == false && empty($elector['voteDate']) && $election['state'] == ElectionState::RUNNING,
				'canClose'			=> $election['state'] == ElectionState::CLOSEABLE,
				'canSeeResults'		=> $election['state'] == ElectionState::PUBLISHED || ($election['state'] == ElectionState::CLOSED && $election['canEdit']),
				'canStop'			=> $election['state'] == ElectionState::UPCOMING,
				'canPublish'		=> $election['state'] == ElectionState::CLOSED,
				'canRemove'			=> $election['state'] == ElectionState::CANCELED && $election['canEdit'],
				'canAddElector'		=> $election['canEdit'] && in_array($election['state'], [ElectionState::RUNNING, ElectionState::CLOSEABLE]),
				'isUpcoming'		=> $election['state'] == ElectionState::UPCOMING,
				'isOver'			=> in_array($election['state'], [ElectionState::CLOSEABLE, ElectionState::CLOSED, ElectionState::CANCELED]),
				'canCancel'			=> !in_array($election['state'], [ElectionState::PUBLISHED, ElectionState::CANCELED]),
				'votes'				=> $this->getVotes($election),
				'prelim'			=> $election['canEdit'] && in_array($election['state'], [ElectionState::RUNNING, ElectionState::CLOSEABLE]) ? $this->getVoteStatus($election) : false,
				'saved'				=> intval($this->grab('saved')),
			]
		);
	}
}
