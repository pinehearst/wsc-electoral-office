<?php
// FIXME: Expand! :)

class ElectionState {
	const CANCELED	= 0;
	const INPREP	= 1;
	const READY		= 2;
	const UPCOMING	= 3;
	const RUNNING	= 4;
	const CLOSEABLE	= 5;
	const CLOSED	= 6;
	const PUBLISHED	= 7;

	public static function getName($state) {
		switch($state) {
			case self::CANCELED:
				return 'Abgebrochen';
			case self::INPREP:
				return 'In Bearbeitung';
			case self::READY:
				return 'Bereit zum Starten';
			case self::UPCOMING:
				return 'Started bald';
			case self::RUNNING:
				return 'Läuft';
			case self::CLOSED:
				return 'Beendet';
			case self::PUBLISHED:
				return 'Veröffentlicht';
			case self::CLOSEABLE:
				return 'Kann beendet werden';
			default:
				return 'Unbekannter Status';
		}
	}
}
