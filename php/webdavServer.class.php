<?php

/**
 * webdav 服务端
 * 
 * 简易文档: https://tech.yandex.com/disk/doc/dg/reference/put-docpage/
 * 文档:     http://www.webdav.org/specs/rfc2518.html
 */
class webdavServer {
	public $lastError = '';
	public $method = '';
	public function __construct($root,$DAV_PRE_PATH) {
		$this->root = $root;
		$this->initPath($DAV_PRE_PATH);
		$this->start();
	}
	public function initPath($DAV_PRE_PATH){
		$GLOBALS['requestFrom'] = 'webdavNextcloud';
		$this->method = 'http'.HttpHeader::method();
		$uri  = rtrim($_SERVER['REQUEST_URI'],'/').'/'; //带有后缀的从domain之后部分;
		if(!$this->pathCheck($uri)){//路径长度限制
			$this->lastError = LNG('common.lengthLimit');
			$this->response(array("code"=>404));exit;
		}
		$this->urlBase  = substr($uri,0,strpos($uri,$DAV_PRE_PATH)+1); //$find之前;
		$this->urlBase  = rtrim($this->urlBase,'/').$DAV_PRE_PATH;
		$this->uri   	= $this->pathGet();
		$this->path 	= $this->parsePath($this->uri);
		if(strpos($uri,$DAV_PRE_PATH) === false){
			$this->lastError = LNG('common.noPermission');
			$this->response(array("code"=>404));exit;
		}		
	}
	public function checkUser(){
		$user = HttpAuth::get();
		if($user['user'] == 'admin' && $user['pass'] == '123'){
			return true;
		}
		HttpAuth::error();
	}
	private function pathCheck($path){
		$PATH_LENGTH_MAX = 4096;//路径最长限制;
		return strlen($path) >= $PATH_LENGTH_MAX ? false:true;
	}
	
	public function start(){
		$method = 'http'.HttpHeader::method();
		if(!method_exists($this,$method)){
			return HttpAuth::error();
		}
		if($method == 'httpOPTIONS'){
			return self::response($this->httpOPTIONS());
		}
		$this->checkUser();
		$notCheck = array('httpMKCOL','httpPUT');
		if( !in_array($method,$notCheck) && 
			!$this->pathExists($this->path,true) ){
			$result = array('code' => 404);
		}else{
			$result = $this->$method();
		}
		if(!$result) return;//文件下载;
		self::response($result);
	}
	public function pathGet($dest=false){
		$path = $dest ? $_SERVER['HTTP_DESTINATION'] : $_SERVER['REQUEST_URI'];
		$path = parse_url($path,PHP_URL_PATH);
		$path = KodIO::clear(rawurldecode($path));
		$base = KodIO::clear($this->urlBase);
		if(rtrim($path,'/') == rtrim($base,'/')) return '/';
		if(strpos($path,$base) !== 0) return false;
		return substr($path,strlen($base));
	}
	
	public function pathExists($path,$allowInRecycle=false){
		return file_exists($path);
	}
	public function pathMkdir($path){
		return mkdir($path,DEFAULT_PERRMISSIONS,true);
	}
	public function pathInfo($path){
		return path_info($path);
	}
	public function pathList($path){
		return path_list($path);
	}
	// range支持;
	public function pathOut($path){
		echo file_get_contents($path);
	}
	public function pathPut($path,$tempFile=''){
		if(!$tempFile){
			return file_put_contents($path,'');
		}
		return move_path($tempFile,$path);
	}
	public function pathRemove($path){
		if(is_file($path)){
	        return @unlink($this->path);
	    }else{
	        return del_dir($this->path);
	    }
	}
	public function pathMove($path,$dest){
		return move_path($path,$dest);
	}
	public function pathCopy($path,$dest){
		return copy_dir($path,$dest);
	}
    public function parsePath($path){
    	return $path;
	}
	public function parseItem($item,$isInfo){
		$pathCurrent = trim($this->pathGet(),'/');
		$pathAdd = trim($pathCurrent.'/'.$item['name'],'/');
		if($isInfo){
			$pathAdd = $pathCurrent;
		}
		$pathAdd = $pathAdd === '' ? '/' : '/'.str_replace('%2F','/',rawurlencode($pathAdd));
		$defaultTime = (defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT) ? 1600000000 : time();
		if(!trim($item['modifyTime'])){$item['modifyTime'] = $defaultTime;}
		if(!trim($item['createTime'])){$item['createTime'] = $defaultTime;}
		
		$result  = array(
			'href' 			=> KodIO::clear(rtrim($this->urlBase,'/').$pathAdd),
			'modifyTime' 	=> @gmdate("D, d M Y H:i:s",$item['modifyTime']).' GMT',
			'createTime' 	=> @gmdate("Y-m-d\\TH:i:s\\Z",$item['createTime']),
			'size' 			=> $item['size'] ? $item['size']:0,
		);
		return $result;
	}
	
