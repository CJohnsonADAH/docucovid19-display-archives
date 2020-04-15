<?php
define('ALACOVDAT_TZ', 'America/Chicago');
	
class SnapshotDateTime {
		
	static public function alacovdat_datestamp_regex () {
		return "/^
			([0-9]{4})
			([0-9]{2})
			([0-9]{2})
			([0-9]{2})
			([0-9]{2})
			([0-9]{2})
			Z
		$/ix";
	} /* alacovdat_datestamp_regex () */

	static public function is_alacovdat_datestamp ($ts) {
		return preg_match(self::alacovdat_datestamp_regex(), trim($ts));
	} /* is_alacovdat_datestamp () */

	static public function human_datetime ($ts, $fmt = 'M d, Y H:i') {
		$vTs = $ts;
		if (is_string($ts) and self::is_alacovdat_datestamp(trim($ts))) :
			$vTs = self::get_the_timestamp($ts);
		endif;
		date_default_timezone_set(ALACOVDAT_TZ);
		return date($fmt, $vTs);
	} /* human_datetime() */

	static public function get_the_timestamp($DATESTAMP) {
		// Timestamp of snapshot: Parse the slug into its component parts
		// and convert into a Unix-epoch timestamp
		$got_the_time = preg_match(self::alacovdat_datestamp_regex(), $DATESTAMP, $ts_matches);
		if ($got_the_time) :
			$timestamp = gmmktime($ts_matches[4], $ts_matches[5], $ts_matches[6], $ts_matches[2], $ts_matches[3], $ts_matches[1]);
		else :
			$timestamp = null;
		endif;
	
		return $timestamp;
	} /* get_the_timestamp () */

} /* class SnapshotDateTime */
