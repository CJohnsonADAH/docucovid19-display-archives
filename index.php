<?php

$params = array_merge([
"date" => null,
"slug" => null,
"mirrored" => null,
"test" => null,
], $_REQUEST);

$out = '';
$timestamp = null;
$sourceUrl = null;
$metaTable = [];

function get_json_to_table ($hash, $slug) {
	$data = ['THEAD' => [], 'TBODY' => []];
	
	$props[$slug] = null;
	$props = array_merge($props, [
		"capture" => ["CNTYNAME", "CNTYFIPS", "ADPHDistrict", "CONFIRMED", "DIED", "REPORTED_DEATH"],
		"testsites" => null,
	]);
	
	foreach ($hash->fields as $field) :
		if (is_null($props[$slug]) or in_array($field->name, $props[$slug])) :
			$data['THEAD'][] = [$field->name, $field->alias];
		endif;
	endforeach;

	foreach ($hash->features as $feat) :
		$tr = [];
		foreach ($feat->attributes as $key => $value) :
			if (is_null($props[$slug]) or in_array($key, $props[$slug])) :
				$tr[$key] = $value;
			endif;
		endforeach;		
		$data['TBODY'][] = $tr;
	endforeach;

	return $data;

} /* get_json_to_table () */

function get_most_recent_timestamp ($timestamps) {
	rsort($timestamps);
	return (count($timestamps)>0 ? $timestamps[0] : null);
}

function get_slug_timestamps ($what, $where) {
	$aWhere = array_reduce($where, function ($a, $e) use ($what) {
		if ($what == $e[0]) :
			$a[] = $e[1];
		endif;
		return $a;
	}, []);
	
	return $aWhere;
} /* get_most_recent () */

function get_snapshot_lists ($dir) {
	global $params;

	$files = array_merge(
		glob("${dir}/*.url.txt"),
		glob("${dir}/data/*.url.txt"),
		glob("${dir}/html/*.url.txt")
	);
	
	$pairs = []; $allSlugs = [];
	foreach ($files as $file) :
		$basedir = preg_replace("|^".preg_quote($dir)."|i", "", dirname($file));
		$base = basename($file);
		if (preg_match("|^([^-]+)(-([0-9]+Z))?[.]url[.]txt$|i", $base, $m)) :
			$set = [(strlen($basedir) > 0 ? "${basedir}/" : "") . $m[1], $m[3], $basedir];
			$allSlugs[] = $set;
			if (is_null($params['slug']) or ($set[0]==$params['slug'])) :
				$pairs[] = $set;
			endif;
		endif;
	endforeach;

	if ($params['test'] == 'files') :
		header("Content-type: text/plain");
		echo "--- files ---\n";
		var_dump($files);
		echo "--- pairs ---\n";
		var_dump($pairs);
		exit;
	endif;
	

	return [
	"files" => $files,
	"sets" => $pairs,
	"available slugs" => $allSlugs,
	];
	
} /* get_snapshot_lists () */

function getJsonUrl ($slug) {
	global $params;
	
	$DATESTAMP = $params['date'];
	
	if (strlen($slug) > 0) :
		$capturePrefix = "data/${slug}";
	else :
		$capturePrefix = "capture";
	endif;
	
	$captureUrl = "/covid-data/${capturePrefix}-${DATESTAMP}.json";
	return $captureUrl;

} /* getJsonUrl () */	


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
$metaInput = [
	'file' => null,
];

