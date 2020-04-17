<?php
	$myDir = dirname(__FILE__);
	require_once("${myDir}/archivedsource.class.php");
	require_once("${myDir}/mirroredurl.class.php");
	require_once("${myDir}/snapshotdatetime.class.php");

define('ALACOVDAT_URL', 'browse');

$defaultParams = [
"date" => null,
"slug" => null,
"mirrored" => null,
"test" => null,
];
	
	if (is_passthru_request()) :
		// check for pass-thru
		$oFile = new MirroredURL(["file" => urldecode(my_request_url('path'))]);
		
		$passthru=$oFile->get_readable();
		if (is_readable($passthru)) :
			$mime = mime_content_type($passthru);
			if ($mime !== false) :
				$filename=basename($passthru);
				if (preg_match('![.](js|css)([?@].*)?$!ix', $filename, $ref)) :
					if (preg_match('!^text/plain!ix', $mime)) :
						$textType = ["js" => "javascript", "css" => "css"];
						$mime = 'text/'.$textType[$ref[1]];
					endif;
				endif;
				
				header("Content-Type: ${mime}");
			endif;
			$size = filesize($passthru);
			if ($size !== false) :
				header("Content-Length: ${size}");
			endif;
			
			$mtime = filemtime($passthru);
			$cached = false;
			if ($mtime !== false) :
				if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) :
					$cachedTime = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
					if (is_numeric($cachedTime)) :
						if ($cachedTime >= $mtime) :
							$cached = true;
							header("HTTP/1.1 304 Not Modified");
						endif;
					endif;
				endif;
				
				header("Last-Modified: ".date("r", $mtime));
			endif;
			
			if (!$cached) :
				if ($mime=='text/html' and !preg_match('/[.]orig$/', $passthru)) :
					$dependencies = preg_match('!/html/snapshots/!ix', $passthru);
					$out = $oFile->get_filtered_html($dependencies);
				else :
					$out = $oFile->get_contents();
				endif;
				print $out;
			endif;
			exit;
		else :
			header("HTTP/1.1 404 Not Found");
			print "<html><head><title>Not Found</title></head><body><h1>Not Found</1><p><code>".$_SERVER['REQUEST_URI']."</code></p></body></html>";
			exit;
		endif;
	elseif (is_browse_request()) :
		$dirs = array_slice(array_filter(explode("/", my_request_url('path'))), 1);
		$key = null;
		foreach ($dirs as $dir) :
			if (is_null($key)) :
				$key = $dir;
			else :
				if (array_key_exists($key, $defaultParams)) :
					$defaultParams[$key] = process_request_path_parameter($key, $dir);
				endif;
				$key = null;
			endif;
		endforeach;
	endif;
	
$params = array_merge($defaultParams, $_REQUEST);

$out = '';
$sourceUrl = null;
$metaTable = [];

function get_json_to_table ($hash, $slug) {
	$data = ['THEAD' => [], 'TBODY' => []];
	
	$props[$slug] = null;
	$props = array_merge($props, [
		"capture" => ["CNTYNAME", "CNTYFIPS", "ADPHDistrict", "CONFIRMED", "DIED", "REPORTED_DEATH"],
		"testsites" => null,
	]);
	
	if (property_exists($hash, "fields")) :
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
	elseif (property_exists($hash, 'type') and "FeatureCollection" == $hash->type) :
	
		if (property_exists($hash, "features")) :
			$props = array_reduce($hash->features, function ($carry, $feat) {
				$props = $carry;
				if (property_exists($feat, "properties")) :
					$props = array_merge($props, array_map(function ($e) { return "properties." . $e; }, array_keys((array) $feat->properties)));
				endif;
				if (property_exists($feat, "geometry")) :
					$props[] = 'geometry';
				endif;
				return array_unique($props);
			}, []);

			$data['THEAD'] = array_map(function ($e) { $al = explode(".", $e, 2); return [$e, end($al)]; }, $props);

			$data['TBODY'] = array_reduce($hash->features, function ($table, $feat) use ($props) {
				foreach ($props as $prop) :
					$obj = $feat;
					$path = explode(".", $prop, 2);
					foreach ($path as $part) :
						$obj = $obj->{$part};
					endforeach;
					
					$row[$prop] = (is_object($obj) ? json_encode($obj) : $obj);
				endforeach;
				$table[] = $row;
				return $table;
			}, []);
		endif;
	
	else :
		
		$data['THEAD'] = ["Property", "Value"];
		foreach ($hash as $prop => $value) :
			$data['TBODY'][] = ["Property" => $prop, "Value" => "<pre>".htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT))."</pre>"];
		endforeach;

	endif;
	
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

