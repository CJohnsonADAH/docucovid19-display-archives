<?php
class MirroredURL {
	private $_sBaseDir;
	private $_sDataBase;
	private $_sSnapBase;
	private $_sUrl;
	private $_sFile;
	private $_sTs;
	private $_sSlug;
	private $_vTest;
	
	public function __construct ($params = array()) {
		global $_REQUEST;
		
		$params = array_merge([
		"archive" => null,
		"url" => null,
		"file" => null,
		"slug" => null,
		"ts" => null,
		"base dir" => dirname(__FILE__),
		"data base" => "/covid-data",
		"snap base" => "/html/snapshots/",
		"test" => (isset($_REQUEST['MirroredURL-Test']) ? $_REQUEST['MirroredURL-Test'] : null),
		], $params);

		if (!is_null($arX=$params['archive'])) :
			$params['slug'] = basename($arX->slug());
			$params['ts'] = $arX->ts();
			$params['url'] = $arX->source_url();
		endif;
		
		$this->_vTest = $params['test'];
		
		$this->_sBaseDir = $params['base dir'];
		$this->_sDataBase = $params['data base'];
		$this->_sSnapBase = $params['snap base'];

		$this->_sTs = $params['ts'];
		$this->_sSlug = $params['slug'];
		
		if (!is_null($params['url'])) :
			$this->set_url($params['url']);
		endif;

		if (!is_null($params['file'])) :
			if (is_null($this->_sTs)) :
				$this->_sTs = $this->get_ts($params['file']);
			endif;
			
			$this->set_file($params['file']);
		endif;
	} /* MirroredURL::__construct () */
	
	protected function is_test ($tag = null) {
		$ret = !is_null($this->_vTest);
		if (!is_null($tag)) :
			$ret = ($ret and $this->_vTest == $tag);
		endif;
		return $ret;
	}
	
	protected function console_log ($obj, $name = null) {
		if ($this->is_test()) :
			if (is_string($obj)) :
				$v = $obj."\n";
			else :
				ob_start();
				var_dump($obj);
				$v = ob_get_clean();
			endif;

			if (is_null($name)) :
				printf("%s", $v);
			else :
				printf("%s=%s", $name, $v);
			endif;

		endif;
	}
	
	public function ts () {
		return $this->_sTs;
	}
	
	protected function re_ts($after = '') {
		$pattern = "|^(.*";
		$pattern .= preg_quote($this->_sSnapBase);
		$pattern .= "[a-z0-9]+/+";
		$pattern .= ")([0-9]+Z)";
		$pattern .= $after;
		$pattern .= '$|ix';
		return $pattern;
	}
	
	public function get_ts ($file) {
		$base = null;
		
		$pattern = $this->re_ts('(/+.*)');
		if (preg_match($pattern, $file, $ref)) :
			$parent = $ref[1];
			$base = $ref[2];
		endif;
		return $base;
	}
	
	public function get_contents () {
		$filepath = $this->get_readable();
		return file_get_contents($filepath);
	}

	protected function unmunge_wget_html ($munged, $unmunged, $html) {
		if (preg_match("/^re:(.*)$/", $munged, $ref)) :
			$munged = $ref[1];
			$replace = function ($m, $u, $h) { return preg_replace($m, $u, $h); };
		else :
			$replace = function ($m, $u, $h) { return str_replace($m, $u, $h); };
		endif;
		
		return $replace($munged, $unmunged, $html);
	}
	
	public function get_filtered_html ($dependencies = false) {
		// 0. Add a base[@href] tag for handling relative URIs better, even when
		// the URL of this page is weirded by the pass-thru mechanism
		$html = $this->get_contents();
		
		$html = preg_replace(
			"!(<base(\s+[^>]+)?>)!ix",
			"",
			$html
		);
		$html = preg_replace(
			"!(<head(\s+[^>]*)?>)!ix",
			'$1<base href="' . $this->url() . '" />',
			$html
		);
		
		$html = $this->unmunge_wget_html(
			'<a href="https://altogetheralabama.org/\&quot;/join-the-list\&quot;"',
			'<a href=\"/join-the-list\"',
			$html
		);
		
	$adph_munged=<<<EOH
'<a href="https://www.alabamapublichealth.gov/infectiousdiseases/'+&#32;WEBAPPT&#32;+'"
EOH;
	$adph_unmunged=<<<EOH
'<a href="'+ WEBAPPT +'"
EOH;
		$html = $this->unmunge_wget_html(
			trim($adph_munged),
			trim($adph_unmunged),
			$html
		);
		
	$uah_munged=<<<EOH
<img src="https://www.uah.edu/\&quot;images\/news\/virus_1440.jpg\&quot;"
EOH;
	$uah_unmunged=<<<EOH
<img src=\"images\/news\/virus_1440.jpg\"
EOH;
		$html = $this->unmunge_wget_html(
			trim($uah_munged),
			trim($uah_unmunged),
			$html
		);

	$uah_munged='re:!["]https://www[.]uah[.]edu/\\\\[&]quot[;]([^&]*)\\\\[&]quot;["]!'; //\&quot;(.*)\&quot;!';
	$uah_unmunged='\"$1\"';

		$html = $this->unmunge_wget_html(
			trim($uah_munged),
			trim($uah_unmunged),
			$html
		);

	$adph_munged='re:!'.preg_quote('"https://dph1.adph.state.al.us/\\&quot;').'([^"]+)'.preg_quote('\\""').'!';
	$adph_unmunged='\\"$1\\"';
	
		$html = $this->unmunge_wget_html(
			trim($adph_munged),
			trim($adph_unmunged),
			$html
		);
	
		// Sub-Resource Integrity Checks will often fail, because wget has modified the resource
		$has_integrity='re:!integrity="([^"]+)"!ix';
		$no_integrity="";
	
		$html = $this->unmunge_wget_html(
			trim($has_integrity),
			trim($no_integrity),
			$html
		);
	
		if ($dependencies !== false) :
			$html = $this->resolve_ajax_dependencies($html);
		endif;
		
		return $html;
	} /* get_filtered_html () */

