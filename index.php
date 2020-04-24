<?php
	$myDir = dirname(__FILE__);
	require_once("${myDir}/archivedsource.class.php");
	require_once("${myDir}/mirroredurl.class.php");
	require_once("${myDir}/snapshotdatetime.class.php");

define('ALACOVDAT_DATA_DIR', "${myDir}/covid-data");
define('ALACOVDAT_URL', 'browse');
define('ALACOVDAT_REQUEST_URL', '(archive)');
define('ALACOVDAT_SOURCES_TSV', "${myDir}/sources.tsv.txt");

if (!is_readable(ALACOVDAT_DATA_DIR."/data")) :
	header("HTTP/1.1 500 Internal Server Error");
?>
<!DOCTYPE html>
<html>
<head>
<title>Documenting Covid-19 is off-line for a minute.</title>
</head>
<body>
<h1>500 Internal Server Error</h1>
<p>The site is off-line for a minute due to temporary maintenance or a technical issue.
BRB, hopefully..</p>
</body>
</html>
<?php
	exit;
endif;

$defaultParams = [
"tag" => null,
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
					if (preg_match('!^text/!ix', $mime)) :
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
	elseif (is_archive_request()) :
		$urls = explode("/", trim(my_request_url('path'), '/'), 2);
		$url = urldecode($urls[1]);
		$url = urldecode($url);
		
		$services = [
			"archive.today" => sprintf('https://archive.today?run=1&url=%s', urlencode($url)),
			"archive.org" => sprintf('https://web.archive.org/save/%s', $url)
		];
		
		foreach ($services as $shortname => $service) :
			$iframeSrc = htmlspecialchars($service);
?>
<h1><?=$shortname?></h1>
<iframe src="<?=$iframeSrc?>">
</iframe>
<?php
		endforeach;
		exit;
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

function get_archive_request_url ($part = null) {
	return my_request_url($part, 'archive');
}

function get_archive_service_link ($serviceUrl, $url = null, $text = null) {
	$html = '';
	$imgSrc = "/assets/images/icon_savePage.png";
	$imgAlt = 'add to';
	$aHref = $serviceUrl;
	
	$serviceParts = parse_url($serviceUrl);
	$url = (is_null($url) ? '' : urlencode($url));
	
	$aHrefPattern="%s#%s";
	if ($serviceUrl == 'https://archive.today/') :
		if (is_null($text)) :
			$text = $serviceParts['host'];
		endif;
	elseif ($serviceUrl == 'https://archive.org/') :
		if (is_null($text)) :
			$text = 'Internet Archive';
		endif;
	elseif ($serviceUrl == get_archive_request_url()) :
		if (is_null($text)) :
			$text = 'all';
		endif;
		$aHrefPattern="%s/%s";
		$url = urlencode($url); // double-encode to end-around apache
	else :
		if (is_null($text)) :
			$text = $serviceParts['host'];
		endif;
	endif;
	
	$aHref = htmlspecialchars(sprintf($aHrefPattern, $serviceUrl, $url));
	$aText = htmlspecialchars($text);
	$imgSrc = htmlspecialchars($imgSrc);
	$imgAlt = htmlspecialchars($imgAlt);
	$html = "<a class='archive-service' style='font-size: 10px; vertical-align: bottom;' href=\"${aHref}\"><img style='vertical-align: bottom; height: 16px; width: auto;' src='${imgSrc}' alt='${imgAlt}' /> ${aText}</a>";
	return $html;
}

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

	$subDirs = [ "${dir}/data", "${dir}/html" ];
	$urlDirectories = $subDirs;
	foreach ($urlDirectories as $subDir) :
		$subSubDirs = glob("${subDir}/202[0-9]*Z", GLOB_ONLYDIR);
		$urlDirectories = array_merge($urlDirectories, $subSubDirs);
	endforeach;

	$files = [];
	foreach ($urlDirectories as $urlDir) :
		$files = array_merge($files, glob("${urlDir}/*.url.txt"));
	endforeach;
	
	$pairs = []; $allSlugs = [];
	foreach ($files as $file) :
		$basedir = preg_replace("|^".preg_quote($dir)."|i", "", dirname($file));
		
		$base = basename($file);
		if (preg_match("|^([^-]+)(-([0-9]+Z))?[.]url[.]txt$|i", $base, $m)) :
			$slug = $m[1];
			$ts = $m[3];
			if (basename($basedir)==$ts) :
				$basedir = dirname($basedir);
			endif;
			
			$set = [(strlen($basedir) > 0 ? "${basedir}/" : "") . $slug, $ts, $basedir];
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

$gaGetSlugTagsTable = null;
function is_hash_tag ($s) {
	return !!preg_match('/^[#]/', $s);
}
function get_slug_tags ($slug = null) {
	global $gaGetSlugTagsTable;
	
	if (is_null($slug)) :
	
		$lines = [];
		if (is_readable(ALACOVDAT_SOURCES_TSV)) :
			$lines = array_map('rtrim', file(ALACOVDAT_SOURCES_TSV));
		endif;
		
		$ret = array_reduce($lines, function ($aSlugTags, $line) {
			
			$fields = preg_split("/\t/", $line);
			if (count($fields) >= 3) :
				$slug = $fields[2];
				$hash_tags = array_filter(array_slice($fields, 3), 'is_hash_tag');
				$tags = array_map(function ($e) { return preg_replace('/^[#]/', '', $e); }, $hash_tags);
				
				if (!array_key_exists($slug, $aSlugTags)) :
					$aSlugTags[$slug] = [];
				endif;
				$aSlugTags[$slug] = array_merge($aSlugTags[$slug], $tags);
			endif;
			return $aSlugTags;
		}, []);
		
	else :
		if (is_null($gaGetSlugTagsTable)) :
			$gaGetSlugTagsTable = get_slug_tags();
		endif;

		$ret = (array_key_exists($slug, $gaGetSlugTagsTable) ? $gaGetSlugTagsTable[$slug] : []);
	endif;
	
	return $ret;
}

function do_output_data_table ($table, $htmlClass) {
	$table = array_merge([
	"THEAD" => [],
	"TBODY" => [],
	], $table);
	
	$dataTHEAD = $table['THEAD'];
	$dataTBODY = $table['TBODY'];
	$tableClass = (is_numeric($htmlClass) ? 'data' : $htmlClass);
	
	if (count($dataTHEAD) > 0) :
?>
	<table border="1" class="<?=$tableClass?>">
	<thead>
	<tr>
<?php
		foreach ($dataTHEAD as $th) :
			$label = (is_array($th) ? $th[1] : $th);
			print '<th scope="col">'.$label."</th>";
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
} /* do_output_data_table() */

function my_request_url ($part = null, $path = null) {
	$myUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'] . '/' . ltrim($_SERVER['REQUEST_URI'], '/');
	if (!is_null($path)) :
		$myUrl = rtrim($myUrl, '/') . '/' . $path;
	endif;
	
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
function is_passthru_request () { return (!is_root_request() and !is_browse_request() and !is_archive_request()); }
function is_root_request () { return preg_match("\007^/*(".preg_quote(my_script_name()).")?$\007i", my_request_url('path')); }
function is_browse_request () { return preg_match("\007^/*".preg_quote(ALACOVDAT_URL)."(/.+)?$\007i", my_request_url('path'), $refs); }
function is_archive_request () { return preg_match("\007^/*".ALACOVDAT_REQUEST_URL."(/.+)?$\007i", my_request_url('path'), $refs); }
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
$dataTable = [];
$metaInput = [
	'file' => null,
];
$tableClass = "data";
$all_tags = [];

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
		list($type, $siteslug) = explode("/", trim($s, "/"), 2) + ['', ''];
		return [
		$s,	$type,
		make_browse_link(["class" => "browse ".$siteslug, "href" => ["slug" => $s], "text" => $siteslug]),
		get_most_recent_timestamp(get_slug_timestamps($s, $allSlugs)),
		array_merge([$type], get_slug_tags($siteslug)),
	]; }, $availableSlugs);

	$oNow = new SnapshotDateTime(time());

	foreach ($slugLinks as $slugLink) :
		list($slug, $snapType, $link, $ts, $tags) = $slugLink;
		$slugpath = explode("/", trim($slug, '/'));
		
		$oLatest = new SnapshotDateTime($ts);
		$latestLink = make_browse_link(["class" => "view", "href" => ["date" => $ts, "slug" => $slug], "text" => $oLatest->human_readable()]);
		$latest = "latest: ${latestLink}";
		$ext = ($slugpath[0]=='html' ? 'html' : 'json');

		$myLink = $link;
		if ($slug==$params['slug']) :
			$myLink = preg_replace('!<a \s+!ix', '<a class="current" ', $myLink);
		endif;
		
		if (is_null($params['tag']) or in_array($params['tag'], $tags)) :
			$arX = new ArchivedSource([
				"slug" => $slug,
				"ts" => $oLatest->datetimecode(),
				"file type" => $ext
			]);
			$metaTable[] = [
				$snapType,
				$myLink
				. ' <span class="archive-services">'
				. get_archive_service_link(get_archive_request_url(), $arX->source_url(), 'all')
				. ' ' . get_archive_service_link('https://archive.today/', $arX->source_url(), 'archive.today: '.$arX->source_url('host'))
				. ' ' . get_archive_service_link('https://archive.org/', $arX->source_url(), 'Internet Archive: '.$arX->source_url('host')) . '</span>',
				implode("; ", $tags),
				"<small>${latest}</small>",
				"@class" => array_map(function ($e) { return 'tagged-'.$e; }, $tags)
			];
		endif;
	
		$all_tags = array_merge($all_tags, $tags);
	endforeach;
	usort($metaTable, function ($a, $b) { return strcmp(strip_tags($a[1]), strip_tags($b[1])); });

	$metaTable = array_merge([ ["Type", "<b>Source</b>", "<b>Tags</b>", "<b>Latest</b>"] ], $metaTable);

	$all_tags = array_unique($all_tags);
	
	$outWhat = "Listing";
	$oDateTime = new SnapshotDateTime(time());
	
	$rawDataOut = null;
	
	$dataTHEAD = [];
	$dataTBODY = [];
	$dataTHEAD = ["Source", "Type", "Timestamp"];
	foreach ($lists['sets'] as $pair) :
		list($slug, $ts) = $pair;
		$slugParts = array_filter(explode("/", trim($slug, '/'), 2)) + ['', ''];
		$slugtype = $slugParts[0]; $slugsource = $slugParts[1];
		$ext = ($slugtype=='html' ? 'html' : 'json');
		
		$oDateTime = new SnapshotDateTime($ts);

		$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);
		
		if (!array_key_exists("${tableClass} $slugsource", $dataTBODY)) :
			$dataTBODY["${tableClass} $slugsource"] = [];
		endif;

		$checksum = $arX->payload_checksum();
		$dataTBODY["${tableClass} $slugsource"][] = [
			"Type" => $slugtype,
			"Source" => $slugsource,
			"Timestamp" => make_browse_link([
			"href" => ["slug" => $slug, "date" => $ts],
			"text" => $oDateTime->human_readable(),
			"title" => is_null($checksum) ? "source: ".$arX->source_url() : "checksum: ".$arX->payload_checksum(),
		])];
	endforeach;
	$dataTable = array_map(function ($e) use ($dataTHEAD) { return ["THEAD" => $dataTHEAD, "TBODY" => $e]; }, $dataTBODY);
	
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
	if (!is_null($checksum=$arX->payload_checksum())) :
		$metaTable[] = ["Checksum", $checksum];
	endif;
	
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
		$out .= "<h2>" . $arX->source_url('host') . "</h2>";
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

		if (count($dataTable['THEAD']) + count($dataTable['TBODY']) > 0) :
			$viewOptions = array_merge(
			['<a href="#view-data-table" class="tab">data table</a>'],
			$viewOptions);
		endif;
		
		$metaTable[] = ["View",  implode(" / ", $viewOptions)];

		$rawDataOut = $hash;
	endif;
	