function my_request_url ($part = null) {
	$myUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'] . '/' . ltrim($_SERVER['REQUEST_URI'], '/');
	if (is_null($part)) :
		$ret = $myUrl;
	else :
		$myUrl = parse_url($myUrl);
		$ret = (isset($myUrl[$part]) ? $myUrl[$part] : null);
	endif;
	return $ret;
}
function my_script_name () { global $_SERVER; return basename($_SERVER['PHP_SELF']); }
function my_script_path () { global $_SERVER; return "/" . my_script_name(); }
function is_passthru_request () { return (!is_root_request() and !is_browse_request()); }
function is_root_request () { return preg_match("\007^/*(".preg_quote(my_script_name()).")?$\007i", my_request_url('path')); }
function is_browse_request () { return preg_match("\007^/*".preg_quote(ALACOVDAT_URL)."(/.+)?$\007i", my_request_url('path'), $refs); }
function is_mirrored_url_request () { global $params; return !is_null($params['mirrored']); }
function is_data_table_request () { global $params; return in_array($params['slug'], ["capture", "testsites"]) or preg_match('|^/?data[_/].*$|i', $params['slug']); }
function is_html_request (&$refs) { global $params; return preg_match("|^/*(html)([_/](.*))?$|i", $params['slug'], $refs); }
function is_index_request () { global $params; return is_null($params['date']); }
function process_request_path_parameter ($key, $value) {
	if ("slug"==$key) :
		$value = "/" . trim(str_replace(".", "/", $value), "/");
	endif;
	return $value;
}
function unprocess_request_path_parameter ($key, $value) {
	if ("slug"==$key) :
		$value = str_replace("/", ".", trim($value, '/'));
	endif;
	return urlencode($value);
}
function make_browse_link ($params) {
	$a = '<a';
	foreach ($params as $key => $value) :
		if ($key != 'text') :
			$a .= " ".htmlspecialchars($key).'="';
			if (is_string($value)) :
				$a .= htmlspecialchars($value);
			elseif ("href" == $key) :
				$href = '/'.urlencode(ALACOVDAT_URL);
				foreach ($value as $param => $paramValue) :
					$slug = unprocess_request_path_parameter($param, $paramValue);
					$href .= "/${param}/${slug}";
				endforeach;
				$a .= $href;
			endif;
			$a .= '"';
		endif;
	endforeach;
	$a .= '>' . (array_key_exists('text', $params) ? $params['text'] : 'link') . '</a>';
	return $a;
}
function make_browse_selector ($params) {
	$params = array_merge([
	"action" => "",
	"method" => "GET",
	"date" => [],
	"selected" => null,
	], $params);
	
	$action = htmlspecialchars($params['action']);
	$method = htmlspecialchars($params['method']);
	$slug = htmlspecialchars($params['slug']);
	
	$aSelectOptions = [];
	if (is_array($params['date'])) :
		$oDateTime = $params['selected'];
		foreach ($params['date'] as $oDT) :
			$sDt = htmlspecialchars($oDT->datetimecode());
			$sSelected = (($oDT->datetimecode() == $oDateTime->datetimecode()) ? ' selected="selected"' : '');
			$sText = htmlspecialchars($oDT->human_readable());
			$aSelectOptions[] = "<option value=\"${sDt}\"${sSelected}>${sText}</option>";
		endforeach;
		$sSelectOptions = implode("\n", $aSelectOptions);
	endif;
	
	ob_start();
?>
<form action="<?=$action?>" method="<?=$method?>">
<input type="hidden" name="slug" value="<?=$slug?>" />
<select name="date">
<?=$sSelectOptions?>
</select>
<input type="submit" value="see">
</form>
<?php
	$selector = ob_get_clean();
	return $selector;
}

$rawDataOut = null;
$dataTHEAD = null;
$dataTBODY = null;
$metaInput = [
	'file' => null,
];
$tableClass = "data";

$oDateTime = (is_null($params['date']) ? null : new SnapshotDateTime($params['date']));

$refs = [];
if (is_mirrored_url_request()) :
	$slug = $params['slug'];

	$mirrorFile = dirname(__FILE__) . $params['mirrored'];
	$mirrorUrl = 'http://' . $_SERVER['HTTP_HOST'] . $params['mirrored'];
	$oFile = new MirroredURL(["file" => $params['mirrored'], "url" => $mirrorUrl, "ts" => $oDateTime->datetimecode()]);

	if (is_readable($oFile->get_readable())) :
		$mirrorHtml = $oFile->get_filtered_html(/*dependencies=*/ true);
		echo $mirrorHtml;
	else :
		$readable = $oFile->get_readable();
		print "<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>Missing component: <code>${mirrorFile}</code> =&gt; <code>${readable}</code></p></body></html>";
	endif;
	exit;