	public function resolve_ajax_dependencies ($html = null) {
		if (is_null($html)) :
			$html = $this->get_contents();
		endif;
		
		$jsonMirrorUrls = file_get_contents(dirname(__FILE__)."/json-mirror-urls.json");
		$dataMirrorUrls = json_decode($jsonMirrorUrls);

		if (is_object($dataMirrorUrls)) :
			$oDateTime = new SnapshotDateTime($this->ts());

			$dataMirrorUrls = (array) $dataMirrorUrls;
			foreach ($dataMirrorUrls as $to => $from) :
				$oldHtml = $html;
				$sub = $this->get_json_url($to, $oDateTime);
				if (!is_null($sub)) :
					$html = str_replace(
						$from, $sub,
						$html
					);
				endif;
			endforeach;
		else :
			echo "DataMirrorURLs: JSON Encoding Error."; exit;
		endif;
		return $html;
	}
	
	protected function get_json_url ($slug, $datetime) {
		$dt = (is_object($datetime) ? $datetime->datetimecode() : $datetime);
		
		if (strlen($slug) > 0) :
			$capturePrefix = "data/${slug}";
			$captureDtPrefix = "data/${dt}/${slug}";
		else :
			$capturePrefix = "capture";
		endif;
		
		$testUrls = [
			"/covid-data/${capturePrefix}-${dt}.json",
			"/covid-data/${captureDtPrefix}-${dt}.json",
		];
		
		$docRoot = $_SERVER['DOCUMENT_ROOT'];
		$captureUrl = null;
		foreach ($testUrls as $test) :
			if (is_readable($docRoot . $test)) :
				$captureUrl = $test;
				break;
			endif;
		endforeach;
		return $captureUrl;
	} /* get_json_url () */	

	protected function to_get_params ($params) {
		$http_params = [];
		foreach ($params as $key => $value) :
			$http_params[] = urlencode($key) . "=" . urlencode($value);
		endforeach;
		
		return (count($http_params)==0 ? '' : '?' . implode("&", $http_params));
	}
	
	public function get_mirror_url () {
		$params = [
		"date" => $this->ts(),
		"mirrored" => $this->file(),
		];

		return "/" . $this->to_get_params($params);
	}
	
	public function get_readable () {
		$filepath = $this->get_path($this->_sFile);
		
		$bReadable = is_readable($filepath);
		$bDir = is_dir($filepath);
		$bFile = ($bReadable and !$bDir);
		
		$ext = "html";
		if (!$bFile and is_readable("${filepath}.${ext}")) :
			$filepath = "${filepath}.${ext}";
		endif;

		if ($bDir and is_readable("${filepath}/index.html")) :
			$filepath = "${filepath}/index.html";
		endif;
						
		return $filepath;
	}
	
	public function get_path ($filename = null) {
		$path = rtrim($this->_sBaseDir, '/');
		if (!is_null($filename)) :
			$file = ltrim($filename, '/');
			$path = "${path}/${file}";
		endif;
		return $path;
	}
	
	public function file () {
		return $this->_sFile;
	}
	
	public function warc_url () {
		$url=null;
		if (!is_null($warc=$this->warc_file())) :
			$db = rtrim($this->_sBaseDir, "/");
			$url = preg_replace("\007^".preg_quote($db)."\007x", "", $warc);
		endif;
		return $url;
	}
	public function warc_file () {
		$db = $this->_sDataBase;
		$sb = $this->_sSnapBase;
		$snapDir = rtrim($db, "/") . "/" . trim($sb, "/");
		$slug = $this->_sSlug;
		$ts = $this->_sTs;
		$warc=$this->get_path($snapDir."/".$slug."/".$ts."/".$slug."-".$ts.".warc.gz");
		return (is_readable($warc) ? $warc : null);
	}
	