	public function parseItemXml($itemFile,$isInfo){
		if(defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT && isset($itemFile['path']) && (empty($itemFile['sourceID']) || empty($itemFile['metaInfo']))){
			$infoFull = IO::infoFull($itemFile['path']);
			if(is_array($infoFull)) $itemFile = array_merge($itemFile,$infoFull);
		}
		$item = $this->parseItem($itemFile,$isInfo);
		$etag = $this->itemEtag($itemFile);
		$fileID = $this->itemFileID($itemFile);
		$permissions = $this->itemPermissions($itemFile);
		$checksums = $this->itemChecksums($itemFile);
		$favorite = $this->itemFavorite($itemFile);
		$privateLink = $this->itemPrivateLink($itemFile);
		$hasPreview = $itemFile['type'] == 'file' ? 'false':'false';
		$lockDiscovery = $this->itemLockDiscovery($itemFile);
		if ($itemFile['type'] == 'folder') {//getetag
			$xmlAdd = "<D:resourcetype><D:collection/></D:resourcetype>";
			$xmlAdd.= "<D:getcontenttype>httpd/unix-directory</D:getcontenttype>";
			$item['href'] = rtrim($item['href'],'/').'/';
			if(isset($_SERVER['HTTP_DATE']) && isset($_SERVER['HTTP_DEPTH'])){
				// goodsync同步处理;HTTP_DATE/HTTP_DEPTH; 首次列表展开失败问题处理;
				$item['modifyTime'] = $_SERVER['HTTP_DATE'];
			}
		}else{
			$ext    = $itemFile['ext'] ? $itemFile['ext']:get_path_ext($itemFile['name']);
			$mime   = get_file_mime($ext);
			$xmlAdd = '<D:resourcetype/>';
			$xmlAdd.= "<D:getcontenttype>{$mime}</D:getcontenttype>";
		}
		
		$infoMore = array();
		$picker = array(
			'hasFile','hasFolder','fileInfo','fileInfoMore','oexeContent','parentID','isTruePath',
			'isReadable','isWriteable','sourceRoot','icon','iconClassName','children',
			'listAllChildren','fileThumb','fileThumbCover','fileShowView',
		);
		foreach ($picker as $key){
			if(array_key_exists($key,$itemFile)){$infoMore[$key] = $itemFile[$key];}
		}
		if($itemFile['type'] == 'file'){
			$param = array('path'=>$itemFile['path']);
			$infoMore['fileOutLink'] = Action('user.index')->apiSignMake('explorer/index/fileOut',$param);
		}
		if($infoMore){
			$xmlAdd.= "<D:extendFileInfo>".base64_encode(json_encode($infoMore))."</D:extendFileInfo>";
		}
		
		return "
		<D:response>
			<D:href>{$item['href']}</D:href>
			<D:propstat>
				<D:prop>
					<D:getlastmodified>{$item['modifyTime']}</D:getlastmodified>
					<D:creationdate>{$item['createTime']}</D:creationdate>
					<D:getcontentlength>{$item['size']}</D:getcontentlength>
					<D:getetag>&quot;{$etag}&quot;</D:getetag>
					<oc:fileid>{$fileID}</oc:fileid>
					<oc:id>{$fileID}</oc:id>
					<oc:size>{$item['size']}</oc:size>
					<oc:permissions>{$permissions}</oc:permissions>
					<oc:checksums>{$checksums}</oc:checksums>
					<oc:owner-id>{$this->currentUserName()}</oc:owner-id>
					<oc:owner-display-name>{$this->currentUserName()}</oc:owner-display-name>
					<oc:favorite>{$favorite}</oc:favorite>
					<oc:privatelink>{$privateLink}</oc:privatelink>
					<nc:has-preview>{$hasPreview}</nc:has-preview>
					<D:quota-used-bytes>{$item['size']}</D:quota-used-bytes>
					<D:quota-available-bytes>-3</D:quota-available-bytes>
					<D:supportedlock>
						<D:lockentry>
							<D:lockscope><D:exclusive/></D:lockscope>
							<D:locktype><D:write/></D:locktype>
						</D:lockentry>
					</D:supportedlock>
					{$lockDiscovery}
					{$xmlAdd}
				</D:prop>
				<D:status>HTTP/1.1 200 OK</D:status>
			</D:propstat>
		</D:response>";
	}
	protected function itemEtag($item){
		$hash = $this->itemHash($item);
		$sourceID = isset($item['sourceID']) ? $item['sourceID']:'';
		$type = isset($item['type']) ? $item['type']:'';
		$size = isset($item['size']) ? $item['size']:0;
		$version = _get($item,'metaInfo.webdavEtag');
		if($type == 'folder'){
			$children = _get($item,'children.fileNum','').':'._get($item,'children.folderNum','');
			$mtime = isset($item['modifyTime']) ? $item['modifyTime']:'';
			$data = implode('|',array($sourceID,$type,$mtime,$size,$children,$version));
		}else{
			$mtime = isset($item['modifyTime']) ? $item['modifyTime']:'';
			$data = $hash ?
				implode('|',array($sourceID,$type,$mtime,$size,$hash)) :
				implode('|',array($sourceID,$type,$mtime,$size,$version));
		}
		return md5($data);
	}
	protected function itemHash($item){
		$hash = _get($item,'fileInfo.hashMd5');
		if(!$hash) $hash = _get($item,'hashMd5');
		if(!$hash) $hash = _get($item,'fileInfo.hashSimple');
		if(!$hash) $hash = _get($item,'hashSimple');
		if($hash) return $hash;
		$fileID = intval(_get($item,'fileInfo.fileID'));
		if(!$fileID) $fileID = intval(_get($item,'fileID'));
		if(!$fileID){
			$sourceID = intval(_get($item,'sourceID'));
			if($sourceID){
				$sourceInfo = Model('Source')->where(array('sourceID'=>$sourceID))->find();
				$fileID = intval(_get($sourceInfo,'fileID'));
			}
		}
		if(!$fileID) return '';
		$fileInfo = Model('File')->where(array('fileID'=>$fileID))->find();
		if(!is_array($fileInfo)) return '';
		if(_get($fileInfo,'hashMd5')) return _get($fileInfo,'hashMd5');
		if(_get($fileInfo,'hashSimple')) return _get($fileInfo,'hashSimple');
		return '';
	}
	protected function itemFileID($item){
		$id = isset($item['sourceID']) ? $item['sourceID']:'';
		if(!$id) $id = _get($item,'fileInfo.fileID');
		if(!$id) $id = _get($item,'fileID');
		if(!$id) $id = sprintf('%u',crc32(_get($item,'path','').'/'. _get($item,'name','')));
		return htmlentities($id.'');
	}
	protected function itemPermissions($item){
		if($item['type'] == 'folder') return 'RGDNVCK';
		return 'RGDNVW';
	}
	protected function itemFavorite($item){
		return '0';
	}
	protected function itemPrivateLink($item){
		$id = isset($item['sourceID']) ? intval($item['sourceID']) : 0;
		if(!$id) return '';
		$base = rtrim(APP_HOST,'/');
		if($item['type'] == 'folder'){
			$url = $base.'#explorer&path='.rawurlencode(KodIO::make($id));
		}else{
			$url = $base.'#explorer&sidf='.$id;
		}
		return htmlentities($url);
	}
	protected function itemChecksums($item){
		$sha1 = _get($item,'fileInfo.hashSha1');
		if(!$sha1) $sha1 = _get($item,'hashSha1');
		if(!$sha1) $sha1 = _get($item,'fileInfo.sha1');
		if(!$sha1) $sha1 = _get($item,'sha1');
		if($sha1) return 'SHA1:'.$sha1;
		$md5 = $this->itemHash($item);
		if(!$md5){
			$simple = _get($item,'fileInfo.hashSimple');
			if(!$simple) $simple = _get($item,'hashSimple');
			if($simple && preg_match('/^[a-f0-9]{32}$/i',$simple)) $md5 = $simple;
		}
		return ($md5 && preg_match('/^[a-f0-9]{32}$/i',$md5)) ? 'MD5:'.$md5 : '';
	}
	protected function itemLockDiscovery($item){
		return '<D:lockdiscovery/>';
	}
	protected function currentUserName(){
		$user = Session::get("kodUser");
		$name = is_array($user) ? ($user['name'] ? $user['name']:$user['userID']) : '';
		return htmlentities($name.'');
	}
	protected function pathEtag($path){
		$info = $this->pathInfo($path);
		if(!$info || !is_array($info)) return false;
		return $this->itemEtag($info);
	}
	protected function etagHeader($path){
		$etag = $this->pathEtag($path);
		if(!$etag) return array();
		return array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"');
	}
	protected function etagMatch($header,$etag){
		if(!$header) return true;
		if(trim($header) == '*') return true;
		$etag = trim($etag,'"');
		$items = explode(',',$header);
		foreach($items as $item){
			$item = trim(str_replace('W/','',$item));
			$item = trim($item,'"');
			if($item === $etag) return true;
		}
		return false;
	}
	protected function preconditionCheck($path,$allowMissing=false){
		$exists = $this->pathExists($path,true);
		$etag = $exists ? $this->pathEtag($path) : false;
		$ifMatch = HttpHeader::get('If-Match');
		if($ifMatch){
			if(!$exists || !$this->etagMatch($ifMatch,$etag)){
				return array('code'=>412,'body'=>$this->errorBody('PreconditionFailed','If-Match failed'));
			}
		}
		$ifNoneMatch = HttpHeader::get('If-None-Match');
		if($ifNoneMatch){
			if(trim($ifNoneMatch) == '*' && $exists){
				return array('code'=>412,'body'=>$this->errorBody('PreconditionFailed','If-None-Match failed'));
			}
			if($exists && $etag && $this->etagMatch($ifNoneMatch,$etag)){
				return array('code'=>412,'body'=>$this->errorBody('PreconditionFailed','If-None-Match failed'));
			}
		}
		if(!$exists && !$allowMissing){
			return array('code'=>404,'body'=>$this->errorBody('ObjectNotFound','not exist'));
		}
		return false;
	}
	protected function afterWrite($path,$destPath=false,$action=''){
		return true;
	}
	public function pathListMerge($listData){
		if(!$listData) return $listData;
		$keyList = array('fileList','folderList','groupList');
		$list    = array();
		foreach ($listData as $key=>$typeList){
			if(!in_array($key,$keyList) || !is_array($typeList)) continue;
			$list = array_merge($list,$typeList);
		}
		//去除名称中的/分隔; 兼容存储挂载
		$filtered = array();
		foreach ($list as &$item) {
			if(isset($item['name']) && preg_match('/-chunking-[^-]+-[0-9]+-[0-9]+$/',$item['name'])){
				continue;
			}
			$item['name'] = str_replace('/','@',$item['name']);
			$filtered[] = $item;
		}
		return $filtered;
	}