elseif (is_index_request()) :

	$tableClass = "nav";
	
	$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
	$files = $lists['files'];
	$slugs = array_map(function ($e) { return $e[0]; }, $lists['sets']);
	$timestamps = array_map(function ($e) { return $e[1]; }, $lists['sets']);
	
	$allSlugs = $lists['available slugs'];
	$availableSlugs = array_unique(array_map(function ($e) { return $e[0]; }, $lists['available slugs']));
	
	$slugLinks = array_map(function ($s) use ($allSlugs) {
		$bits = explode("/", trim($s, "/"), 2) + ['', ''];
		return [
		$s,
		$bits[0],
		make_browse_link(["href" => ["slug" => $s], "text" => $bits[1]]),
		get_most_recent_timestamp(get_slug_timestamps($s, $allSlugs)),
	]; }, $availableSlugs);

	$oNow = new SnapshotDateTime(time());
	$metaTable[] = ["Time", $oNow->human_readable(), "now"];

	foreach ($slugLinks as $slugLink) :
		list($slug, $snapType, $link, $ts) = $slugLink;
		$slugpath = explode("/", $slug);
		
		$oLatest = new SnapshotDateTime($ts);
		$latestLink = make_browse_link(["href" => ["date" => $ts, "slug" => $slug], "text" => $oLatest->human_readable()]);
		$latest = "latest: ${latestLink}";
		
		if ($slug==$params['slug']) :
			$metaTable[] = [$snapType, "<strong>".end($slugpath)."</strong>", "<small>${latest}</small>"];
		else :
			$metaTable[] = [$snapType, $link, "<small>${latest}</small>"];
		endif;
	endforeach;

	$outWhat = "Listing";
	$oDateTime = new SnapshotDateTime(time());
	
	$rawDataOut = null;
	$dataTHEAD = ["Type", "Timestamp"];
	foreach ($lists['sets'] as $pair) :
		list($slug, $ts) = $pair;
		
		$oDateTime = new SnapshotDateTime($ts);
		$dataTBODY[] = ["Type" => $slug, "Timestamp" => make_browse_link([
			"href" => ["slug" => $slug, "date" => $ts],
			"text" => $oDateTime->human_readable()
		])];
	endforeach;

elseif (is_html_request($refs)) :

	$slug = $refs[0];
	$ext = $refs[1];
	$site = $refs[3];
	
	$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);
	
	$sourceUrl = $arX->source_url();
	if (!is_null($sourceUrl)) :
		$host = $arX->source_url('host');
		$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$host.'</a>'];
	endif;
	
	$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
	$allSlugs = $lists['available slugs'];
	$allTS = array_map(function ($e) { return new SnapshotDateTime($e); }, get_slug_timestamps($slug, $allSlugs));
	$selector=make_browse_selector(["action" => "/".ALACOVDAT_URL, "slug" => $slug, "date" => $allTS, "selected" => $oDateTime]);
	
	$metaTable[] = ["Timestamp", $selector];

	$oFile = new MirroredURL(["archive" => $arX]);

	$mirrorUrl = $oFile->get_mirror_url();
	
	$snapshotSection = '';
	$viewOptions = ['<a class="tab" href="#html-view-source">view source (html)</a>'];
	if (!is_null($warc_url=$oFile->warc_url())) :
		$viewOptions = array_merge(
			$viewOptions,
			['<a href="'.htmlspecialchars($warc_url).'">WARC</a>']
		);
	endif;
	
	if (!is_null($png_url=$arX->screenshot_url())) :
		$snapshotSection .= '<section id="html-view-screenshot"><div><img src="' . htmlspecialchars($png_url) . '" /></div></section>';
		$viewOptions = array_merge(
			['<a class="tab" href="#html-view-screenshot">screen shot (png)</a>'],
			$viewOptions
		);
	endif;
	
	if (is_readable($oFile->get_readable())) :
		$snapshotSection .= '<section id="html-view-snapshot"><iframe src="' . htmlspecialchars($mirrorUrl) . '" width="95%" height="800">';
		$snapshotSection .= "</iframe></section>";
		$viewOptions = array_merge(
			['<a class="tab" href="#html-view-snapshot">webpage snapshot</a>'],
			$viewOptions
		);
	endif;
	
	$metaTable[] = ["View", implode(" / ", $viewOptions)];
	
	header("Content-type: text/html");

	$html = $arX->payload_contents();
	$rawDataOut = null;
	$out = '';
	if (preg_match('|<title>([^<]*)</title>|ix', $html, $ref)) :
		$out .= "<h2>" . $ref[1] . "</h2>";
	else :
		$out .= "<h2>" . $host . "</h2>";
	endif;
	$out .= "<section id='html-view-source'>";
	if (!is_null($warc=$arX->payload_warc_url())) :
		$out .= '[<a href="' . htmlspecialchars($warc) . '">download WARC archive</a>]';
	endif;
	$out .= "<code><pre>".htmlspecialchars($html)."</pre></code></section>\n";
	$out .= $snapshotSection;
	
	$outWhat = "HTML Front Page";
	