	public function filepath () {
		return $this->get_path($this->_sFile);
	}
	
	public function url () {
		return $this->_sUrl;
	}
	
	protected function set_url (string $url) {
		$this->_sUrl = $url;
		
		$source = [];
		if (strlen($this->_sUrl) > 0) :
			$source = parse_url($this->_sUrl);
		endif;
		
		$source = array_merge([
		"scheme" => "file",
		"host" => "localhost",
		"path" => "",
		"query" => "",
		"fragment" => "",
		], $source);

		if (strlen($source['path']) > 0) :
			$base = rtrim($this->_sDataBase, "/")."/".trim($this->_sSnapBase, "/");
			$slug = $this->_sSlug;
			$ts = $this->_sTs;
			$host = rtrim($source['host'], "/");
			$path = ltrim($source['path'], "/");
			$query = (strlen($source['query']) > 0 ? '?'.$source['query'] : "");
			$file = "${base}/${slug}/${ts}/${host}/${path}${query}";
			
			$this->console_log($file, "set_url.file");
			$this->set_file($file);
		endif;
		
	}
	
	protected function set_file (string $file) {
		$this->_sFile = $file;
		$this->console_log($file, "_sFile");
		
		$filepath = $this->get_path($file);
		
		if (!is_readable($this->get_readable())) :
			// check whether we've got a screwy timestamp
			$newpath=$filepath;
			if (!is_null($this->ts())) :
				$v = $this->seek_file($file, $filepath, $this->ts());
				$this->console_log($v, "seek_file");

				list($newpath, $newfile, $newparent, $newts) = $v;
			endif;
			
			if ($newpath != $filepath) :
				$filepath = $newpath;
				$this->_sFile = $newfile;
				$this->_sTs = $newts;
			endif;
		endif;
		
		$base = rtrim($this->_sBaseDir, "/") . "/" . trim($this->_sDataBase, "/")."/".trim($this->_sSnapBase, "/");
		$reUrlPattern = "\007^".preg_quote($base)."/*([^/]+)(/+([0-9Z]+)(/+.*)?)?$\007x";
		$matched = preg_match($reUrlPattern, $filepath, $ref);
		if (is_null($this->_sSlug) and $matched) :
			$this->_sSlug = $ref[1];
		endif;
		if (is_null($this->_sTs) and $matched) :
			$this->_sTs = $ref[3];
		endif;
	}
	
	protected function seek_file (string $file, string $filepath, string $ts) {
		$found = false;
		
		$parent = $filepath;
		$base = basename($filepath);
		
		while (!$found and strlen($parent) > 1) :
			$base = basename($parent);
			$parent = dirname($parent);

			$pattern = $this->re_ts();
			if (preg_match($pattern, $parent, $ref)) :
				$parent = $ref[1];
				$base = $ref[2];
				$found = true;
			endif;
		endwhile;
		
		if ($found) :
			$aTs = array_map(function ($e) { return basename($e); }, glob($parent."/[0-9]*Z"));
			sort($aTs);
			$aTs = array_values(array_filter($aTs, function ($e) use ($base) { return ($e >= $base); }));
			if (count($aTs) >= 1) :
				$parent = rtrim($parent, '/') . '/';
				$testFile = str_replace($parent.$base, $parent."/".$aTs[0], $filepath);
				$this->console_log($testFile, "testfile");
				if (is_readable($testFile)) :
					$filepath = $testFile;
					$file = str_replace($this->get_path(), "", $testFile);
					$base = $aTs[0];
				endif;
			endif;
		endif;

		return [$filepath, $file, $parent.$base, $ts];
	}
}

if (basename($_SERVER['SCRIPT_NAME'])==basename(__FILE__)) :
	echo "Hello world!\n";
	$snapDir = '/covid-data/html/snapshots';
	$files = [
"${snapDir}/jeffco/20200407110001Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/jeffco/20200407110000Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/jeffco/99999999999999Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/mobilealabama/20200407110001Z/www.cityofmobile.org/visitors",
"${snapDir}/aum/20200407004838Z/www.aum.edu/coronavirus",
	];
	foreach ($files as $file) :
		$oMirror = new MirroredURL(["file" => $file, "test" => "yes"]);
		$contents = $oMirror->get_contents();
		$sWarcFile = $oMirror->warc_file();
		printf("<%s\n", $file);
		printf("get_contents(): %d %s\n", strlen($contents), "'" . substr($contents, 0, 10) . "...'");
		printf("warc_file(): %s\n", $sWarcFile);
		printf("\n");
	endforeach;
	exit;
endif;

