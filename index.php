<?php

$params = array_merge([
"date" => null,
"slug" => "capture",
], $_REQUEST);

$out = '';
$timestamp = null;
$sourceUrl = null;
$metaTable = [];

function get_the_timestamp($DATESTAMP) {
	// Timestamp of snapshot: Parse the slug into its component parts
	// and convert into a Unix-epoch timestamp
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
	else :
		$timestamp = null;
	endif;
	
	return $timestamp;
} /* get_the_timestamp () */

$rawDataOut = null;
$dataTHEAD = null;
$dataTBODY = null;

if (is_null($params['date'])) :
	$files = glob(dirname(__FILE__) . "/covid-data/*.json");
	$basenames = array_map(function ($filename) { return basename($filename); }, $files);
	$slugs = array_map(function ($f) { return preg_replace("|^([^-]+)(-([0-9]+Z))?[.]json$|i", "$1", $f); }, $basenames);
	$timestamps = array_map(function ($f) { return preg_replace("|^([^-]+)(-([0-9]+Z))?[.]json$|i", "$1;$3", $f); }, $basenames);
	
	$rawDataOut = $files;
	$dataTHEAD = ["Type", "Timestamp"];
	foreach ($timestamps as $ts_slug) :
		list($slug, $ts) = explode(";", $ts_slug, 2);
		date_default_timezone_set('America/Chicago');
		$dataTBODY[] = ["Type" => $slug, "Timestamp" => '<a href="?date='.$ts.'&slug='.$slug.'">'.date('r', get_the_timestamp($ts)).'</a>'];
	endforeach;

elseif ("testsites" == $params['slug']) :
	$slug = $params['slug'];
	$DATESTAMP = $params['date'];
	$DATA_PREFIX = "/covid-data/${slug}-";
	$JSON_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.json";
	$URL_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.url.txt";
	
	$json = file_get_contents($JSON_FILE);
	$hash = json_decode($json);
	if (is_null($hash)) :
		header("Content-type: text/plain");
		echo $json;
	else :
		header("Content-type: text/html");

		$timestamp = get_the_timestamp($DATESTAMP);

		// URL of snapshot: Get it from the file, if available
		if (is_readable($URL_FILE)) :
			$sourceUrl = file_get_contents($URL_FILE);
		endif;
		
		if (!is_null($sourceUrl)) :
			$source = parse_url($sourceUrl);
			$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'];
		endif;
		if (!is_null($timestamp)) :
			date_default_timezone_set('America/Chicago');
			$metaTable[] = ["Timestamp", date("m/d/Y H:i:s", $timestamp)];
		endif;

		$dataTHEAD = [];
		$dataTBODY = [];
		
		foreach ($hash->fields as $field) :
			$dataTHEAD[] = $field->name;
		endforeach;
		foreach ($hash->features as $feat) :
			$tr = [];
			foreach ($feat->attributes as $key => $value) :
				$tr[$key] = $value;
			endforeach;
			$dataTBODY[] = $tr;
		endforeach;
		
		$rawDataOut = $hash;
		
	endif;
elseif ("capture" == $params['slug']) :
	$DATESTAMP = $params['date'];
	$DATA_PREFIX = "/covid-data/capture-";
	$JSON_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.json";
	$URL_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.url.txt";
	
	$json = file_get_contents($JSON_FILE);
	$hash = json_decode($json);
	if (is_null($hash)) :
		header("Content-type: text/plain");
		echo $json;
	else :
		header("Content-type: text/html");

		$timestamp = get_the_timestamp($DATESTAMP);
		
		// URL of snapshot: Get it from the file, if available
		if (is_readable($URL_FILE)) :
			$sourceUrl = file_get_contents($URL_FILE);
		endif;
		
		if (!is_null($sourceUrl)) :
			$source = parse_url($sourceUrl);
			$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'];
		endif;
		if (!is_null($timestamp)) :
			date_default_timezone_set('America/Chicago');
			$metaTable[] = ["Timestamp", date("m/d/Y H:i:s", $timestamp)];
		endif;
		
		$dataTHEAD = [];
		$dataTBODY = [];
		
		$props = ["CNTYNAME", "CNTYFIPS", "ADPHDistrict", "CONFIRMED", "DIED"];
		foreach ($hash->fields as $field) :
			if (in_array($field->name, $props)) :
				$dataTHEAD[] = [$field->name, $field->alias];
			endif;
		endforeach;

		foreach ($hash->features as $feat) :
			$tr = [];
			foreach ($feat->attributes as $key => $value) :
				if (in_array($key, $props)) :
					$tr[$key] = $value;
				endif;
			endforeach;		
			$dataTBODY[] = $tr;
		endforeach;

		$rawDataOut = $hash;
	endif;
	
endif;

date_default_timezone_set('America/Chicago');

if (strlen($out) == 0 and is_null($dataTHEAD)) exit;
?>
<!DOCTYPE html>
<html>
<head>
</head>
<body>
<?php
	print "<h1>Alabama Covid-19 Data Snapshot: " . date('m/d/y H:i:s', $timestamp) . "</h1>\n";

	if (count($metaTable) > 0) :
?>
	<table border="1">
	<tbody>
<?php
		foreach ($metaTable as $row) :
			print "<tr>";
			$i = 0;
			foreach ($row as $col) :
				print ($i>0) ? "<td>" : "<th>";
				print $col;
				print ($i>0) ? "</td>" : "</th>";
				$i++;
			endforeach;
			print "</tr>\n";
		endforeach;
?>
	</tbody>
	</table>
	
<?php
	endif;

	if (count($dataTHEAD) > 0) :
?>
	<table border="1">
	<thead>
	<tr>
<?php
		foreach ($dataTHEAD as $th) :
			if (is_array($th)) :
				$label = $th[1];
			else :
				$label = $th;
			endif;
			
			print '<th scope="col">' . $label . "</th>";
		endforeach;
?>
	</tr>
	</thead>
	
	<tbody>
<?php
		foreach ($dataTBODY as $tr) :
			print "<tr>";
			foreach ($dataTHEAD as $th) :
				if (is_array($th)) :
					$name = $th[0];
				else :
					$name = $th;
				endif;

				$td = $tr[$name];
				print "<td>${td}</td>";
			endforeach;
			print "</tr>\n";
		endforeach;
?>
	</tbody>
	</table>
	
<?php
	endif;
	
	print $out;
?>
<hr/>
<?php
		print "<h2>JSON Source:</h2>\n";
		echo "<pre>"; var_dump($rawDataOut); echo "</pre>";
?>
</body>
</html>