elseif (is_data_table_request()) :

	$slug = $params['slug'];
	$ext = 'json';
	
	$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);

	$metaInput['file'] = $arX->payload_file();
	$outWhat = "Data Set";
	
	$json = $arX->payload_contents();

	$hash = json_decode($json);
	if (is_null($hash)) :
		header("Content-type: text/plain");
		echo $json;
	else :
		header("Content-type: text/html");
		
		// URL of snapshot: Get it from the file, if available
		$sourceUrl = $arX->source_url();
		
		if (!is_null($sourceUrl)) :
			$source = parse_url($sourceUrl);
			$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'];
		endif;
		if (!is_null($oDateTime)) :
			$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
			$allSlugs = $lists['available slugs'];
			$allTS = array_map(function ($e) { return new SnapshotDateTime($e); }, get_slug_timestamps($slug, $allSlugs));
			$selector=make_browse_selector(["action" => "/".ALACOVDAT_URL, "slug" => $slug, "date" => $allTS, "selected" => $oDateTime]);
			
			$metaTable[] = ["Timestamp", $selector];
		endif;
		$viewOptions = ['<a href="#view-json-source" class="tab">json source</a>'];
		
		$dataTable = get_json_to_table($hash, $params['slug']);
		$dataTHEAD = $dataTable['THEAD'];
		$dataTBODY = $dataTable['TBODY'];		

		if (count($dataTHEAD) + count($dataTBODY) > 0) :
			$viewOptions = array_merge(
			['<a href="#view-data-table" class="tab">data table</a>'],
			$viewOptions);
		endif;
		
		$metaTable[] = ["View",  implode(" / ", $viewOptions)];

		$rawDataOut = $hash;
	endif;
	
endif;


if (strlen($out) == 0 and is_null($dataTHEAD)) exit;
	$sTimestamp = (is_null($oDateTime) ? null : $oDateTime->human_readable());
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

#html-view-screenshot div {
	margin: 10px; border: 1px dotted black;
}
#html-view-screenshot img {
	max-width: 100%; height: auto;
}

.tab.current {
	font-weight: bold;
	color: #000;
	text-decoration: none;
}

table.nav {
	float: left;
	margin-right: 20px;
}

section {
	clear: both;
}

</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript">
//<![CDATA[
function isTabbedInterface () {
	return ($('.tab').length > 0);
}

function isHTMLSnapshot () {
	return ($('#html-view-source').length > 0);
}
function activateTabFromLink (e) {
	e.preventDefault();
	
	var htmlId = e.target.hash;
	$('.tab').removeClass('current');
	$(e.target).addClass('current');
	$('section').fadeOut( { duration: 250 } ).promise().then( function () { $(htmlId).fadeIn( { duration: 250 } ); } );
}
function setupSnapshotTabLinks () {
	$('a.tab').click( activateTabFromLink );
}
function hideSnapshotTabs () {
	$('section').hide().promise().then( function () {
		var tab = $('.tab').eq(0).attr('href');
		$('.tab').eq(0).addClass('current');
		$(tab).show();
	});
}

$(document).ready( function () {
	if (isTabbedInterface()) {
		setupSnapshotTabLinks();
		hideSnapshotTabs();
	} /* if */
});
//]>
</script>
</head>
<body>
<h1><a href="/">Documenting Covid-19: Alabama's Responses</a></h1>
<h2><?=$outWhat?> Snapshot: <?=$sTimestamp?></h1>
<?php
	if (count($metaTable) > 0) :
?>
	<table border="1" id="meta-table" class="<?=$tableClass; ?>">
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
		if ("nav" != $tableClass) :
?>
	<section id="view-data-table">
<?php
		endif;
?>
	<table border="1" class="<?=$tableClass?>">
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
		if ("nav" != $tableClass) :
?>
	</section>	
<?php
		endif;
	endif;
	
	print $out;
	
	if (!is_null($rawDataOut)) :
?>

<section id="view-json-source">
<?php

		print "<h2>JSON Source";
		if (!is_null($metaInput['file'])) :
			print " (<code>".htmlspecialchars(basename($metaInput['file']))."</code>) ";
		endif;
		print ":</h2>\n";
		echo "<pre><code>"; echo json_encode($rawDataOut, JSON_PRETTY_PRINT); echo "</code></pre>";
?>
</section>
<?php
	endif;
?>
</body>
</html>

