<?php
	$myDir = dirname(__FILE__);
	require_once("${myDir}/archivedsource.class.php");
	require_once("${myDir}/mirroredurl.class.php");
	require_once("${myDir}/snapshotdatetime.class.php");
	require_once("${myDir}/includes/archiveservices.class.php");
	require_once("${myDir}/includes/get_json_to_table.function.php");
	require_once("${myDir}/includes/get_snapshot_lists.function.php");
	
define('ALACOVDAT_DATA_DIR', "${myDir}/covid-data");
define('ALACOVDAT_URL', 'browse');
define('ALACOVDAT_REQUEST_URL', '(archive)');
define('ALACOVDAT_SOURCES_TSV', "${myDir}/sources.tsv.txt");

if (!is_readable(ALACOVDAT_DATA_DIR."/data")) :
	require_once("${myDir}/template-data-dir-missing-500.php");
	exit;
endif;

if (array_key_exists('test', $_REQUEST)) :
	if ($_REQUEST['test']=='phpinfo') :
		phpinfo();
		exit;
	endif;
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
			require_once("${myDir}/template-passthru-404.php");
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
	$tHeadBody = array_merge([
	"THEAD" => [],
	"TBODY" => [],
	], $table);
	
	$dataTHEAD = $tHeadBody['THEAD'];
	$dataTBODY = $tHeadBody['TBODY'];
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
	else :
?>
	<table border="1" id="meta-table" class="<?=$tableClass; ?>">
	<tbody>
<?php
		$isAttrib = function ($e) { return !!preg_match('/^@/', $e); };
		
		foreach ($table as $row) :
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
	require_once("${myDir}/process-mirroredurl.php");

elseif (is_index_request()) :
	require_once("${myDir}/process-index.php");
	
elseif (is_html_request($refs)) :
	require_once("${myDir}/process-html.php");
	
elseif (is_data_table_request()) :
	require_once("${myDir}/process-datatable.php");
	
endif;


if (strlen($out) == 0 and count($dataTable)==0) exit;

	$sTimestamp = (is_null($oDateTime) ? null : $oDateTime->human_readable());
?>
<!DOCTYPE html>
<html>
<head>
<title>Documenting Covid-19: Alabama's Responses (Development/Test Site)</title>

<link rel="stylesheet" href="/assets/css/alacovdat.css" media="all" />

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript" src="/assets/js/alacovdat.js"></script>
<link rel="archive-request-url" href="<?=htmlspecialchars(get_archive_request_url())?>" />
</head>
<body>
<h1><a href="/">Documenting Covid-19: Alabama's Responses</a></h1>
<p>development and test site for web archiving project</p>
<h2><?=$outWhat?> Snapshot: <?=$sTimestamp?>
<?=get_all_archive_services_links();?>
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
		do_output_data_table($metaTable, $tableClass);
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
		echo "<pre><code>"; echo (is_string($rawDataOut) ? $rawDataOut : json_encode($rawDataOut, JSON_PRETTY_PRINT)); echo "</code></pre>";
?>
</section>
<?php
	endif;
?>
</body>
</html>

