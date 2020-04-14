<?php
class ArchivedSource {
	private $_sSlug;
	private $_sTs;
	private $_sExt;
	private $_sDataDir;
	
	public function __construct ($params = []) {
		$params = array_merge([
		"slug" => null,
		"ts" => null,
		"file type" => null,
		"data dir" => dirname(__FILE__) . "/covid-data",
		], $params);

		$this->_sSlug = $params['slug'];
		$this->_sTs = $params['ts'];
		$this->_sExt = $params['file type'];
		$this->_sDataDir = $params['data dir'];
	} /* ArchivedSource::__construct () */

	protected function data_dir () {
		return $this->_sDataDir;
	}
	
	protected function data_prefix ($basedir = null) {
		return (is_null($basedir) ? $this->data_dir() : $basedir) . "/" . $this->_sSlug . "-";
	}
	
	public function ts () {
		return $this->_sTs;
	}
	
	public function source_url ($part = null) {
		$sourceUrl = null;
		
		// URL of snapshot: Get it from the file, if available
		$url_file = $this->url_file();
		if (is_readable($url_file)) :
			$sourceUrl = trim(file_get_contents($url_file));
		endif;
		
		// Allow parsing of the URL into little bits: scheme, host, path, query, fragment...
		if (!is_null($sourceUrl) and !is_null($part)) :
			$parts = parse_url($sourceUrl);
			$sourceUrl = (isset($parts[$part]) ? $parts[$part] : null);
		endif;
		return $sourceUrl;
	}
	
	public function capture_file ($type) {
		return $this->data_prefix().$this->ts().".".$type;
	}
	
	public function url_file () {
		return $this->capture_file('url.txt');
	} /* ArchivedSource::url_file () */

	public function payload_file () {
		return $this->capture_file($this->_sExt);
	}
	
	public function screenshot_file () {
		return $this->capture_file("png");
	}

	public function screenshot_url () {
		return $this->data_prefix('/covid-data') . $this->ts() . ".png";
	}
} /* class ArchivedSource */