	public function httpPROPFIND(){
		$listFile = $this->pathList($this->path);
		$list = $this->pathListMerge($listFile);
		$pathInfo = $listFile['current'];
		if(!is_array($list) || (isset($pathInfo['exists']) && $pathInfo['exists'] === false) ){//不存在;
			return array("code" => 404,"body" => $this->errorBody('ObjectNotFound','not exist'));
		}
		if(isset($listFile['folderList'])){
			$pathInfo['type'] = 'folder';
		}		
		//只显示属性;
		$isInfo = $pathInfo['type'] == 'file' || HttpHeader::get('Depth') == '0';
		// kodbox webdav挂载获取文件夹属性;
		if( $pathInfo['type'] == 'folder' && 
			HttpHeader::get('X_DAV_ACTION') == 'infoChildren'){
			$pathInfo = IO::infoWithChildren($pathInfo['path']);
		}
		
		// kodbox 挂载kod存储; listAll请求优化;
		if( $pathInfo['type'] == 'folder' && 
			isset($_SERVER['HTTP_X_DAV_ACTION']) && $_SERVER['HTTP_X_DAV_ACTION'] == 'kodListAll'){
			$pathInfo['listAllChildren'] = IO::listAllSimple($this->path,true);
		}
		
		if($isInfo){
			$list = array($pathInfo);
		}else{
			$pathInfo['name'] = '';
			$list = array_merge(array($pathInfo),$list);
		}
		$out = '';
		foreach ($list as $itemFile){
			$out .= $this->parseItemXml($itemFile,$isInfo);
		}
		// write_log([$this->pathGet(),$this->path,$pathInfo],'webdavNextcloud');
		$code = 207;//207 => 200;
		if(strstr($this->uri,'.xbel')){$code = 200;} // 兼容floccus

		// 扩展kod内容;
		$infoMore = array();
		$picker   = array('groupShow','pageInfo','targetSpace','listTypePhoto','listTypeSet','pageSizeArray');
		foreach ($picker as $key){
			if(array_key_exists($key,$listFile)){$infoMore[$key] = $listFile[$key];}
		}
		$infoMoreData = $infoMore ? base64_encode(json_encode($infoMore)):'';
		return array(
			"code" => $code,
			"headers" => array('X-extendFileList: '.$infoMoreData),
			"body" => "<D:multistatus xmlns:D=\"DAV:\" xmlns:oc=\"http://owncloud.org/ns\" xmlns:nc=\"http://nextcloud.org/ns\">\n{$out}\n</D:multistatus>"
		);
	}
		
