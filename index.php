<?php

$params = array_merge([
"date" => null,
], $_REQUEST);

$out = '';
$timestamp = null;

if (is_null($params['date'])) :
	$files = glob(dirname(__FILE__) . "/covid-data/*.json");
	$basenames = array_map(function ($filename) { return basename($filename); }, $files);
	$timestamps = array_map(function ($f) { return preg_replace("|^([^-]+)(-([0-9]+Z))?[.]json$|i", "$3", $f); }, $basenames);
	print "<ul>";
	foreach ($timestamps as $ts) :
		print "<li><a href='?date=${ts}'>${ts}</a></li>\n";
	endforeach;
	print "</ul>";
	var_dump($timestamps);
else :
	$DATESTAMP = $params['date'];
	$JSON_FILE = dirname(__FILE__) . "/covid-data/capture-${DATESTAMP}.json";

	$json = file_get_contents($JSON_FILE);
	$hash = json_decode($json);
	if (is_null($hash)) :
		header("Content-type: text/plain");
		echo $json;
	else :
		header("Content-type: text/html");

		$got_the_time = preg_match('/^
			([0-9]{4})
			([0-9]{2})
			([0-9]{2})
			([0-9]{2})
			([0-9]{2})
			([0-9]+)
			Z$
		/ix', $DATESTAMP, $ts_matches);
		if ($got_the_time) :
			$timestamp = mktime($ts_matches[4], $ts_matches[5], $ts_matches[6], $ts_matches[2], $ts_matches[3], $ts_matches[1]);
		endif;

		ob_start();
		print "<table border='1'>\n";
		print "<thead>\n";
		print "<tr>\n";
		
		$props = ["CNTYNAME", "CNTYFIPS", "ADPHDistrict", "CONFIRMED", "DIED"];
		foreach ($hash->fields as $field) :
			if (in_array($field->name, $props)) :
				$alias = $field->alias;
				print "<th scope='col'>${alias}</th>\n";
			endif;
		endforeach;
		print "</tr>\n";
		print "</thead>\n";
		print "<tbody>\n";
		foreach ($hash->features as $feat) :
			print "<tr>\n";
			foreach ($feat->attributes as $key => $value) :
				if (in_array($key, $props)) :
					print "<td>${value}</td>";
				endif;
			endforeach;		
			print "</tr>\n";
		endforeach;
		print "</tbody>\n";
		print "</table>\n";
		$out = ob_get_clean();
		
	endif;
	
endif;

date_default_timezone_set('America/Chicago');

if (strlen($out) == 0) exit;
?>
<!DOCTYPE html>
<html>
<head>
</head>
<body>
<?php
	print "<h1>" . date('r', $timestamp) . "</h1>\n";
	print $out;
?>
<hr/>
<?php
		print "<h2>JSON Source:</h2>\n";
		echo "<pre>"; var_dump($hash); echo "</pre>";
?>
</body>
</html>