endif;


if (strlen($out) == 0 and count($dataTable)==0) exit;

	$sTimestamp = (is_null($oDateTime) ? null : $oDateTime->human_readable());
?>
<!DOCTYPE html>
<html>
<head>
<title>Documenting Covid-19: Alabama's Responses (Development/Test Site)</title>
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

a.current {
	font-weight: bold;
	color: #000;
	text-decoration: none;
}

table.nav {
	float: left;
	margin-right: 20px;
	margin-bottom: 10px;
}
#view-nav-table table.nav {
	float: none !important;
}

section {
	clear: both;
}
section#view-nav-table {
	clear: none !important;
	float: left !important;
}
#view-tags ul { display: block; list-style: none; margin: 0; padding: 10px 10px; }
#view-tags li { display: inline-block; list-style: none; margin: 0; padding: 0 10px 0 0; }

.archive-services {
	display: none;
}
h2:hover .archive-services, th:hover .archive-services, td:hover .archive-services {
	display: block;
	position: absolute;
	background-color: #eeeea0;
	color: black;
	border-radius: 2px;
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
function isNavTableInterface () {
	return ($('#meta-table').filter('.nav').find('a.browse').length > 0);
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
function filterNavSectionFromLink (e) {
	
	let linkClasses = $(e.target).attr('class').split(/\s+/);
	let activeTable = $('table.nav').filter("." + linkClasses[1]);
	
	if (activeTable.length > 0) {
		e.preventDefault();
		
		$('#view-nav-table').find('table.nav').fadeOut({
			duration: 250
		}).promise().then( function () {
			$('a.browse').removeClass('current').promise().then( function () { $(e.target).addClass('current'); } );
			$(activeTable).fadeIn({
				duration: 250,
			}).promise().then( function () {
				$('html, body').animate({
					scrollTop: $(activeTable).offset().top
				}, 500 /*ms*/);
			});
		});
	}
}
function filterNavMainFromTagLink (e) {
	
	let path = e.target.pathname;
	let dirs = path.trim('/').replace(/^\/+/, '').split(/\/+/);
	
	if (dirs.length > 2 && dirs[0]=='browse' && dirs[1]=='tag') {
		let activeTag = dirs[2];
		
		if ($('tr.tagged-'+activeTag).length > 0) {
			e.preventDefault();
			
			$('tr.tagged-html, tr.tagged-data').hide().promise().then( function () {
				$('tr.tagged-'+activeTag).fadeIn({ duration: 250 /*ms*/ });
			});
		
			$('a.view-tag').removeClass('current').promise().then(function () {
				$(e.target).addClass('current');
			});
		}
	}
}
function setupNavTableTabLinks() {
	$('#meta-table').filter('.nav').find('a.browse').click( filterNavSectionFromLink );
	if ($('#view-nav-table').find('table.nav').length > 1) {
		$('#view-nav-table').find('table.nav').hide();
	}
	
	$('#view-tags').find('a.view-tag').click( filterNavMainFromTagLink );
	if ($('#view-tags').find('a.view-tag').length > 0) {
		$('#view-tags').find('a.view-tag').eq(0).click();
	}
	
	$('.archive-service').click( activateArchiveBookmarkletFromLink );
	
}

const myArchiveUrl = <?=json_encode(get_archive_request_url())?>;
const myArchiveUrlHost = <?=json_encode(get_archive_request_url('host'))?>;
function activateArchiveBookmarkletFromLink (e) {
	let link=e.currentTarget;
	let service = link.hostname;
	let services = {
	"*": function (url) {
		for (var service in services) {
			if (service != myArchiveUrlHost && service != '*') {
				services[service](url);
			} /* if */
		} /* for */
	},
	"archive.today": function (url) { window.open('https://archive.today?run=1&url='+url); },
	"archive.org": function (url) { window.open('https://web.archive.org/save/'+decodeURIComponent(url)) }
	};
	services[myArchiveUrlHost] = services['*'];
	
	if (typeof(services[service]) != 'undefined') {
		e.preventDefault();

		let url=link.href;
		if (link.hash.length > 0) {
			url=link.hash.slice(1);
		} else {
			url = link.href.replace(myArchiveUrl, "").replace(/^\/+/, '');
			url = decodeURIComponent(url);
			url = decodeURIComponent(url);
		}
		
		if ( url.length == 0 ) {
			url=window.location.href;
		}
		console.log(service, url);
		services[service](url);
	} /* if */
}
	
$(document).ready( function () {
	if (isTabbedInterface()) {
		setupSnapshotTabLinks();
		hideSnapshotTabs();
	} else if (isNavTableInterface()) {
		setupNavTableTabLinks();
	} /* if */
});
//]>
</script>
</head>
<body>
<h1><a href="/">Documenting Covid-19: Alabama's Responses</a></h1>
<p>development and test site for web archiving project</p>
<h2><?=$outWhat?> Snapshot: <?=$sTimestamp?>
<span class="archive-services"><?php
	print get_archive_service_link(get_archive_request_url());
	print get_archive_service_link('https://archive.today/');
	print get_archive_service_link('https://archive.org/');
?></span>
</h2>
<?php
	if (count($all_tags) > 0) :
?>
<nav id="view-tags"><ul>
<?php
		foreach ($all_tags as $tag) :
?>
	<li><a class="view-tag" href="/browse/tag/<?=$tag?>"><?php print $tag; ?></a></li>
<?php
		endforeach;
?>
</ul></nav>
<?php
	endif;
?>

<?php
	if (count($metaTable) > 0) :
?>
	<table border="1" id="meta-table" class="<?=$tableClass; ?>">
	<tbody>
<?php
		$isAttrib = function ($e) { return !!preg_match('/^@/', $e); };
		
		foreach ($metaTable as $row) :
			$attribNames = array_filter(array_keys($row), $isAttrib);
			$tag = ["tr"];
			foreach ($attribNames as $attribName) :
				$key = htmlspecialchars(preg_replace('/^@/', '', $attribName));
				$value = $row[$attribName];
				if (is_array($value)) :
					$value = implode(" ", $value);
				endif;
				$value = htmlspecialchars($value);
				$tag[] = "${key}=\"${value}\"";
			endforeach;
			$TR = implode(" ", $tag);
			
			print "<${TR}>";
			$i = 0;
			foreach ($row as $key => $col) :
				if (!$isAttrib("${key}")) :
					print ($i>0) ? "<td>" : "<th>";
					print $col;
					print ($i>0) ? "</td>" : "</th>";
					$i++;
				endif;
			endforeach;
			print "</tr>\n";
		endforeach;
?>
	</tbody>
	</table>
	
<?php
	endif;

	if (count($dataTable) > 0) :
		if (array_key_exists('TBODY', $dataTable)) :
			$dataTable = [$tableClass => $dataTable];
		endif;

		$htmlClasses = preg_split('/\s+/', trim($tableClass));
		$topClass = $htmlClasses[0];
	
		print "<section id='view-${topClass}-table'>\n";
		foreach ($dataTable as $key => $table) :
			do_output_data_table($table, $key);
		endforeach;
		print "</section>\n\n";
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