	public function httpHEAD() {
		$info = $this->pathInfo($this->path);
		if(!$info && defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT){
			return array('code'=>404,'headers'=>array('Content-Length: 0'));
		}
		$etag = ($info && is_array($info)) ? $this->itemEtag($info) : md5(time());
        if(!$info || $info['type'] == 'folder'){
        	return array(
                'code' => 200,
                'headers' => array(
                    'Content-Type: text/html; charset=utf8',
					'Last-Modified: '.gmdate("D, d M Y H:i:s ",time())."GMT",
					'ETag: "'.$etag.'"',
                )
            );
        }

		return array(
			'code'=> 200,
			'headers' => array(
				'Vary: Range',
                'Accept-Ranges: bytes',
				'Content-length: '.$info['size'],
                'Content-type: '.get_file_mime($info['ext']),
                'Last-Modified: '.gmdate("D, d M Y H:i:s ", $info['mtime'])."GMT",
                'Cache-Control: max-age=86400,must-revalidate',
                'ETag: "'.$etag.'"',
            )
		);
	}
	public function httpOPTIONS() {
		return array(
            'code'	  => 200,
            'headers' => array(
                'DAV: 1, 2, 3, extended-kodbox',
                'MS-Author-Via: DAV',
                'Allow: OPTIONS, PROPFIND, PROPPATCH, MKCOL, GET, PUT, DELETE, COPY, MOVE, LOCK, UNLOCK, HEAD',
				'Accept-Ranges: bytes',
                'Content-Length: 0',
            )
        );
	}
	public function httpPROPPATCH(){
		$out = '
		<D:response>
			<D:href>'.$_SERVER['REQUEST_URI'].'</D:href>
			<D:propstat>
				<D:prop>
					<m:Win32LastAccessTime xmlns:m="urn:schemas-microsoft-com:" />
					<m:Win32CreationTime xmlns:m="urn:schemas-microsoft-com:" />
					<m:Win32LastModifiedTime xmlns:m="urn:schemas-microsoft-com:" />
					<m:Win32FileAttributes xmlns:m="urn:schemas-microsoft-com:" />
				</D:prop>
				<D:status>HTTP/1.1 200 OK</D:status>
			</D:propstat>
		</D:response>';
		return array(
			"code" => 207,
			"body" => "<D:multistatus xmlns:D=\"DAV:\">\n{$out}\n</D:multistatus>"
		);
	}
	
