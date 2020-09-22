<?php
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

