<?php
class Resource extends Context {
	public function beforeRoute($f3) {
		parent::beforeRoute($f3);
		header('Content-Type: application/json');
	}

	public function getGroupMemberNames($f3, $params) {
		$query = 'SELECT username FROM {wcf}user JOIN {wcf}user_to_group USING (userID) WHERE groupID = ?';
		echo json_encode(
			array_map(
				function ($user) {
					return $user['username'];
				},
				$this->forum->doQuery($query, (int)$params['id'])
			)
		);
	}
}