	public function httpGET() {
		$this->pathOut($this->path);
	}
	// 分片支持; X-Expected-Entity-Length
	public function httpPUT() {
		$existsBefore = $this->pathExists($this->path,true);
		if($check = $this->preconditionCheck($this->path,true)) return $check;
		$tempFile = $this->uploadFileLocal();
		$expectSize = $this->uploadExpectSize();
		if($expectSize > 0 && !$tempFile){
			$this->lastError = 'Upload body is empty or was not received by server.';
			return array(
				'code' => 503,
				'headers' => array('Content-Length: 0','X-DAV-ERROR: empty-upload-body'),
			);
		}
		if($tempFile && $expectSize > 0 && @filesize($tempFile) != $expectSize){
			@unlink($tempFile);
			$this->lastError = 'Upload body size mismatch.';
			return array(
				'code' => 503,
				'headers' => array('Content-Length: 0','X-DAV-ERROR: upload-body-size-mismatch'),
			);
		}
		if($tempFile){
			$code = $existsBefore ? 204 : 201;
		}else{
		    $tempFile = '';
			$code = 201;
		}
		$this->responseCleanBeforeAction();
		$result = $this->pathPut($this->path,$tempFile);
		$this->responseCleanAfterAction('PUT');
		@unlink($tempFile);
		if($result == false){$code = 404;}
		$etagPath = is_string($result) ? $result : $this->path;
		if($result) $this->afterWrite($etagPath,false,'PUT');
		$headers = $result ? $this->etagHeader($etagPath) : array();
		if($result && !$headers){
			$etag = md5($etagPath.'|'.time());
			$headers = array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"');
		}
		$headers[] = 'Content-Length: 0';
		return array("code"=>$code,"headers"=>$headers);
	}
	