if (!is_null($params['mirrored'])) :
	$slug = $params['slug'];
	$DATESTAMP = $params['date'];

	$mirrorFile = dirname(__FILE__) . $params['mirrored'];
	$mirrorUrl = 'http://' . $_SERVER['HTTP_HOST'] . $params['mirrored'];

	if (!is_readable($mirrorFile)) :
		// check whether we've got a screwy timestamp
		$mirrorDir = dirname($mirrorFile);
		while (strlen($mirrorDir) > 1) :
			if (preg_match("|^(.*/html/snapshots/[a-z]+/)([0-9]+Z)$|", $mirrorDir, $ref)) :
				$mirrorDir = $ref[1];
				$mirrorBase = $ref[2];
				break;
			endif;
			
			$mirrorBase = basename($mirrorDir);
			$mirrorDir = dirname($mirrorDir);
			
		endwhile;

		$ts = array_map(function ($e) { return basename($e); }, glob($mirrorDir . "/[0-9]*Z"));
		sort($ts);
		$ts = array_filter($ts, function ($e) use ($mirrorBase) { return ($e >= $mirrorBase); }); 

		if (count($ts) >= 1) :
			$ts = array_values($ts);
			$testFile = str_replace($mirrorBase, $ts[0], $mirrorFile);
			if (is_readable($testFile)) :
				$mirrorFile = $testFile;
			endif;
		endif;
	endif;
	
	if (is_dir($mirrorFile) and is_readable("${mirrorFile}/index.html")) :
		$mirrorFile = "${mirrorFile}/index.html";
	endif;

	$ext = "html";
	if (!is_readable($mirrorFile) and is_readable("{$mirrorFile}.${ext}")) :
		$mirrorFile = "${mirrorFile}.${ext}";
	endif;
	
	if (is_readable($mirrorFile)) :
		$timestamp = get_the_timestamp($DATESTAMP);
			
		$mirrorHtml = file_get_contents($mirrorFile);

		$mirrorHtml = str_replace(
			"<head>",
			'<head><base href="' . $mirrorUrl . '" />',
			$mirrorHtml
		);
		
		$dataMirrorUrls = [
		'https://services7.arcgis.com/4RQmZZ0yaZkGR1zy/arcgis/rest/services/COV19_Public_Dashboard_ReadOnly/FeatureServer/0/query?where=1%3D1&outFields=CNTYNAME%2CCNTYFIPS%2CCONFIRMED%2CDIED&returnGeometry=false&f=pjson' => getJsonUrl('confirmeddied'),
		'https://services7.arcgis.com/4RQmZZ0yaZkGR1zy/arcgis/rest/services/COV19_Public_Dashboard_ReadOnly/FeatureServer/0/query?where=1%3D1&outFields=CNTYNAME%2CCNTYFIPS%2CCONFIRMED%2CDIED%2Creported_death&returnGeometry=false&f=pjson' => getJsonUrl('confirmeddiedreported'),
		];
		
		foreach ($dataMirrorUrls as $from => $to) :
			
			$mirrorHtml = str_replace(
				$from, $to,
				$mirrorHtml
			);
		
		endforeach;
		
		echo $mirrorHtml;
	else :
		print "<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>Missing component: <code>${mirrorFile}</code></p></body></html>";
	endif;
	exit;

elseif (is_null($params['date'])) :

	$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
	$files = $lists['files'];
	$slugs = array_map(function ($e) { return $e[0]; }, $lists['sets']);
	$timestamps = array_map(function ($e) { return $e[1]; }, $lists['sets']);
	
	$allSlugs = $lists['available slugs'];
	$availableSlugs = array_unique(array_map(function ($e) { return $e[0]; }, $lists['available slugs']));
	
	$slugLinks = array_map(function ($s) use ($allSlugs) {
		$bits = explode("/", trim($s, "/"), 2);
		return [
		$s,
		$bits[0],
		'<a href="?slug='.urlencode($s).'">'.htmlspecialchars($bits[1]).'</a>',
		get_most_recent_timestamp(get_slug_timestamps($s, $allSlugs)),
	]; }, $availableSlugs);

	foreach ($slugLinks as $slugLink) :
		list($slug, $snapType, $link, $ts) = $slugLink;

		date_default_timezone_set('America/Chicago');
		$latest = "latest: ".date('M d Y H:i', get_the_timestamp($ts));
		if ($slug==$params['slug']) :
			$metaTable[] = [$snapType, "<strong>".$slug."</strong>*", $latest];
		else :
			$metaTable[] = [$snapType, $link, $latest];
		endif;
	endforeach;

	date_default_timezone_set('America/Chicago');
	$metaTable[] = ["Time", date('r'), "now"];

	$outWhat = "Listing";
	$timestamp = time();
	
	$rawDataOut = null;
	$dataTHEAD = ["Type", "Timestamp"];
	foreach ($lists['sets'] as $pair) :
		list($slug, $ts) = $pair;
		date_default_timezone_set('America/Chicago');
		$dataTBODY[] = ["Type" => $slug, "Timestamp" => '<a href="?date='.$ts.'&slug='.$slug.'">'.date('r', get_the_timestamp($ts)).'</a>'];
	endforeach;

	