	public function uploadFileTemp(){
		return TEMP_FILES;
	}
	// 兼容move_uploaded_file 和 流的方式上传
	public function uploadFileLocal(){
		$tempPath = rtrim($this->uploadFileTemp(),'/').'/';
		$dest 	= $tempPath.'upload_dav_'.rand_string(32);mk_dir($tempPath);
		$outFp 	= @fopen($dest, "wb");
		$in  	= @fopen("php://input","rb");
		if(!$in || !$outFp){@unlink($dest);return false;} 	
		$writeSize = 0;
		@stream_set_read_buffer($in,0);
		@stream_set_write_buffer($outFp,0);
		if(function_exists('stream_copy_to_stream')){
			while(!feof($in)){
				$written = @stream_copy_to_stream($in,$outFp,16 * 1024 * 1024);
				if($written === false) break;
				if($written === 0){
					if(feof($in)) break;
					usleep(10000);
					continue;
				}
				$writeSize += $written;
			}
			fclose($in);fclose($outFp);
			if(@filesize($dest) > 0 && @filesize($dest) == $writeSize) return $dest;
			@unlink($dest);return false;
		}
		$bufferSize = 16 * 1024 * 1024;
		while(!feof($in)) {
			$data = fread($in, $bufferSize);
			if($data === false) break;
			if($data === '') continue;
			$offset = 0;
			$length = strlen($data);
			while($offset < $length){
				$written = fwrite($outFp, substr($data,$offset));
				if($written === false || $written <= 0) break 2;
				$offset += $written;
				$writeSize += $written;
			}
		}
		fclose($in);fclose($outFp);
		if(@filesize($dest) > 0 && @filesize($dest) == $writeSize) return $dest;
		@unlink($dest);return false;
	}
	protected function uploadExpectSize(){
		$length = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
		$expect = isset($_SERVER['HTTP_X_EXPECTED_ENTITY_LENGTH']) ? intval($_SERVER['HTTP_X_EXPECTED_ENTITY_LENGTH']) : 0;
		return max($length,$expect);
	}

	
	/**
	 * 新建文件夹
	 */
	public function httpMKCOL() {
		if($check = $this->preconditionCheck($this->path,true)) return $check;
		if ($this->pathExists($this->path)) {
            return array('code' => 201); // alist 等程序可能额外调用;
            // return array('code' => 409);
        }
        $res  = $this->pathMkdir($this->path);
		if($res) $this->afterWrite($this->path,false,'MKCOL');
        return array('code' => $res?201:403,'headers'=>$res ? $this->etagHeader($this->path):array());
	}