elseif (preg_match("|^/*(html)([_/](.*))?$|i", $params['slug'], $refs)) :

	$slug = $refs[0];
	$ext = $refs[1];
	$site = $refs[3];
	
	$DATESTAMP = $params['date'];
	$DATA_PREFIX = "/covid-data/${slug}-";
	$JSON_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.${ext}";
	$URL_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.url.txt";
	$SNAP_PREFIX = "/covid-data/${ext}/snapshots/${site}/";
	
		$timestamp = get_the_timestamp($DATESTAMP);

	// URL of snapshot: Get it from the file, if available
	$sourceParts = [];
	if (is_readable($URL_FILE)) :
		$sourceUrl = trim(file_get_contents($URL_FILE));
		$sourceParts = parse_url($sourceUrl);
	endif;

	if (!is_null($sourceUrl)) :
		$source = parse_url($sourceUrl);
		$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'];
	endif;
	if (!is_null($timestamp)) :
		date_default_timezone_set('America/Chicago');
		$metaTable[] = ["Timestamp", date("m/d/Y H:i:s", $timestamp)];
	endif;
	$metaTable[] = ["View", '<a class="tab" href="#html-view-snapshot">webpage snapshot</a> <a class="tab" href="#html-view-source">view source (html)</a> '];
	$sourceParts = array_merge([
	"scheme" => "file",
	"host" => "localhost",
	"path" => "",
	"query" => "",
	"fragment" => "",
	], $sourceParts);

	$mirrorUrl = $SNAP_PREFIX . $DATESTAMP . '/' . $sourceParts['host'] . "/" . $sourceParts['path'] . (strlen($sourceParts['query']) > 0 ? '?' . $sourceParts['query'] : "");
	$mirrorUrl = "/?date=${DATESTAMP}&mirrored=".urlencode($mirrorUrl);
	
	header("Content-type: text/html");

	$timestamp = get_the_timestamp($DATESTAMP);

	$html = file_get_contents($JSON_FILE);
	$rawDataOut = null;
	$out = "<section id='html-view-source'><code><pre>".htmlspecialchars($html)."</pre></code></section>\n";
	
	$out .= '<section id="html-view-snapshot"><iframe src="' . htmlspecialchars($mirrorUrl) . '" width="95%" height="800">';
	$out .= "</iframe></section>";
	$outWhat = "HTML Front Page";
	
elseif (in_array($params['slug'], ["capture", "testsites"]) or preg_match('|^/?data[_/].*$|i', $params['slug'])) :

	$DATESTAMP = $params['date'];
	$DATA_PREFIX = "/covid-data/".$params['slug']."-";
	$JSON_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.json";
	$URL_FILE = dirname(__FILE__) . "${DATA_PREFIX}${DATESTAMP}.url.txt";

	$metaInput['file'] = $JSON_FILE;
	$outWhat = "Data Snapshot";
	
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
			$sourceUrl = trim(file_get_contents($URL_FILE));
		endif;
		
		if (!is_null($sourceUrl)) :
			$source = parse_url($sourceUrl);
			$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'];
		endif;
		if (!is_null($timestamp)) :
			date_default_timezone_set('America/Chicago');
			$metaTable[] = ["Timestamp", date("m/d/Y H:i:s", $timestamp)];
		endif;
		
		$dataTable = get_json_to_table($hash, $params['slug']);
		$dataTHEAD = $dataTable['THEAD'];
		$dataTBODY = $dataTable['TBODY'];		

		$rawDataOut = $hash;
	endif;
	
endif;


if (strlen($out) == 0 and is_null($dataTHEAD)) exit;
	date_default_timezone_set('America/Chicago');
	$sTimestamp = date('m/d/y H:i:s', $timestamp);
?>
<!DOCTYPE html>
<html>
<head>
<title>Documenting Covid-19: Alabama's Responses</title>
<style type="text/css">
#html-view-source pre {
	padding: 1.0em;
	background-color: #eee;
	color: #000;
	width: 96%;
	overflow: auto;
}

#meta-table {
	margin-bottom: 1.0em;
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript">
//<![CDATA[
function isHTMLSnapshot () {
	return ($('#html-view-source').length > 0);
}
function activateTabFromLink (e) {
	e.preventDefault();
	
	var htmlId = e.target.hash;

	$('section').fadeOut( { duration: 250 } ).promise().then( function () { $(htmlId).fadeIn( { duration: 250 } ); } );
}
function setupSnapshotTabLinks () {
	$('a[href="#html-view-source"]').click( activateTabFromLink );
	$('a[href="#html-view-snapshot"]').click( activateTabFromLink );
}
function hideSnapshotTabs () {
	$('section').hide().promise().then( function () { $('#html-view-snapshot').show(); } );
}

$(document).ready( function () {
	if (isHTMLSnapshot()) {
		setupSnapshotTabLinks();
		hideSnapshotTabs();
	} /* if */
});
//]>
</script>
</head>
<body>
<h1><a href="/">Documenting Covid-19: Alabama's Responses</a></h1>
<h2><?=$outWhat?> Snapshot: <?=$sTimestamp?></h1>;
<?php
	if (count($metaTable) > 0) :
?>
	<table border="1" id="meta-table">
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
	
	if (!is_null($rawDataOut)) :
?>
<hr/>
<section id="json-source">
<?php

		print "<h2>JSON Source";
		if (!is_null($metaInput['file'])) :
			print " (<code>".htmlspecialchars(basename($metaInput['file']))."</code>) ";
		endif;
		print ":</h2>\n";
		echo "<pre>"; var_dump($rawDataOut); echo "</pre>";
?>
</section>
<?php
	endif;
?>
</body>
</html>