	public function httpMOVE() {
		if($check = $this->preconditionCheck($this->path)) return $check;
		$dest    = $this->parsePath($this->pathGet(true));
		if (isset($_SERVER["HTTP_OVERWRITE"])) {
            $options["overwrite"] = $_SERVER["HTTP_OVERWRITE"] == "T";
        }
		$res 	= $this->pathMove($this->path,$dest);
		if($res) $this->afterWrite($this->path,$dest,'MOVE');
		return array('code' => $res?201:404,'headers'=>$res ? $this->etagHeader($dest):array());
	}
	public function httpCOPY() {
		if($check = $this->preconditionCheck($this->path)) return $check;
		$dest   = $this->parsePath($this->pathGet(true));
		$res 	= $this->pathCopy($this->path,$dest);
		if($res) $this->afterWrite($this->path,$dest,'COPY');
		return array('code' => $res?201:404,'headers'=>$res ? $this->etagHeader($dest):array());
	}
	public function httpDELETE() {
		if($check = $this->preconditionCheck($this->path)) return $check;
		$res = $this->pathRemove($this->path);
		if($res) $this->afterWrite($this->path,false,'DELETE');
		return array(
			'code' => $res ? 204 : 503,
			'headers' => array('Content-Length: 0'),
		);
	}
	public function httpLOCK() {
		$this->fileLock($this->path);
		$token      = $this->makeLockToken();
		$depth      = _get($_SERVER,'HTTP_DEPTH','infinity');
		$timeout    = _get($_SERVER,'HTTP_TIMEOUT','Infinite');
		$lockInfo   = '<d:prop xmlns:d="DAV:">
			<d:lockdiscovery>
				<d:activelock>
				    <d:locktype><d:write/></d:locktype>
				    <d:lockscope><d:exclusive/></d:lockscope>
					<d:depth>'.$depth.'</d:depth>
					<d:lockroot><d:href>'.$_SERVER['REQUEST_URI'].'</d:href></d:lockroot>
					<d:owner>'.trim($this->xmlGet('lockinfo/owner/href')).'</d:owner>
					<d:timeout>'.$timeout.'</d:timeout>
					<d:locktoken><d:href>opaquelocktoken:'.$token.'</d:href></d:locktoken>
				</d:activelock>
			</d:lockdiscovery>
		</d:prop>';
		
		return array(
            'code' => 201,
            'headers' => array(
				'Lock-Token: <opaquelocktoken:'.$token.'>',
				'Connection: keep-alive',
			),
			'body' => $lockInfo,
        );
	}
	
	private function makeLockToken(){
	    return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
	}
	
	public function httpUNLOCK() {
		$this->fileUnLock($this->path);
		return array('code' => 204);
	}
	public function fileLock($path){}
	public function fileUnLock($path){}

	public function xmlGet($key){
		static $xml = false;
		if(!$xml){
			// 禁用xml实体,避免xxe攻击; php8以上已废弃
			if(PHP_VERSION_ID < 80000) {libxml_disable_entity_loader(true);}
			$body = file_get_contents('php://input');
			if(!$body) return '';
			$xml = new DOMDocument();
			$xml->loadXML($body);
		}
		
        $tag = array_shift(explode('/', $key));
		$objData = $xml->getElementsByTagNameNS('DAV:', $tag);
		if($objData) return $objData[0]->nodeValue;
		return '';
	}
	
	public function getLastError(){return $this->lastError;}
	public function errorBody($title='',$desc=''){
		if(!$desc){$desc = $this->getLastError();}
		return 
		'<D:error xmlns:D="DAV:" xmlns:S="http://kodcloud.com">
			<S:exception>'.htmlentities($title).'</S:exception>
			<S:message>'.htmlentities($desc).'</S:message>
		</D:error>';
	}
	
	/**
    * 输出应答信息
    * @param array $data [header:array,code:int,body:string]
    */
    public function response($data) {
		$this->responseCleanAfterAction('response');
		if(!isset($data['headers']) || !is_array($data['headers'])) $data['headers'] = array();
		if(!isset($data['body'])) $data['body'] = '';
        $headers   = $data['headers'];
		$headers[] = HttpHeader::code($data['code']);
		$headers[] = 'Pragma: no-cache';
		$headers[] = 'Cache-Control: no-cache';
		$headers[] = 'X-DAV-BY: kodbox';
        foreach ($headers as $header) {
            header($header);
        }
		
		if($data['code'] >= 400 && !$data['body']){
			$data['body'] = $this->errorBody();
		}
        if(is_string($data['body'])) {
        	header('Content-Type: application/xml; charset=utf-8');
        	echo '<?xml version="1.0" encoding="utf-8"?>'."\n".$data['body'];
        }
        
        if($this->method != 'httpPROPFIND'){
            // write_log(array($_SERVER['REQUEST_URI'],$headers,$data),'webdavNextcloud');
        }
	}
	protected function responseCleanBeforeAction(){
		if(ob_get_level() > 0){
			@ob_clean();
		}
		ob_start();
	}
	protected function responseCleanAfterAction($action=''){
		if(ob_get_level() <= 0) return;
		$out = ob_get_clean();
		if($out !== ''){
			$this->lastError = $action ? $action.' unexpected output suppressed' : 'unexpected output suppressed';
		}
	}
}
