<?php

/**
 * Nextcloud Desktop compatible sync endpoint.
 * This plugin intentionally handles only the Nextcloud/WebDAV compatibility
 * routes and leaves the classic WebDAV UI/mounting feature to plugins/webdav.
 */
class webdavNextcloudPlugin extends PluginBase{
	protected $dav;
	function __construct(){
		parent::__construct();
	}
	public function regist(){
		$this->hookRegist(array(
			'globalRequest'					=> 'webdavNextcloudPlugin.route',
		));
	}

	public function route(){
		if(!$this->isNextcloudCompatRequest()) return;
		$this->_checkConfig();
		if($this->routeNextcloudCompat()) exit;
	}
	private function isNextcloudCompatRequest(){
		if(isset($_GET['_nc_route'])) return true;
		$uri = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
		$uri = $this->nextcloudNormalizeCompatUri($uri);
		$base = parse_url(APP_HOST,PHP_URL_PATH);
		if($base && $base != '/' && substr($uri,0,strlen($base)) == $base){
			$uri = substr($uri,strlen($base) - 1);
		}
		for($i = 0;$i < 2;$i++){
			if(substr($uri,0,11) == '/index.php/') $uri = substr($uri,10);
			if(substr($uri,0,11) == '/nextcloud/') $uri = substr($uri,10);
		}
		return $uri == '/status.php' ||
			$uri == '/204' ||
			substr($uri,0,10) == '/login/v2' ||
			preg_match('#^/ocs/v[12]\.php/#',$uri) ||
			preg_match('#^/remote\.php/dav/(files|uploads|avatars)/#',$uri) ||
			preg_match('#^/(index\.php/)?f/[0-9]+#',$uri) ||
			preg_match('#^/(index\.php/)?apps/activity/#',$uri) ||
			preg_match('#^/(index\.php/)?apps/terms_of_service/#',$uri);
	}
	private function routeNextcloudCompat(){
		$uri = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
		$uri = $this->nextcloudNormalizeCompatUri($uri);
		$base = parse_url(APP_HOST,PHP_URL_PATH);
		if($base && $base != '/' && substr($uri,0,strlen($base)) == $base){
			$uri = substr($uri,strlen($base) - 1);
		}
		if(isset($_GET['_nc_route'])){
			if($_GET['_nc_route'] == 'login_poll') return $this->nextcloudLoginPoll();
			if($_GET['_nc_route'] == 'login_flow'){
				$token = isset($_GET['token']) ? $_GET['token'] : '';
				return $this->nextcloudLoginFlow($token);
			}
		}
		$compatPrefix = '';
		for($i = 0;$i < 2;$i++){
			if(substr($uri,0,11) == '/index.php/'){
				$uri = substr($uri,10);
			}
			if(substr($uri,0,11) == '/nextcloud/'){
				$compatPrefix = '/nextcloud';
				$uri = substr($uri,10);
			}
		}
		if($uri == '/status.php') return $this->nextcloudStatus();
		if($uri == '/204') return $this->nextcloudStatusCode(204);
		if($uri == '/login/v2'){
			return $this->nextcloudLoginStart();
		}
		if($uri == '/login/v2/poll'){
			return $this->nextcloudLoginPoll();
		}
		if(preg_match('#^/login/v2/flow/([A-Za-z0-9]+)$#',$uri,$match)){
			return $this->nextcloudRedirect($this->nextcloudUrl('status.php?_nc_route=login_flow&token='.rawurlencode($match[1])),302);
		}
		if(preg_match('#^/ocs/v[12]\.php/cloud/capabilities/?$#',$uri)){
			return $this->nextcloudCapabilities();
		}
		if(preg_match('#^/ocs/v[12]\.php/cloud/user/?$#',$uri)){
			return $this->nextcloudUser();
		}
		if(preg_match('#^/ocs/v[12]\.php/core/getapppassword/?$#',$uri)){
			return $this->nextcloudAppPassword();
		}
		if(preg_match('#^/ocs/v[12]\.php/core/apppassword/?$#',$uri)){
			return $this->nextcloudDeleteAppPassword();
		}
		if(preg_match('#^/ocs/v[12]\.php/apps/files_sharing/api/v1/(shares|sharees|sharees_recommended|deletedshares|remote_shares)(?:/.*)?$#',$uri)){
			return $this->nextcloudSharingApi($uri);
		}
		if(preg_match('#^/f/([0-9]+)(?:/.*)?$#',$uri,$match)){
			return $this->nextcloudOpenInBrowser($match[1]);
		}
		if(preg_match('#^/ocs/v[12]\.php/apps/activity/api/v2/activity(?:/.*)?$#',$uri)){
			return $this->nextcloudActivityApi();
		}
		if(preg_match('#^/apps/activity/#',$uri)){
			return $this->nextcloudActivityPage();
		}
		if(preg_match('#^/ocs/v[12]\.php/apps/terms_of_service/terms/?$#',$uri)){
			return $this->nextcloudOcsError(404,'Terms of service is not enabled.');
		}
		if(preg_match('#^/index\.php/apps/terms_of_service/#',$uri) || preg_match('#^/apps/terms_of_service/#',$uri)){
			return $this->nextcloudStatusCode(404);
		}
		if(preg_match('#^/ocs/v[12]\.php/core/navigation/apps/?$#',$uri)){
			return $this->nextcloudNavigationApps();
		}
		if(preg_match('#^/remote\.php/dav/avatars/[^/]+/[0-9]+\.png$#',$uri)){
			return $this->nextcloudAvatar();
		}
		if(preg_match('#^/remote\.php/dav/uploads/([^/]*)(/.*)?$#',$uri,$match)){
			if(!$match[1]){HttpAuth::error();}
			if(!defined('KOD_NEXTCLOUD_COMPAT')) define('KOD_NEXTCLOUD_COMPAT',1);
			return $this->nextcloudChunkRoute($match[1],isset($match[2]) ? $match[2] : '/');
		}
		if(preg_match('#^/remote\.php/dav/files/([^/]*)(/.*)?$#',$uri,$match)){
			if(!$match[1]){HttpAuth::error();}
			if(!$this->nextcloudAuthUser($match[1])) return true;
			if($this->nextcloudLegacyChunkRoute($match[1],isset($match[2]) ? $match[2] : '/')) return true;
			if($_SERVER['REQUEST_METHOD'] == 'PUT'){
				if(!defined('KOD_NEXTCLOUD_COMPAT')) define('KOD_NEXTCLOUD_COMPAT',1);
				return $this->nextcloudFilePutRoute($match[1],isset($match[2]) ? $match[2] : '/');
			}
			if($_SERVER['REQUEST_METHOD'] == 'HEAD'){
				if(!defined('KOD_NEXTCLOUD_COMPAT')) define('KOD_NEXTCLOUD_COMPAT',1);
				if($this->nextcloudMobileHeadRoute($match[1],isset($match[2]) ? $match[2] : '/')) return true;
			}
			$davPre = $compatPrefix.'/remote.php/dav/files/'.$match[1].'/';
			if(!defined('KOD_NEXTCLOUD_COMPAT')) define('KOD_NEXTCLOUD_COMPAT',1);
			$this->run($davPre);exit;
		}
		return false;
	}
	private function nextcloudMobileHeadRoute($user,$path){
		if(!$this->nextcloudIsMobileClient()) return false;
		$destRel = '/'.trim(rawurldecode($path),'/');
		if(!$this->nextcloudMobileNakedPersonalRel($destRel)) return false;
		require_once($this->pluginPath.'php/webdavServer.class.php');
		require_once($this->pluginPath.'php/webdavServerKod.class.php');
		$dav = new webdavServerKod('/remote.php/dav/files/'.$user.'/');
		$kodPath = rtrim(MY_HOME,'/').'/'.trim($destRel,'/');
		$info = $this->nextcloudSafeInfoFull($kodPath);
		$this->log('nextcloud mobile naked HEAD: user='.$user.';rel='.$destRel.';path='.$kodPath.';exists='.($info ? 1 : 0).';size='.intval(_get($info,'size')));
		if(!$info){
			return $this->nextcloudRawResponse(404,array('Content-Length: 0','X-DAV-BY: kodbox-nextcloud-mobile-head'));
		}
		$headers = array('X-DAV-BY: kodbox-nextcloud-mobile-head');
		$etag = md5(implode('|',array(_get($info,'sourceID'),_get($info,'type'),_get($info,'modifyTime'),_get($info,'size'),_get($info,'fileInfo.hashMd5'))));
		$headers[] = 'ETag: "'.$etag.'"';
		$headers[] = 'OC-ETag: "'.$etag.'"';
		$headers[] = 'Last-Modified: '.gmdate('D, d M Y H:i:s ',intval(_get($info,'modifyTime',time()))).'GMT';
		if(_get($info,'type') == 'folder' || intval(_get($info,'isFolder')) == 1){
			$headers[] = 'Content-Type: httpd/unix-directory';
			$headers[] = 'Content-Length: 0';
		}else{
			$headers[] = 'Accept-Ranges: bytes';
			$headers[] = 'Content-Type: '.get_file_mime(_get($info,'ext'));
			$headers[] = 'Content-Length: '.intval(_get($info,'size'));
		}
		return $this->nextcloudRawResponse(200,$headers);
	}
	private function nextcloudMobileNakedPersonalRel($destRel){
		$rel = trim($destRel,'/');
		if(!$rel) return false;
		$first = explode('/',$rel)[0];
		$reserved = array(
			LNG('explorer.toolbar.rootPath') => true,
			LNG('explorer.toolbar.myGroup') => true,
			LNG('explorer.toolbar.fav') => true,
		);
		if(isset($reserved[$first])) return false;
		return true;
	}
	private function nextcloudFilePutRoute($user,$path){
		ignore_user_abort(true);
		@set_time_limit(0);
		$this->nextcloudPutShutdownRegister();
		require_once($this->pluginPath.'php/webdavServer.class.php');
		require_once($this->pluginPath.'php/webdavServerKod.class.php');
		$dav = new webdavServerKod('/remote.php/dav/files/'.$user.'/');
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'] = array(
			'active' => true,
			'user' => $user,
			'path' => $path,
			'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
			'size' => isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0,
			'targetPath' => '',
			'targetName' => get_path_this('/'.trim(rawurldecode($path),'/')),
			'existsBefore' => false,
			'time' => time(),
		);
		$this->log('nextcloud file put route start: user='.$user.';path='.$path.';size='._get($GLOBALS,'KOD_NEXTCLOUD_PUT_FALLBACK.size').';uri='._get($GLOBALS,'KOD_NEXTCLOUD_PUT_FALLBACK.uri'));
		$oldErrorReporting = error_reporting();
		$oldDisplayErrors = ini_get('display_errors');
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		@ini_set('display_errors','0');
		error_reporting($oldErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		try{
			return $this->nextcloudFilePutRouteInner($user,$path);
		}catch(Throwable $e){
			$this->log('nextcloud file put route exception captured: user='.$user.';path='.$path.';error='.$e->getMessage());
			return $this->nextcloudFilePutExceptionResponse($e);
		}finally{
			error_reporting($oldErrorReporting);
			@ini_set('display_errors',$oldDisplayErrors);
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		}
		try{
			$dav->checkUser();
			$dav->initPath('/remote.php/dav/files/'.$user.'/');
			$targetPath = $this->nextcloudDavTargetPath($dav,$path);
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['targetPath'] = $targetPath;
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['targetName'] = $targetPath ? get_path_this($targetPath) : $GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['targetName'];
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['existsBefore'] = $targetPath ? !!$this->nextcloudSafeInfoFull($targetPath) : false;
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = false;
			$GLOBALS['KOD_WEBDAV_SYNC_UPLOAD'] = true;
			$result = $dav->httpPUT();
			unset($GLOBALS['KOD_WEBDAV_SYNC_UPLOAD']);
			if($result){
				$dav->response($result);
			}
			return true;
		}catch(Throwable $e){
			unset($GLOBALS['KOD_WEBDAV_SYNC_UPLOAD']);
			$ctx = isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']) ? $GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'] : array();
			$targetPath = isset($ctx['targetPath']) ? $ctx['targetPath'] : '';
			$size = isset($ctx['size']) ? intval($ctx['size']) : 0;
			$info = $targetPath ? $this->nextcloudSafeInfoFull($targetPath) : false;
			if(!$info && $targetPath){
				$info = $this->nextcloudFindUploadedInfo($targetPath,isset($ctx['targetName']) ? $ctx['targetName'] : get_path_this($targetPath),$size);
			}
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = false;
			$this->log('nextcloud file put exception: '.$e->getMessage().';file='.$e->getFile().';line='.$e->getLine());
			if($info && (!$size || intval(_get($info,'size')) == $size)){
				$etag = $this->nextcloudUploadedEtag($targetPath,$info);
				$code = !empty($ctx['existsBefore']) ? 204 : 201;
				return $this->nextcloudRawResponse($code,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-put-exception-ok'));
			}
			return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: exception'));
		}
	}
	private function nextcloudFilePutExceptionResponse($e){
		$ctx = isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']) ? $GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'] : array();
		$targetPath = isset($ctx['targetPath']) ? $ctx['targetPath'] : '';
		$targetName = isset($ctx['targetName']) ? $ctx['targetName'] : get_path_this($targetPath);
		$size = isset($ctx['size']) ? intval($ctx['size']) : 0;
		$info = $targetPath ? $this->nextcloudFindUploadedInfo($targetPath,$targetName,$size) : false;
		$ok = $this->nextcloudUploadInfoMatches($info,$targetName,$size);
		$json = $this->nextcloudJsonFromOutput($e->getMessage());
		if(!$ok && $targetPath){
			$bodyFile = $this->nextcloudRequestBodyFile($size);
			if($bodyFile && is_file($bodyFile)){
				$this->log('nextcloud file put exception body-file fallback: target='.$targetPath.';file='.$bodyFile.';size='.@filesize($bodyFile).';expect='.$size);
				$fallback = $this->nextcloudUploadLocalFile($targetPath,$bodyFile);
				if($fallback){
					$info = $this->nextcloudFindUploadedInfo($targetPath,$targetName,$size);
					$ok = $this->nextcloudUploadInfoMatches($info,$targetName,$size);
				}
			}
		}
		$this->log('nextcloud file put exception verify: ok='.($ok ? 1 : 0).';target='.$targetPath.';targetName='.$targetName.';size='.$size.';remoteSize='.(is_array($info) ? intval(_get($info,'size')) : -1).';json='.($json ? json_encode($json) : '').';lastError='.json_encode(IO::getLastError()));
		if(!$ok){
			return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: put-exception'));
		}
		$path = _get($info,'path',$targetPath);
		$version = $this->nextcloudBumpEtag($path);
		if($version) $info['metaInfo']['webdavEtag'] = $version;
		$this->nextcloudBumpEtag(IO::pathFather($path));
		$etag = $this->nextcloudUploadedEtag($path,$info);
		$code = !empty($ctx['existsBefore']) ? 204 : 201;
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = false;
		return $this->nextcloudRawResponse($code,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-put-exception'));
	}
	private function nextcloudDavTargetPath($dav,$path){
		$destRel = '/'.trim(rawurldecode($path),'/');
		$mobileRoot = $this->nextcloudMobileRootUploadRel($destRel);
		if($this->nextcloudCompatRootWriteRelBlocked($destRel) && !$mobileRoot){
			$this->log('nextcloud target path root write blocked: destRel='.$destRel);
			return '';
		}
		$targetName = get_path_this($destRel);
		$parentRel = get_path_father($destRel);
		$parentPath = $mobileRoot ? MY_HOME : $dav->parsePath($parentRel ? $parentRel : '/');
		$parentPath = $this->nextcloudMobilePersonalParentPath($parentPath,$parentRel);
		if(!$targetName || !$parentPath){
			$this->log('nextcloud target path parse failed: destRel='.$destRel.';parentRel='.$parentRel.';parentPath='.$parentPath);
			return '';
		}
		return rtrim($parentPath,'/').'/'.$targetName;
	}
	private function nextcloudFilePutRouteInner($user,$path){
		$authUser = $this->nextcloudAuthUser($user);
		if(!$authUser) return true;
		require_once($this->pluginPath.'php/webdavServer.class.php');
		require_once($this->pluginPath.'php/webdavServerKod.class.php');
		$dav = new webdavServerKod('/remote.php/dav/files/'.$user.'/');
		$destRel = '/'.trim(rawurldecode($path),'/');
		$mobileRoot = $this->nextcloudMobileRootUploadRel($destRel);
		if($this->nextcloudCompatRootWriteRelBlocked($destRel) && !$mobileRoot){
			$this->log('nextcloud file put root write blocked: user='.$user.';destRel='.$destRel);
			return $this->nextcloudRawResponse(403,array('Content-Length: 0','X-DAV-ERROR: nextcloud-root-is-readonly'));
		}
		$targetName = get_path_this($destRel);
		$parentRel = get_path_father($destRel);
		$parentPath = $mobileRoot ? MY_HOME : $dav->parsePath($parentRel ? $parentRel : '/');
		$parentPath = $this->nextcloudMobilePersonalParentPath($parentPath,$parentRel);
		if(!$targetName || !$parentPath){
			$this->log('nextcloud file put path parse failed: user='.$user.';destRel='.$destRel.';parentRel='.$parentRel.';parentPath='.$parentPath);
			return $this->nextcloudRawResponse(409,array('Content-Length: 0'));
		}
		$targetPath = rtrim($parentPath,'/').'/'.$targetName;
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['targetPath'] = $targetPath;
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['targetName'] = $targetName;
		$targetInfo = $this->nextcloudSafeInfoFull($targetPath);
		$existsBefore = !!$targetInfo;
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['existsBefore'] = $existsBefore;
		$canParentEdit = $this->nextcloudDavCan($dav,$parentPath,'edit');
		$canTargetEdit = $targetInfo ? $this->nextcloudDavCan($dav,$targetPath,'edit') : false;
		if(!$canParentEdit && (!$targetInfo || !$canTargetEdit)){
			$this->log('nextcloud file put permission denied: targetPath='.$targetPath.';parentPath='.$parentPath);
			return $this->nextcloudRawResponse(403,array('Content-Length: 0'));
		}
		$timeStart = microtime(true);
		$expectSize = $this->nextcloudRequestBodySize();
		$bodyFile = $this->nextcloudRequestBodyFile($expectSize);
		$usingBodyFile = !!$bodyFile;
		if($bodyFile){
			$tmp = $bodyFile;
			$tmpDir = rtrim(dirname($tmp),'/').'/';
			$writeSize = @filesize($tmp);
			$timeRecv = microtime(true);
			$this->nextcloudDiagLog('nextcloud file put using nginx body file: file='.$tmp.';size='.$writeSize.';expect='.$expectSize);
		}else{
			$tmpDir = $this->nextcloudUploadTempDir($parentPath);
			$tmp = $tmpDir.'nextcloud_put_'.rand_string(32);
			$out = @fopen($tmp,'wb');
			$in = @fopen('php://input','rb');
			if(!$out || !$in){
				$error = error_get_last();
				$this->log('nextcloud file put temp open failed: targetPath='.$targetPath.';tmp='.$tmp.';tempDir='.$tmpDir.';out='.($out ? 1 : 0).';in='.($in ? 1 : 0).';isDir='.(is_dir($tmpDir) ? 1 : 0).';writable='.(is_writable($tmpDir) ? 1 : 0).';error='.json_encode($error));
				if($out) fclose($out);
				if($in) fclose($in);
				@unlink($tmp);
				return $this->nextcloudRawResponse(500,array('Content-Length: 0','X-DAV-ERROR: temp-open-failed'));
			}
			$writeSize = 0;
			$readError = !$this->nextcloudStreamCopy($in,$out,$writeSize);
			$timeRecv = microtime(true);
			fclose($in);fclose($out);
			if($readError){
				$this->log('nextcloud file put temp write failed: targetPath='.$targetPath.';tmp='.$tmp.';size='.@filesize($tmp).';written='.$writeSize.';error='.json_encode(error_get_last()));
				@unlink($tmp);
				return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: temp-write-failed'));
			}
		}
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['size'] = $expectSize;
		if($expectSize > 0 && @filesize($tmp) != $expectSize){
			$this->log('nextcloud file put size mismatch: targetPath='.$targetPath.';expect='.$expectSize.';actual='.@filesize($tmp));
			if(!$usingBodyFile) @unlink($tmp);
			return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: body-size-mismatch'));
		}
		$tmpSize = @filesize($tmp);
		$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['size'] = $tmpSize;
		$result = $this->nextcloudUploadLocalFile($targetPath,$tmp);
		$timeImport = microtime(true);
		@unlink($tmp);
		if(!$result){
			$targetInfoAfter = $this->nextcloudFindUploadedInfo($targetPath,$targetName,$tmpSize);
			if($targetInfoAfter){
				$result = $targetInfoAfter['path'];
				$this->log('nextcloud file put upload returned false but target exists: targetPath='.$targetPath.';size='.$tmpSize);
			}else{
				$this->log('nextcloud file put upload failed: targetPath='.$targetPath.';size='.$tmpSize);
				return $this->nextcloudRawResponse(503,array('Content-Length: 0'));
			}
		}
		$uploadInfo = $this->nextcloudVerifiedUploadInfo($result,$targetPath,$targetName,$tmpSize);
		if(!$uploadInfo){
			$this->log('nextcloud file put verify failed: targetPath='.$targetPath.';result='.$result.';tmpSize='.$tmpSize);
			return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: verify-failed'));
		}
		$mtime = _get($_SERVER,'HTTP_X_OC_MTIME');
		if($mtime && intval($mtime) > 1000 && intval($mtime) <= time() + 86400){
			try{
				IO::setModifyTime($uploadInfo['path'],intval($mtime));
			}catch(Throwable $e){
				$this->log('nextcloud file put mtime exception: '.$e->getMessage().';path='.$uploadInfo['path']);
			}
			$uploadInfo['modifyTime'] = intval($mtime);
		}
		$version = $this->nextcloudBumpEtag($uploadInfo['path']);
		if($version) $uploadInfo['metaInfo']['webdavEtag'] = $version;
		$this->nextcloudBumpEtag(IO::pathFather($uploadInfo['path']));
		$etag = $this->nextcloudUploadedEtag($uploadInfo['path'],$uploadInfo);
		$this->nextcloudDiagLog('nextcloud file put timing: path='.$uploadInfo['path'].';size='.$tmpSize.';tmpDir='.$tmpDir.';bodyFile='.($usingBodyFile ? 1 : 0).';recv='.round($timeRecv - $timeStart,3).'s;import='.round($timeImport - $timeRecv,3).'s;total='.round(microtime(true) - $timeStart,3).'s');
		$code = $existsBefore ? 204 : 201;
		$this->log('nextcloud file put success: path='.$uploadInfo['path'].';size='.$tmpSize.';code='.$code);
		return $this->nextcloudRawResponse($code,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-put'));
	}
	private function nextcloudNormalizeCompatUri($uri){
		$pos = strrpos($uri,'/index.php/login/v2/');
		if($pos !== false) return substr($uri,$pos + strlen('/index.php'));
		$pos = strrpos($uri,'/login/v2/');
		if($pos !== false) return substr($uri,$pos);
		return $uri;
	}
	private function nextcloudCompatRootWriteRelBlocked($destRel){
		$items = explode('/',trim($destRel,'/'));
		if(!trim($destRel,'/')) return true;
		return count($items) <= 1;
	}
	private function nextcloudMobileRootUploadRel($destRel){
		if(!$this->nextcloudCompatRootWriteRelBlocked($destRel)) return false;
		$name = trim($destRel,'/');
		if(!$name || strpos($name,'/') !== false) return false;
		return $this->nextcloudIsMobileUploadClient();
	}
	private function nextcloudMobilePersonalParentPath($parentPath,$parentRel){
		if(!$this->nextcloudIsMobileUploadClient()) return $parentPath;
		if($parentPath){
			$home = rtrim(MY_HOME,'/').'/';
			if(strpos(rtrim($parentPath,'/').'/', $home) === 0 && !IO::exist($parentPath)){
				return IO::mkdir($parentPath) ? $parentPath : false;
			}
			return $parentPath;
		}
		$rel = trim($parentRel,'/');
		if(!$rel) return MY_HOME;
		$path = rtrim(MY_HOME,'/').'/'.$rel;
		return IO::mkdir($path) ? $path : false;
	}
	private function nextcloudIsMobileUploadClient(){
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
		if($method != 'PUT' && $method != 'MKCOL') return false;
		return $this->nextcloudIsMobileClient();
	}
	private function nextcloudIsMobileClient(){
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		return !!preg_match('/(android|ios|iphone|ipad|mobile|nextcloud-android|nextcloud-ios|nextcloudphotos|nextcloud.*mobile)/i',$ua);
	}
	private function nextcloudDavCan($dav,$path,$action){
		if(!$path) return false;
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		try{
			return !!$dav->can($path,$action);
		}catch(Throwable $e){
			$this->log('nextcloud dav can exception: path='.$path.';action='.$action.';error='.$e->getMessage());
			return false;
		}finally{
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		}
	}
	private function nextcloudSafeInfoFull($path){
		if(!$path) return false;
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		try{
			$info = IO::infoFull($path);
			return is_array($info) ? $info : false;
		}catch(Throwable $e){
			$json = $this->nextcloudJsonFromOutput($e->getMessage());
			$msg = is_array($json) ? _get($json,'data','') : $e->getMessage();
			if($msg && strpos($msg,'不存在') === false && stripos($msg,'not') === false){
				$this->log('nextcloud safe info exception: path='.$path.';error='.$e->getMessage());
			}
			return false;
		}finally{
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		}
	}
	private function nextcloudLegacyChunkRoute($user,$path){
		$method = $_SERVER['REQUEST_METHOD'];
		if($method != 'PUT' && $method != 'DELETE') return false;
		$chunk = $this->nextcloudLegacyChunkInfo($path);
		if(!$chunk) return false;
		$authUser = $this->nextcloudAuthUser($user);
		if(!$authUser) return true;
		$dir = $this->nextcloudLegacyChunkDir($user,$chunk['transferID']);
		if($method == 'DELETE'){
			$this->nextcloudChunkRemove($dir);
			return $this->nextcloudRawResponse(204,array('Content-Length: 0'));
		}
		mk_dir($dir);
		$file = $dir.$chunk['index'].'.part';
			$out = @fopen($file,'wb');
			$in = @fopen('php://input','rb');
			if(!$out || !$in) return $this->nextcloudRawResponse(500,array('Content-Length: 0'));
			$copyOk = $this->nextcloudStreamCopy($in,$out);
			fclose($in);fclose($out);
			if(!$copyOk) return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: chunk-write-failed'));
			$expectSize = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
			if($expectSize > 0 && @filesize($file) != $expectSize){
				$actualSize = @filesize($file);
			@unlink($file);
			$this->log('nextcloud legacy chunk size mismatch: path='.$path.';expect='.$expectSize.';actual='.$actualSize);
			return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: chunk-size-mismatch'));
		}
		$this->log('nextcloud legacy chunk stored: path='.$path.';dest='.$chunk['destRel'].';index='.$chunk['index'].';total='.$chunk['total'].';transfer='.$chunk['transferID']);
		if($this->nextcloudLegacyChunksComplete($dir,$chunk['total'])){
			return $this->nextcloudLegacyChunkAssemble($user,$chunk,$dir);
		}
		$etag = $this->nextcloudFileEtag($file);
		return $this->nextcloudRawResponse(201,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-legacy-chunk'));
	}
	private function nextcloudLegacyChunkInfo($path){
		$path = rawurldecode($path);
		$path = '/'.ltrim($path,'/');
		$name = basename($path);
		if(!preg_match('/^(.+)-chunking-(.+)-([0-9]+)-([0-9]+)$/',$name,$match)) return false;
		$numA = intval($match[3]);
		$numB = intval($match[4]);
		if($numA <= $numB){
			$index = $numA;
			$total = $numB;
		}else{
			$total = $numA;
			$index = $numB;
		}
		if($total <= 1 || $index < 0) return false;
		$pos = strrpos($path,'/');
		$parent = $pos === false ? '' : substr($path,0,$pos);
		$parent = $parent == '/' ? '' : rtrim($parent,'/');
		return array(
			'path' => $path,
			'name' => $match[1],
			'transferID' => $match[2],
			'index' => $index,
			'total' => $total,
			'destRel' => ($parent ? $parent : '').'/'.$match[1],
		);
	}
	private function nextcloudLegacyChunkDir($user,$transferID){
		$key = md5($user.'|legacy|'.$transferID);
		return rtrim(TEMP_FILES,'/').'/nextcloud_chunks/'.$key.'/';
	}
	private function nextcloudLegacyChunksComplete($dir,$total){
		$zeroBased = true;
		for($i = 0;$i < $total;$i++){
			if(!is_file($dir.$i.'.part')){$zeroBased = false;break;}
		}
		if($zeroBased) return true;
		for($i = 1;$i <= $total;$i++){
			if(!is_file($dir.$i.'.part')) return false;
		}
		return true;
	}
	private function nextcloudLegacyChunkAssemble($user,$chunk,$dir){
		if(!is_dir(TEMP_FILES)) mk_dir(TEMP_FILES);
		$tmp = TEMP_FILES.'nextcloud_upload_'.rand_string(32);
		$out = @fopen($tmp,'wb');
		if(!$out) return $this->nextcloudRawResponse(500);
		$start = is_file($dir.'0.part') ? 0 : 1;
		for($i = $start;$i < $start + $chunk['total'];$i++){
			$file = $dir.$i.'.part';
			$in = @fopen($file,'rb');
			if(!$in){fclose($out);@unlink($tmp);return $this->nextcloudRawResponse(409);}
			$copyOk = $this->nextcloudStreamCopy($in,$out);
			fclose($in);
			if(!$copyOk){fclose($out);@unlink($tmp);return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: legacy-chunk-merge-failed'));}
		}
		fclose($out);
		$result = $this->nextcloudUploadMergedFile($user,$chunk['destRel'],$tmp);
		@unlink($tmp);
		if(!$result) return $this->nextcloudRawResponse(503);
		$this->nextcloudChunkRemove($dir);
		$etag = $this->nextcloudFinalizeUploadedEtag($result);
		return $this->nextcloudRawResponse(201,array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"','Content-Length: 0'));
	}
	private function nextcloudChunkRoute($user,$path){
		$authUser = $this->nextcloudAuthUser($user);
		if(!$authUser) return true;
		$path = trim($path,'/');
		$parts = $path === '' ? array() : explode('/',$path);
		$transferID = isset($parts[0]) ? $parts[0] : '';
		$itemName = count($parts) > 1 ? implode('/',array_slice($parts,1)) : '';
		if(!$transferID) return $this->nextcloudUploadRootRoute($user);
		$dir = $this->nextcloudChunkDir($user,$transferID);
		$method = $_SERVER['REQUEST_METHOD'];
		if($method == 'MKCOL'){
			if(empty($_SERVER['HTTP_DESTINATION'])) return $this->nextcloudRawResponse(400,array('Content-Length: 0'));
			mk_dir($dir);
			$etag = $this->nextcloudTransferEtag($dir);
			return $this->nextcloudRawResponse(201,array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"','Content-Length: 0'));
		}
		if($method == 'DELETE'){
			$this->nextcloudChunkRemove($dir);
			return $this->nextcloudRawResponse(204);
		}
		if($method == 'PROPFIND'){
			return $this->nextcloudChunkPropfind($user,$transferID,$dir,$itemName);
		}
		if($method == 'PUT'){
			if(!$itemName) return $this->nextcloudRawResponse(400);
			if(empty($_SERVER['HTTP_DESTINATION'])) return $this->nextcloudRawResponse(400,array('Content-Length: 0'));
			mk_dir($dir);
			$file = $dir.$this->nextcloudSafeName($itemName);
			$out = @fopen($file,'wb');
			$in = @fopen('php://input','rb');
			if(!$out || !$in) return $this->nextcloudRawResponse(500);
			$copyOk = $this->nextcloudStreamCopy($in,$out);
			fclose($in);fclose($out);
			if(!$copyOk) return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: chunk-write-failed'));
			$etag = $this->nextcloudTransferEtag($dir);
			$chunkEtag = $this->nextcloudFileEtag($file);
			return $this->nextcloudRawResponse(201,array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"','X-OC-Chunk-ETag: "'.$chunkEtag.'"','Content-Length: 0'));
		}
		if($method == 'MOVE'){
			if($itemName != '.file') return $this->nextcloudRawResponse(400);
			return $this->nextcloudChunkMoveFinal($user,$dir);
		}
		return $this->nextcloudRawResponse(405);
	}
	private function nextcloudUploadRootRoute($user){
		$method = $_SERVER['REQUEST_METHOD'];
		if($method == 'OPTIONS'){
			return $this->nextcloudRawResponse(200,array(
				'DAV: 1, 3, extended-mkcol',
				'Allow: OPTIONS, PROPFIND, MKCOL, DELETE',
				'Content-Length: 0',
			));
		}
		if($method == 'PROPFIND'){
			$href = $this->nextcloudUrl('remote.php/dav/uploads/'.$user.'/');
			$body = '<D:multistatus xmlns:D="DAV:">'.$this->nextcloudChunkResponse($href,md5($href),0,true).'</D:multistatus>';
			return $this->nextcloudRawResponse(207,array('Content-Type: application/xml; charset=utf-8'),$body);
		}
		return $this->nextcloudRawResponse(405,array('Content-Length: 0'));
	}
	private function nextcloudChunkMoveFinal($user,$dir){
		$dest = isset($_SERVER['HTTP_DESTINATION']) ? $_SERVER['HTTP_DESTINATION'] : '';
		$destRel = $this->nextcloudDestinationPath($dest,$user);
		if(!$destRel) return $this->nextcloudRawResponse(400);
		$chunks = $this->nextcloudChunkFiles($dir);
		if(!$chunks) return $this->nextcloudRawResponse(404);
		if(count($chunks) == 1){
			$uploadPath = $this->nextcloudUploadMergedFile($user,$destRel,$chunks[0]);
			if(!$uploadPath) return $this->nextcloudRawResponse(503);
			$this->nextcloudChunkRemove($dir);
			$etag = $this->nextcloudFinalizeUploadedEtag($uploadPath);
			return $this->nextcloudRawResponse(201,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-single-chunk'));
		}
		if(!is_dir(TEMP_FILES)) mk_dir(TEMP_FILES);
		$tmp = TEMP_FILES.'nextcloud_upload_'.rand_string(32);
		$out = @fopen($tmp,'wb');
		if(!$out) return $this->nextcloudRawResponse(500);
		foreach($chunks as $chunk){
			$in = @fopen($chunk,'rb');
			if(!$in){fclose($out);@unlink($tmp);return $this->nextcloudRawResponse(500);}
			$copyOk = $this->nextcloudStreamCopy($in,$out);
			fclose($in);
			if(!$copyOk){fclose($out);@unlink($tmp);return $this->nextcloudRawResponse(503,array('Content-Length: 0','X-DAV-ERROR: chunk-merge-failed'));}
		}
		fclose($out);
		$uploadPath = $this->nextcloudUploadMergedFile($user,$destRel,$tmp);
		@unlink($tmp);
		if(!$uploadPath) return $this->nextcloudRawResponse(503);
		$this->nextcloudChunkRemove($dir);
		$etag = $this->nextcloudFinalizeUploadedEtag($uploadPath);
		return $this->nextcloudRawResponse(201,$this->nextcloudUploadResponseHeaders($etag,'kodbox-nextcloud-chunk-merge'));
	}
	private function nextcloudUploadResponseHeaders($etag,$by=''){
		$headers = array(
			'ETag: "'.$etag.'"',
			'OC-ETag: "'.$etag.'"',
			'Content-Length: 0',
			'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
		);
		if(_get($_SERVER,'HTTP_X_OC_MTIME')){
			$headers[] = 'X-OC-MTime: accepted';
		}
		if($by) $headers[] = 'X-DAV-BY: '.$by;
		return $headers;
	}
	private function nextcloudFinalizeUploadedEtag($path){
		$info = $this->nextcloudSafeInfoFull($path);
		$version = $this->nextcloudBumpEtag($path);
		if(is_array($info) && $version) $info['metaInfo']['webdavEtag'] = $version;
		$this->nextcloudBumpEtag(IO::pathFather($path));
		return $this->nextcloudUploadedEtag($path,$info);
	}
	private function nextcloudUploadMergedFile($user,$destRel,$tmp){
		require_once($this->pluginPath.'php/webdavServer.class.php');
		require_once($this->pluginPath.'php/webdavServerKod.class.php');
		$dav = new webdavServerKod('/remote.php/dav/files/'.$user.'/');
		$destRel = '/'.trim($destRel,'/');
		$mobileRoot = $this->nextcloudMobileRootUploadRel($destRel);
		if($this->nextcloudCompatRootWriteRelBlocked($destRel) && !$mobileRoot){
			$this->log('nextcloud merged upload root write blocked: user='.$user.';destRel='.$destRel);
			return false;
		}
		$targetName = get_path_this($destRel);
		$parentRel = get_path_father($destRel);
		$parentPath = $mobileRoot ? MY_HOME : $dav->parsePath($parentRel ? $parentRel : '/');
		$parentPath = $this->nextcloudMobilePersonalParentPath($parentPath,$parentRel);
		if(!$targetName || !$parentPath){
			$this->log('nextcloud merged upload path parse failed: user='.$user.';destRel='.$destRel.';parentRel='.$parentRel.';parentPath='.$parentPath);
			return false;
		}
		$targetPath = rtrim($parentPath,'/').'/'.$targetName;
		$targetInfo = $this->nextcloudSafeInfoFull($targetPath);
		$uploadPath = $targetInfo ? $targetInfo['path'] : $targetPath;
		$canParentEdit = $this->nextcloudDavCan($dav,$parentPath,'edit');
		$canTargetEdit = $targetInfo ? $this->nextcloudDavCan($dav,$uploadPath,'edit') : false;
		if(!$canParentEdit && (!$targetInfo || !$canTargetEdit)){
			$this->log('nextcloud merged upload permission denied: uploadPath='.$uploadPath.';parentPath='.$parentPath.';destRel='.$destRel);
			return false;
		}
		$tmpSize = @filesize($tmp);
		$result = $this->nextcloudUploadLocalFile($uploadPath,$tmp);
		if(!$result){
			$targetInfoAfter = $this->nextcloudFindUploadedInfo($uploadPath,get_path_this($uploadPath),$tmpSize);
			if($targetInfoAfter){
				$result = $targetInfoAfter['path'];
				$this->log('nextcloud merged upload returned false but target exists: uploadPath='.$uploadPath.';size='.$tmpSize);
			}else{
				return false;
			}
		}
		$resultPath = is_string($result) ? $result : $uploadPath;
		$uploadInfo = $this->nextcloudVerifiedUploadInfo($resultPath,$uploadPath,get_path_this($uploadPath),$tmpSize);
		if(!$uploadInfo){
			$this->log('nextcloud merged upload verify failed: uploadPath='.$uploadPath.';resultPath='.$resultPath.';destRel='.$destRel.';tmpSize='.$tmpSize.';result='.json_encode($result));
			return false;
		}
		$mtime = _get($_SERVER,'HTTP_X_OC_MTIME');
		if($mtime && intval($mtime) > 1000 && intval($mtime) <= time() + 86400){
			try{
				IO::setModifyTime($uploadInfo['path'],intval($mtime));
			}catch(Throwable $e){
				$this->log('nextcloud merged upload mtime exception: '.$e->getMessage().';path='.$uploadInfo['path']);
			}
			$uploadInfo['modifyTime'] = intval($mtime);
		}
		$this->log('nextcloud merged upload success: uploadPath='.$uploadInfo['path'].';destRel='.$destRel.';size='.$tmpSize);
		return $uploadInfo['path'];
	}
	private function nextcloudStreamCopy($in,$out,&$writeSize = null){
		if($writeSize === null) $writeSize = 0;
		@stream_set_read_buffer($in,0);
		@stream_set_write_buffer($out,0);
		if(function_exists('stream_copy_to_stream')){
			while(!feof($in)){
				$written = @stream_copy_to_stream($in,$out,16 * 1024 * 1024);
				if($written === false) return false;
				if($written === 0){
					if(feof($in)) break;
					usleep(10000);
					continue;
				}
				$writeSize += $written;
			}
			return true;
		}
		$bufferSize = 16 * 1024 * 1024;
		while(!feof($in)){
			$data = fread($in,$bufferSize);
			if($data === false) return false;
			if($data === '') continue;
			$offset = 0;
			$length = strlen($data);
			while($offset < $length){
				$written = fwrite($out,substr($data,$offset));
				if($written === false || $written <= 0) return false;
				$offset += $written;
				$writeSize += $written;
			}
		}
		return true;
	}
	private function nextcloudRequestBodySize(){
		$size = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
		if(!$size && isset($_SERVER['KOD_NGINX_BODY_SIZE'])){
			$size = intval($_SERVER['KOD_NGINX_BODY_SIZE']);
		}
		if(!$size && isset($_SERVER['HTTP_KOD_NGINX_BODY_SIZE'])){
			$size = intval($_SERVER['HTTP_KOD_NGINX_BODY_SIZE']);
		}
		if(!$size && isset($_SERVER['HTTP_CONTENT_LENGTH'])){
			$size = intval($_SERVER['HTTP_CONTENT_LENGTH']);
		}
		return max(0,$size);
	}
	private function nextcloudRequestBodyFile($expectSize = 0){
		$file = isset($_SERVER['KOD_NGINX_BODY_FILE']) ? trim($_SERVER['KOD_NGINX_BODY_FILE']) : '';
		if(!$file && isset($_SERVER['HTTP_KOD_NGINX_BODY_FILE'])){
			$file = trim($_SERVER['HTTP_KOD_NGINX_BODY_FILE']);
		}
		if(!$file){
			$this->nextcloudDiagLog('nextcloud nginx body file missing: hasKOD='.(isset($_SERVER['KOD_NGINX_BODY_FILE']) ? 1 : 0).';hasHTTP='.(isset($_SERVER['HTTP_KOD_NGINX_BODY_FILE']) ? 1 : 0).';uri='._get($_SERVER,'REQUEST_URI'));
			return false;
		}
		if(strpos($file,"\0") !== false) return false;
		if(!is_file($file) || !is_readable($file)){
			$this->nextcloudDiagLog('nextcloud nginx body file unavailable: file='.$file.';exists='.(is_file($file) ? 1 : 0).';readable='.(is_readable($file) ? 1 : 0));
			return false;
		}
		$size = @filesize($file);
		if($expectSize > 0 && intval($size) != intval($expectSize)){
			$this->nextcloudDiagLog('nextcloud nginx body file size mismatch: file='.$file.';expect='.$expectSize.';actual='.$size);
			return false;
		}
		return $file;
	}
	private function nextcloudUploadTempDir($targetPath = ''){
		$tempPath = TEMP_FILES;
		$reason = 'default';
		try{
			$driverInfo = $targetPath ? KodIO::pathDriverType($targetPath) : false;
			if($driverInfo && _get($driverInfo,'type') == 'local'){
				$truePath = rtrim(_get($driverInfo,'path',''),'/').'/';
				$isSame = KodIO::isSameDisk($truePath,TEMP_FILES);
				$reason = 'local;truePath='.$truePath.';sameDisk='.($isSame ? 1 : 0);
				if(!$isSame && file_exists($truePath)){
					$pathRoot = $this->nextcloudPathMountRoot($truePath);
					$reason .= ';mountRoot='.$pathRoot;
					if($pathRoot){
						$tempUpload = rtrim($pathRoot,'/').'/tmp/.kod_nextcloud_upload_temp/';
						mk_dir($tempUpload);
						if(file_exists($tempUpload) && is_writable($tempUpload)){
							$tempPath = $tempUpload;
							$reason .= ';selected=mount-temp';
						}else{
							$reason .= ';mountTempWritable=0';
						}
					}
				}
			}else{
				$reason = $driverInfo ? 'driverType='._get($driverInfo,'type') : 'driverInfo=empty';
			}
		}catch(Throwable $e){
			$reason = 'exception='.$e->getMessage();
		}
		if(!file_exists($tempPath)){
			mk_dir($tempPath);
			@touch(rtrim($tempPath,'/').'/index.html');
		}
		$tempPath = rtrim($tempPath,'/').'/';
		$this->nextcloudDiagLog('nextcloud upload temp dir: targetPath='.$targetPath.';tempPath='.$tempPath.';reason='.$reason);
		return $tempPath;
	}
	private function nextcloudPathMountRoot($path){
		$path = realpath($path);
		if(!$path) return false;
		if(isset($GLOBALS['config']['systemOS']) && $GLOBALS['config']['systemOS'] == 'windows'){
			return preg_match('/^([A-Z]:)/i',$path,$match) ? $match[1] : false;
		}
		$stat = @stat($path);
		if(!$stat) return false;
		while(($parent = dirname($path)) !== $path){
			$parentStat = @stat($parent);
			if(!$parentStat) return $path;
			if(_get($parentStat,'dev') !== $stat['dev']) return $path;
			if($parent == '/') return $parent;
			if(!$parent) break;
			$path = $parent;
		}
		return false;
	}
	private function nextcloudDiagLog($message){
		if(!$this->nextcloudLogEnabled()) return;
		if(function_exists('write_log')){
			write_log('[NEXTCLOUD-COMPAT] '.$message,'webdavNextcloud');
			return;
		}
		$this->log($message);
	}
	private function nextcloudFindUploadedInfo($targetPath,$targetName,$size){
		$parentPath = IO::pathFather($targetPath);
		for($i = 0;$i < 20;$i++){
			$info = $this->nextcloudSafeInfoFull($targetPath);
			if($this->nextcloudUploadInfoMatches($info,$targetName,$size)) return $info;
			$list = Action('explorer.list')->path($parentPath);
			if(is_array($list) && is_array($list['fileList'])){
				foreach($list['fileList'] as $item){
					if(!$this->nextcloudUploadInfoMatches($item,$targetName,$size)) continue;
					$info = $this->nextcloudSafeInfoFull($item['path']);
					return $this->nextcloudUploadInfoMatches($info,$targetName,$size) ? $info : $item;
				}
			}
			usleep(200000);
		}
		return false;
	}
	private function nextcloudUploadInfoMatches($info,$targetName,$size){
		if(!$info || !is_array($info)) return false;
		$type = _get($info,'type');
		$isFolder = _get($info,'isFolder');
		if($type && $type != 'file') return false;
		if($isFolder !== '' && intval($isFolder) == 1) return false;
		$name = _get($info,'name');
		if(!$name) $name = _get($info,'sourceInfo.name');
		if(!$name && _get($info,'pathDisplay')) $name = get_path_this(_get($info,'pathDisplay'));
		if($targetName !== '' && $name !== '' && $name != $targetName) return false;
		if($targetName !== '' && $name === '') return false;
		if($size >= 0 && intval(_get($info,'size')) != intval($size)) return false;
		return true;
	}
	private function nextcloudVerifiedUploadInfo($resultPath,$targetPath,$targetName,$size){
		$paths = array();
		if($resultPath) $paths[] = $resultPath;
		if($targetPath && $targetPath != $resultPath) $paths[] = $targetPath;
		foreach($paths as $path){
			$info = $this->nextcloudSafeInfoFull($path);
			if($this->nextcloudUploadInfoMatches($info,$targetName,$size)) return $info;
		}
		return $this->nextcloudFindUploadedInfo($targetPath,$targetName,$size);
	}
	private function nextcloudSetUploadedMtime($targetPath,$targetName,$size){
		$mtime = _get($_SERVER,'HTTP_X_OC_MTIME');
		if(!$mtime) return false;
		$mtime = intval($mtime);
		if($mtime <= 1000 || $mtime > time() + 86400) return false;
		$info = $this->nextcloudFindUploadedInfo($targetPath,$targetName,$size);
		$path = $info && _get($info,'path') ? $info['path'] : $targetPath;
		try{
			IO::setModifyTime($path,$mtime);
		}catch(Throwable $e){
			$this->log('nextcloud set uploaded mtime exception: '.$e->getMessage().';path='.$path);
			return $info;
		}
		if(!$info) $info = $this->nextcloudSafeInfoFull($path);
		if(is_array($info)) $info['modifyTime'] = $mtime;
		return $info;
	}
	private function nextcloudUploadLocalFile($uploadPath,$localFile){
		$localSize = @filesize($localFile);
		$directResult = false;
		$isLocalFilesystemFile = $this->nextcloudIsLocalFilesystemFile($localFile);
		$isNginxBodyFile = $localFile && _get($_SERVER,'KOD_NGINX_BODY_FILE') && $localFile == _get($_SERVER,'KOD_NGINX_BODY_FILE');
		$this->log('nextcloud upload local enter: path='.$uploadPath.';local='.$localFile.';localSize='.$localSize.';isLocal=' . ($isLocalFilesystemFile ? 1 : 0).';exists='.(is_file($localFile) ? 1 : 0));
		if(!$directResult && !$isNginxBodyFile && is_file($localFile)){
			try{
				$directResult = $this->nextcloudUploadLocalFileFallback($uploadPath,$localFile,$localSize,'copy');
			}catch(Throwable $e){
				$this->log('nextcloud upload copy fallback escaped: path='.$uploadPath.';local='.$localFile.';error='.$e->getMessage());
				$directResult = false;
			}
		}
		if($directResult){
			return is_string($directResult) ? $directResult : $uploadPath;
		}
		if($isLocalFilesystemFile && is_file($localFile)){
			$canonicalResult = $this->nextcloudUploadLocalFileCanonical($uploadPath,$localFile,$localSize);
			if($canonicalResult){
				return is_string($canonicalResult) ? $canonicalResult : $uploadPath;
			}
			$saveResult = $this->nextcloudUploadLocalFileSaveFile($uploadPath,$localFile,$localSize);
			if($saveResult){
				return is_string($saveResult) ? $saveResult : $uploadPath;
			}
		}
		if(!$directResult && !$isNginxBodyFile && is_file($localFile)){
			try{
				$directResult = $this->nextcloudUploadLocalFileFallback($uploadPath,$localFile,$localSize,'move');
			}catch(Throwable $e){
				$this->log('nextcloud upload move fallback escaped: path='.$uploadPath.';local='.$localFile.';error='.$e->getMessage());
				$directResult = false;
			}
		}
		if($directResult){
			return is_string($directResult) ? $directResult : $uploadPath;
		}
		if(!is_file($localFile)){
			$this->log('nextcloud upload local source missing before io-upload: path='.$uploadPath.';local='.$localFile.';localSize='.$localSize);
			return false;
		}
		if($isNginxBodyFile){
			$this->log('nextcloud upload local nginx body fallback failed before io-upload: path='.$uploadPath.';local='.$localFile.';localSize='.$localSize);
			return false;
		}
		$oldUploadPath = array_key_exists('KOD_WEBDAV_UPLOAD_PATH',$GLOBALS) ? $GLOBALS['KOD_WEBDAV_UPLOAD_PATH'] : null;
		$oldCapture = array_key_exists('KOD_WEBDAV_CAPTURE_UPLOAD',$GLOBALS) ? $GLOBALS['KOD_WEBDAV_CAPTURE_UPLOAD'] : null;
		$oldNotExit = array_key_exists('SHOW_JSON_NOT_EXIT',$GLOBALS) ? $GLOBALS['SHOW_JSON_NOT_EXIT'] : null;
		$oldNotExitDone = array_key_exists('SHOW_JSON_NOT_EXIT_DONE',$GLOBALS) ? $GLOBALS['SHOW_JSON_NOT_EXIT_DONE'] : null;
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$oldPutFallbackActive = isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active']) ? $GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] : null;
		$oldInExists = array_key_exists('in',$GLOBALS);
		$oldIn = $oldInExists ? $GLOBALS['in'] : null;
		$uploadParent = rtrim(get_path_father($uploadPath),'/').'/';
		$uploadName = get_path_this($uploadPath);
		$GLOBALS['KOD_WEBDAV_UPLOAD_PATH'] = $uploadPath;
		$GLOBALS['KOD_WEBDAV_CAPTURE_UPLOAD'] = true;
		$GLOBALS['SHOW_JSON_NOT_EXIT'] = true;
		$GLOBALS['SHOW_OUT_EXCEPTION'] = false;
		if(!isset($GLOBALS['in']) || !is_array($GLOBALS['in'])){
			$GLOBALS['in'] = array();
		}
		$GLOBALS['in']['path'] = $uploadParent;
		$GLOBALS['in']['name'] = $uploadName;
		$GLOBALS['in']['size'] = intval($localSize);
		$GLOBALS['in']['fileRepeat'] = REPEAT_REPLACE;
		$GLOBALS['in']['repeatType'] = REPEAT_REPLACE;
		$ocMtime = intval(_get($_SERVER,'HTTP_X_OC_MTIME'));
		if($ocMtime > 1000){
			$GLOBALS['in']['modifyTime'] = $ocMtime;
		}
		if(isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'])){
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = false;
		}
		unset($GLOBALS['SHOW_JSON_NOT_EXIT_DONE']);
		$result = false;
		$output = '';
		$this->log('nextcloud upload local start: path='.$uploadPath.';parent='.$uploadParent.';name='.$uploadName.';local='.$localFile.';localSize='.$localSize.';showOut='.(isset($GLOBALS['SHOW_OUT_EXCEPTION']) && $GLOBALS['SHOW_OUT_EXCEPTION'] ? 1 : 0).';showJson='.(isset($GLOBALS['SHOW_JSON_NOT_EXIT']) && $GLOBALS['SHOW_JSON_NOT_EXIT'] ? 1 : 0));
		ob_start();
		try{
			$result = IO::upload($uploadPath,$localFile,true,REPEAT_REPLACE);
			$output = ob_get_clean();
		}catch(Throwable $e){
			$output = ob_get_level() > 0 ? ob_get_clean() : '';
			$output .= $e->getMessage();
			$json = $this->nextcloudJsonFromOutput($e->getMessage());
			if(is_array($json) && !empty($json['code'])){
				$result = $uploadPath;
			}else{
				$this->log('nextcloud upload local io-upload exception: path='.$uploadPath.';local='.$localFile.';error='.$e->getMessage());
			}
		}
		$this->nextcloudGlobalRestore('KOD_WEBDAV_UPLOAD_PATH',$oldUploadPath);
		$this->nextcloudGlobalRestore('KOD_WEBDAV_CAPTURE_UPLOAD',$oldCapture);
		$this->nextcloudGlobalRestore('SHOW_JSON_NOT_EXIT',$oldNotExit);
		$this->nextcloudGlobalRestore('SHOW_JSON_NOT_EXIT_DONE',$oldNotExitDone);
		$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		if($oldPutFallbackActive !== null && isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'])){
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = $oldPutFallbackActive;
		}
		if($oldInExists){
			$GLOBALS['in'] = $oldIn;
		}else{
			unset($GLOBALS['in']);
		}
		$json = $this->nextcloudJsonFromOutput($output);
		if(!$result && is_array($json) && !empty($json['code'])){
			$result = $uploadPath;
		}
		if($output !== ''){
			$this->log('nextcloud upload captured output: path='.$uploadPath.';parent='.$uploadParent.';name='.$uploadName.';len='.strlen($output).';success='.(is_array($json) && !empty($json['code']) ? 1:0).';json='.($json ? json_encode($json) : substr($output,0,500)).';lastError='.json_encode(IO::getLastError()));
		}
		if($result){
			$resultPath = is_string($result) ? $result : $uploadPath;
			$targetName = get_path_this($uploadPath);
			$info = $this->nextcloudVerifiedUploadInfo($resultPath,$uploadPath,$targetName,intval($localSize));
			if(!$info){
				$this->log('nextcloud upload local verify missed, fallback needed: path='.$uploadPath.';result='.$resultPath.';localSize='.$localSize.';lastError='.json_encode(IO::getLastError()));
				$result = false;
			}
		}
		if(!$result){
			if($isLocalFilesystemFile && is_file($localFile)){
				$saveResult = $this->nextcloudUploadLocalFileSaveFile($uploadPath,$localFile,$localSize);
				if($saveResult){
					return is_string($saveResult) ? $saveResult : $uploadPath;
				}
			}
			$this->log('nextcloud upload local failed: path='.$uploadPath.';parent='.$uploadParent.';name='.$uploadName.';local='.$localFile.';localSize='.$localSize.';exists='.(is_file($localFile) ? 1 : 0).';lastError='.json_encode(IO::getLastError()).';output='.substr($output,0,500));
		}
		return $result ? (is_string($result) ? $result : $uploadPath) : false;
	}
	private function nextcloudUploadLocalFileCanonical($uploadPath,$localFile,$localSize){
		$uploadParent = rtrim(get_path_father($uploadPath),'/').'/';
		$uploadName = get_path_this($uploadPath);
		$parentInfo = $this->nextcloudSafeInfoFull($uploadParent);
		$parentID = intval(_get($parentInfo,'sourceID'));
		if(!$parentID || !$uploadName) return false;
		$canonicalPath = '{source:'.$parentID.'}/'.$uploadName;
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$oldDisplayErrors = ini_get('display_errors');
		$oldErrorReporting = error_reporting();
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		@ini_set('display_errors','0');
		error_reporting($oldErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
		$output = '';
		$result = false;
		ob_start();
		try{
			$result = IO::upload($canonicalPath,$localFile,false,REPEAT_REPLACE);
			$output = ob_get_clean();
		}catch(Throwable $e){
			$output = ob_get_clean();
			$output .= $e->getMessage();
		}finally{
			error_reporting($oldErrorReporting);
			@ini_set('display_errors',$oldDisplayErrors);
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		}
		$resultPath = is_string($result) ? $result : ($result ? $canonicalPath : '');
		$info = $resultPath ? $this->nextcloudVerifiedUploadInfo($resultPath,$uploadPath,$uploadName,intval($localSize)) : false;
		$ok = $this->nextcloudUploadInfoMatches($info,$uploadName,intval($localSize));
		$this->log('nextcloud canonical upload: ok='.($ok ? 1 : 0).';path='.$uploadPath.';canonical='.$canonicalPath.';result='.(is_string($result) ? $result : ($result ? 1 : 0)).';local='.$localFile.';localSize='.$localSize.';remoteSize='.(is_array($info) ? intval(_get($info,'size')) : -1).';output='.substr($output,0,200).';lastError='.json_encode(IO::getLastError()));
		return $ok ? _get($info,'path',$resultPath) : false;
	}
	private function nextcloudUploadLocalFileSaveFile($uploadPath,$localFile,$localSize){
		$uploadParent = rtrim(get_path_father($uploadPath),'/').'/';
		$uploadName = get_path_this($uploadPath);
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		try{
			if(!IO::exist($uploadParent)){
				$parentMade = IO::mkdir($uploadParent);
				if(!$parentMade){
					$this->log('nextcloud saveFile parent mkdir failed: parent='.$uploadParent.';path='.$uploadPath.';lastError='.json_encode(IO::getLastError()));
					return false;
				}
			}
			$targetPath = $uploadPath;
			$targetInfo = IO::exist($targetPath) ? $this->nextcloudSafeInfoFull($targetPath) : false;
			$createdNew = false;
			if(!$targetInfo){
				$this->nextcloudSourceEventStop();
				try{
					$targetPath = IO::mkfile($uploadPath,'',REPEAT_REPLACE);
				}finally{
					$this->nextcloudSourceEventStart();
				}
				$createdNew = !!$targetPath;
				$targetInfo = $targetPath ? $this->nextcloudSafeInfoFull($targetPath) : false;
			}
			if(!$targetInfo || !_get($targetInfo,'path')){
				$this->log('nextcloud saveFile target create failed: path='.$uploadPath.';result='.$targetPath.';lastError='.json_encode(IO::getLastError()));
				return false;
			}
			$result = IO::saveFile($localFile,$targetInfo['path']);
			$info = $result ? $this->nextcloudVerifiedUploadInfo($targetInfo['path'],$uploadPath,$uploadName,intval($localSize)) : false;
			$ok = $this->nextcloudUploadInfoMatches($info,$uploadName,intval($localSize));
			$this->log('nextcloud saveFile upload: ok='.($ok ? 1 : 0).';path='.$uploadPath.';target='.$targetInfo['path'].';local='.$localFile.';localSize='.$localSize.';remoteSize='.(is_array($info) ? intval(_get($info,'size')) : -1).';result='.($result ? 1 : 0).';lastError='.json_encode(IO::getLastError()));
			if(!$ok && $createdNew && _get($targetInfo,'path')){
				IO::remove($targetInfo['path'],false);
			}
			return $ok ? _get($info,'path',$targetInfo['path']) : false;
		}catch(Throwable $e){
			$this->log('nextcloud saveFile upload exception: path='.$uploadPath.';local='.$localFile.';error='.$e->getMessage());
			return false;
		}finally{
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		}
	}
	private function nextcloudSourceEventStop(){
		try{
			Model('SourceEvent')->recodeStop();
		}catch(Throwable $e){
			$this->log('nextcloud source event stop exception: '.$e->getMessage());
		}
	}
	private function nextcloudSourceEventStart(){
		try{
			Model('SourceEvent')->recodeStart();
		}catch(Throwable $e){
			$this->log('nextcloud source event start exception: '.$e->getMessage());
		}
	}
	private function nextcloudIsLocalFilesystemFile($path){
		if(!$path || !is_string($path)) return false;
		if(strpos($path,'{') === 0 || strpos($path,'kodio://') === 0) return false;
		if(preg_match('/^[A-Za-z0-9_]+:\/\//',$path)) return false;
		return is_file($path) && (strpos($path,'/') === 0 || preg_match('/^[A-Za-z]:[\/\\\\]/',$path));
	}
	private function nextcloudUploadLocalFileFallback($uploadPath,$localFile,$localSize,$mode){
		$savePath = rtrim(get_path_father($uploadPath),'/').'/';
		$fileName = get_path_this($uploadPath);
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$oldErrorReporting = error_reporting();
		$oldDisplayErrors = ini_get('display_errors');
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		$result = false;
		$output = '';
		ob_start();
		@ini_set('display_errors','0');
		error_reporting($oldErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
		try{
			if(!IO::exist($savePath)){
				IO::mkdir($savePath);
			}
			if($mode == 'move'){
				$result = IO::move($localFile,$savePath,REPEAT_REPLACE,$fileName);
			}else{
				$result = IO::copy($localFile,$savePath,REPEAT_REPLACE,$fileName);
			}
			$output = ob_get_clean();
		}catch(Throwable $e){
			$output = ob_get_clean();
			$json = $this->nextcloudJsonFromOutput($e->getMessage());
			if(is_array($json) && !empty($json['code'])){
				$result = $uploadPath;
			}else{
				$output .= $e->getMessage();
			}
			error_reporting($oldErrorReporting);
			@ini_set('display_errors',$oldDisplayErrors);
			$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
			if(!$result){
				$this->log('nextcloud upload fallback exception: mode='.$mode.';path='.$uploadPath.';local='.$localFile.';json='.($json ? json_encode($json) : '').';error='.$e->getMessage());
				return false;
			}
		}
		error_reporting($oldErrorReporting);
		@ini_set('display_errors',$oldDisplayErrors);
		$this->nextcloudGlobalRestore('SHOW_OUT_EXCEPTION',$oldOutException);
		$resultPath = is_string($result) ? $result : $uploadPath;
		$info = $this->nextcloudVerifiedUploadInfo($resultPath,$uploadPath,$fileName,intval($localSize));
		$remoteSize = is_array($info) ? intval(_get($info,'size')) : -1;
		$ok = $result && $this->nextcloudUploadInfoMatches($info,$fileName,intval($localSize));
		$verifiedPath = $ok && _get($info,'path') ? _get($info,'path') : $resultPath;
		$this->log('nextcloud upload fallback: mode='.$mode.';ok='.($ok ? 1 : 0).';path='.$uploadPath.';result='.(is_string($result) ? $result : ($result ? 1 : 0)).';verified='.$verifiedPath.';verifiedType='._get($info,'type').';verifiedName='._get($info,'name').';local='.$localFile.';localSize='.$localSize.';remoteSize='.$remoteSize.';outputLen='.strlen($output).';output='.substr($output,0,200).';lastError='.json_encode(IO::getLastError()));
		return $ok ? $verifiedPath : false;
	}
	private function nextcloudAvatar(){
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l4WqKgAAAABJRU5ErkJggg==');
		return $this->nextcloudRawResponse(200,array(
			'Content-Type: image/png',
			'Cache-Control: public, max-age=86400',
			'Content-Length: '.strlen($png),
		),$png);
	}
	private function nextcloudPutShutdownRegister(){
		if(!empty($GLOBALS['KOD_NEXTCLOUD_PUT_SHUTDOWN_REGISTERED'])) return;
		$GLOBALS['KOD_NEXTCLOUD_PUT_SHUTDOWN_REGISTERED'] = true;
		register_shutdown_function(array('webdavNextcloudPlugin','nextcloudPutShutdownFallback'));
	}
	public static function nextcloudStaticSafeInfoFull($path){
		if(!$path) return false;
		$oldOutException = array_key_exists('SHOW_OUT_EXCEPTION',$GLOBALS) ? $GLOBALS['SHOW_OUT_EXCEPTION'] : null;
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		try{
			$info = IO::infoFull($path);
			return is_array($info) ? $info : false;
		}catch(Throwable $e){
			return false;
		}finally{
			if($oldOutException === null){
				unset($GLOBALS['SHOW_OUT_EXCEPTION']);
			}else{
				$GLOBALS['SHOW_OUT_EXCEPTION'] = $oldOutException;
			}
		}
	}
	public static function nextcloudPutShutdownFallback(){
		$ctx = isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']) ? $GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'] : false;
		if(!$ctx || empty($ctx['active'])) return;
		$targetPath = isset($ctx['targetPath']) ? $ctx['targetPath'] : '';
		$size = isset($ctx['size']) ? intval($ctx['size']) : 0;
		$info = $targetPath ? self::nextcloudStaticSafeInfoFull($targetPath) : false;
		$ok = $info && (!$size || intval(_get($info,'size')) == $size);
		$error = error_get_last();
		$sentFile = '';$sentLine = 0;
		$headersSent = headers_sent($sentFile,$sentLine);
		$msg = 'nextcloud put shutdown fallback: ok='.($ok ? 1 : 0).
			';target='.$targetPath.
			';user='.(isset($ctx['user']) ? $ctx['user'] : '').
			';path='.(isset($ctx['path']) ? $ctx['path'] : '').
			';size='.$size.
			';remoteSize='.intval(_get($info,'size')).
			';headersSent='.($headersSent ? 1 : 0).
			';sentAt='.$sentFile.':'.$sentLine.
			';responseCode='.http_response_code().
			';error='.json_encode($error);
		if(function_exists('write_log')) write_log($msg,'webdavNextcloud');
		if($headersSent) return;
		while(ob_get_level() > 0){@ob_end_clean();}
		header_remove();
		if($ok){
			$etag = md5(implode('|',array(_get($info,'sourceID'),_get($info,'type','file'),_get($info,'modifyTime'),_get($info,'size'))));
			http_response_code(!empty($ctx['existsBefore']) ? 204 : 201);
			header('ETag: "'.$etag.'"');
			header('OC-ETag: "'.$etag.'"');
			header('Content-Length: 0');
			header('X-DAV-BY: kodbox-nextcloud-put-shutdown');
			return;
		}
		http_response_code(503);
		header('Content-Length: 0');
		header('X-DAV-ERROR: shutdown');
	}
	private function nextcloudGlobalRestore($key,$value){
		if($value === null){
			unset($GLOBALS[$key]);
		}else{
			$GLOBALS[$key] = $value;
		}
	}
	private function nextcloudJsonFromOutput($output){
		$output = trim($output);
		if($output === '') return false;
		$json = json_decode($output,true);
		if(is_array($json)) return $json;
		$start = strpos($output,'{');
		$end = strrpos($output,'}');
		if($start === false || $end === false || $end <= $start) return false;
		$json = json_decode(substr($output,$start,$end - $start + 1),true);
		return is_array($json) ? $json : false;
	}
	private function nextcloudBumpEtag($path){
		try{
			if(!$path) return false;
			$info = $this->nextcloudSafeInfoFull($path);
			if(!$info || !$info['sourceID']) return false;
			$value = time().'.'.rand_string(8);
			Model("Source")->metaSet($info['sourceID'],'webdavEtag',$value);
			return $value;
		}catch(Throwable $e){
			$this->log('nextcloud bump etag exception: '.$e->getMessage().';path='.$path);
			return false;
		}
	}
	private function nextcloudChunkPropfind($user,$transferID,$dir,$itemName){
		$base = $this->nextcloudUrl('remote.php/dav/uploads/'.$user.'/'.$transferID.'/');
		$out = $this->nextcloudChunkResponse($base,$this->nextcloudTransferEtag($dir),0,true);
		foreach($this->nextcloudChunkFiles($dir) as $file){
			$out .= $this->nextcloudChunkResponse($base.rawurlencode(basename($file)), $this->nextcloudFileEtag($file), filesize($file), false);
		}
		$body = '<D:multistatus xmlns:D="DAV:">'.$out.'</D:multistatus>';
		return $this->nextcloudRawResponse(207,array('Content-Type: application/xml; charset=utf-8'),$body);
	}
	private function nextcloudChunkResponse($href,$etag,$size,$isCollection){
		$resType = $isCollection ? '<D:resourcetype><D:collection/></D:resourcetype>' : '<D:resourcetype/>';
		return '<D:response><D:href>'.$href.'</D:href><D:propstat><D:prop>'.$resType.
			'<D:getcontentlength>'.$size.'</D:getcontentlength><D:getetag>&quot;'.$etag.'&quot;</D:getetag>'.
			'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
	}
	private function nextcloudDestinationPath($dest,$user){
		$path = parse_url($dest,PHP_URL_PATH);
		$path = rawurldecode($path);
		$base = '/remote.php/dav/files/'.$user;
		$pos = strpos($path,$base);
		if($pos === false) return false;
		$rel = substr($path,$pos + strlen($base));
		return $rel ? $rel : '/';
	}
	private function nextcloudChunkDir($user,$transferID){
		$key = md5($user.'|'.$transferID);
		return rtrim(TEMP_FILES,'/').'/nextcloud_chunks/'.$key.'/';
	}
	private function nextcloudChunkFiles($dir){
		if(!is_dir($dir)) return array();
		$files = glob($dir.'*');
		if(!is_array($files)) return array();
		$list = array();
		foreach($files as $file){
			if(is_file($file) && basename($file) != '.file') $list[] = $file;
		}
		natsort($list);
		return array_values($list);
	}
	private function nextcloudSafeName($name){
		return preg_replace('/[^A-Za-z0-9._-]/','_',basename($name));
	}
	private function nextcloudChunkRemove($dir){
		foreach($this->nextcloudChunkFiles($dir) as $file){@unlink($file);}
		if(is_dir($dir)) @rmdir($dir);
	}
	private function nextcloudFileEtag($file){
		return is_file($file) ? md5_file($file) : md5('');
	}
	private function nextcloudTransferEtag($dir){
		mk_dir($dir);
		$file = rtrim($dir,'/').'/.etag';
		if(is_file($file)){
			$etag = trim(file_get_contents($file));
			if($etag) return $etag;
		}
		$etag = md5($dir.'|'.time().'|'.rand_string(32));
		file_put_contents($file,$etag);
		return $etag;
	}
	private function nextcloudUploadedEtag($path,$info=false){
		if(!$info || !is_array($info) || !isset($info['metaInfo'])){
			$info = $this->nextcloudSafeInfoFull($path);
		}
		if(!$info || !is_array($info)) return md5(time().$path);
		$hash = $this->nextcloudUploadedHash($info);
		$type = _get($info,'type','file');
		$size = _get($info,'size',0);
		$version = _get($info,'metaInfo.webdavEtag');
		if($type == 'folder'){
			$children = _get($info,'children.fileNum','').':'._get($info,'children.folderNum','');
			$data = implode('|',array(_get($info,'sourceID'),$type,_get($info,'modifyTime'),$size,$children,$version));
		}else{
			$data = $hash ?
				implode('|',array(_get($info,'sourceID'),$type,_get($info,'modifyTime'),$size,$hash)) :
				implode('|',array(_get($info,'sourceID'),$type,_get($info,'modifyTime'),$size,$version));
		}
		return md5($data);
	}
	private function nextcloudUploadedHash($info){
		$hash = _get($info,'fileInfo.hashMd5');
		if(!$hash) $hash = _get($info,'hashMd5');
		if(!$hash) $hash = _get($info,'fileInfo.hashSimple');
		if(!$hash) $hash = _get($info,'hashSimple');
		if($hash) return $hash;
		return '';
	}
	private function nextcloudRawResponse($code,$headers=array(),$body=''){
		if(isset($GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK'])){
			$GLOBALS['KOD_NEXTCLOUD_PUT_FALLBACK']['active'] = false;
		}
		header(HttpHeader::code($code));
		foreach($headers as $header){header($header);}
		if($body !== '') echo $body;
		return true;
	}
	private function nextcloudDavError($message){
		return '<?xml version="1.0" encoding="utf-8"?>'."\n".
			'<D:error xmlns:D="DAV:" xmlns:s="http://sabredav.org/ns">'.
			'<s:message>'.htmlentities($message).'</s:message>'.
			'</D:error>';
	}
	private function nextcloudLoginStart(){
		if($_SERVER['REQUEST_METHOD'] != 'POST') return $this->nextcloudStatusCode(405);
		$pollToken = $this->nextcloudToken();
		$flowToken = $this->nextcloudToken();
		$data = array(
			'pollToken' => $pollToken,
			'flowToken' => $flowToken,
			'time' => time(),
			'done' => false,
		);
		Cache::set('webdav_nextcloud_compat_flow_'.$flowToken,$data,1200);
		Cache::set('webdav_nextcloud_compat_poll_'.$pollToken,$data,1200);
		return $this->nextcloudJson(array(
			'poll' => array(
				'token' => $pollToken,
				'endpoint' => $this->nextcloudUrl('status.php?_nc_route=login_poll'),
			),
			'login' => $this->nextcloudUrl('status.php?_nc_route=login_flow&token='.rawurlencode($flowToken)),
		));
	}
	private function nextcloudLoginPoll(){
		if($_SERVER['REQUEST_METHOD'] != 'POST') return $this->nextcloudStatusCode(405);
		$token = isset($_POST['token']) ? $_POST['token'] : '';
		if(!$token){
			$raw = file_get_contents('php://input');
			parse_str($raw,$post);
			$token = isset($post['token']) ? $post['token'] : '';
		}
		$flow = $token ? Cache::get('webdav_nextcloud_compat_poll_'.$token) : false;
		$waitUntil = microtime(true) + 1;
		while($token && (!is_array($flow) || empty($flow['done'])) && microtime(true) < $waitUntil){
			usleep(200000);
			$flow = Cache::get('webdav_nextcloud_compat_poll_'.$token);
		}
		if(!is_array($flow) || empty($flow['done'])){
			header(HttpHeader::code(404));
			header('Content-Type: application/json; charset=utf-8');
			header('Retry-After: 1');
			echo '{}';return true;
		}
		Cache::remove('webdav_nextcloud_compat_poll_'.$token);
		return $this->nextcloudJson(array(
			'server' => $this->nextcloudBaseUrl(),
			'loginName' => $flow['loginName'],
			'appPassword' => $flow['appPassword'],
		));
	}
	private function nextcloudLoginFlow($flowToken){
		$flow = Cache::get('webdav_nextcloud_compat_flow_'.$flowToken);
		if(!is_array($flow)) return $this->nextcloudLoginHtml($flowToken,'Authorization request expired.');
		if($_SERVER['REQUEST_METHOD'] != 'POST'){
			return $this->nextcloudLoginHtml($flowToken,'');
		}
		$user = isset($_POST['user']) ? trim($_POST['user']) : '';
		$pass = isset($_POST['password']) ? $_POST['password'] : '';
		$find = ActionCall('user.index.userInfo',$user,$pass);
		if(!is_array($find) || !isset($find['userID'])){
			return $this->nextcloudLoginHtml($flowToken,'Invalid username or password.');
		}
		$poll = array(
			'pollToken' => $flow['pollToken'],
			'flowToken' => $flowToken,
			'time' => time(),
			'done' => true,
			'loginName' => $user,
			'appPassword' => $pass,
			'userID' => $find['userID'],
		);
		Cache::set('webdav_nextcloud_compat_poll_'.$flow['pollToken'],$poll,300);
		Cache::remove('webdav_nextcloud_compat_flow_'.$flowToken);
		return $this->nextcloudLoginHtml($flowToken,'Authorized. You can return to the desktop client.',true);
	}
	private function nextcloudLoginHtml($flowToken,$message = '',$success = false){
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		$action = htmlentities($this->nextcloudUrl('status.php?_nc_route=login_flow&token='.rawurlencode($flowToken)));
		$message = $message ? '<p class="msg '.($success ? 'ok':'err').'">'.htmlentities($message).'</p>' : '';
		$form = $success ? '' : '
			<form method="post" action="'.$action.'">
				<label>账号<input name="user" autocomplete="username" required autofocus></label>
				<label>密码<input name="password" type="password" autocomplete="current-password" required></label>
				<button type="submit">授权桌面客户端</button>
			</form>';
		$done = $success ? '<div class="done"><span></span><b>授权已完成</b><em>可以返回桌面客户端继续同步</em></div>' : '';
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Desktop Authorization</title>
			<style>
				*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#f3f7fb;color:#172033;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
				body:before{content:"";position:fixed;inset:0;background:linear-gradient(140deg,#eef7ff 0%,#f8fbff 42%,#eef3f8 100%);z-index:-1}
				main{width:390px;max-width:100%;background:#fff;border:1px solid #d8e2ec;border-radius:10px;padding:34px 32px 30px;box-shadow:0 18px 50px rgba(23,32,51,.12)}
				.mark{width:54px;height:54px;border-radius:50%;background:#0082c9;margin:0 auto 20px;position:relative;box-shadow:0 8px 18px rgba(0,130,201,.28)}
				.mark:before,.mark:after{content:"";position:absolute;background:#fff;border-radius:50%}.mark:before{width:22px;height:22px;left:12px;top:16px}.mark:after{width:18px;height:18px;right:10px;top:18px}
				h1{font-size:22px;text-align:center;margin:0 0 8px;font-weight:600;color:#172033}
				.sub{font-size:14px;text-align:center;color:#607083;line-height:1.55;margin:0 0 24px}
				label{display:block;font-size:13px;color:#344154;margin:14px 0 0;font-weight:500}
				input{width:100%;height:42px;margin-top:7px;border:1px solid #c8d4e0;border-radius:6px;padding:0 12px;font-size:15px;background:#fbfdff;outline:none}
				input:focus{border-color:#0082c9;box-shadow:0 0 0 3px rgba(0,130,201,.14);background:#fff}
				button{width:100%;height:44px;margin-top:22px;border:0;border-radius:6px;background:#0082c9;color:#fff;font-size:15px;font-weight:600;cursor:pointer}
				button:hover{background:#006fab}.msg{font-size:14px;line-height:1.5;margin:0 0 14px;padding:10px 12px;border-radius:6px}.ok{background:#e8f7ed;color:#166534}.err{background:#fff1f2;color:#b91c1c}
				.done{text-align:center}.done span{display:block;width:54px;height:54px;border-radius:50%;background:#16a34a;margin:0 auto 18px;position:relative}.done span:after{content:"";position:absolute;left:17px;top:14px;width:17px;height:25px;border:solid #fff;border-width:0 4px 4px 0;transform:rotate(45deg)}.done b{display:block;font-size:20px;margin-bottom:8px}.done em{display:block;font-style:normal;color:#607083;font-size:14px;line-height:1.5}
			</style></head><body><main><div class="mark"></div><h1>连接桌面客户端</h1><p class="sub">输入网盘账号密码，授权此设备访问并同步文件。</p>'.$message.$form.$done.'</main></body></html>';
		return true;
	}
	private function nextcloudRedirect($url,$code = 302){
		header(HttpHeader::code($code));
		header('Location: '.$url);
		header('Content-Length: 0');
		return true;
	}
	private function nextcloudToken(){
		return rand_string(32);
	}
	private function nextcloudUrl($path){
		return $this->nextcloudBaseUrl().'/'.ltrim($path,'/');
	}
	private function nextcloudCompatPath($path){
		$base = parse_url(APP_HOST,PHP_URL_PATH);
		$base = $base ? rtrim($base,'/') : '';
		if($base == '/') $base = '';
		return $base.'/'.ltrim($path,'/');
	}
	private function nextcloudBaseUrl(){
		$appParts = parse_url(APP_HOST);
		$scheme = '';
		if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])){
			$proto = explode(',',$_SERVER['HTTP_X_FORWARDED_PROTO']);
			$scheme = trim($proto[0]);
		}
		if(!$scheme && !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) == 'on'){
			$scheme = 'https';
		}
		if(!$scheme && !empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) != 'off'){
			$scheme = 'https';
		}
		if(!$scheme){
			$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? 'https':'http';
		}
		if($scheme == 'http' && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https'){
			$scheme = 'https';
		}
		if($scheme == 'http' && !empty($_SERVER['HTTP_X_FORWARDED_PORT']) && intval($_SERVER['HTTP_X_FORWARDED_PORT']) == 443){
			$scheme = 'https';
		}
		if($scheme == 'http' && !empty($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) == 443){
			$scheme = 'https';
		}
		if($scheme == 'http' && isset($appParts['scheme']) && $appParts['scheme'] == 'https'){
			$scheme = 'https';
		}
		$host = '';
		if(!empty($_SERVER['HTTP_X_FORWARDED_HOST'])){
			$hosts = explode(',',$_SERVER['HTTP_X_FORWARDED_HOST']);
			$host = trim($hosts[0]);
		}
		if(!$host && !empty($_SERVER['HTTP_HOST'])){
			$host = $_SERVER['HTTP_HOST'];
		}
		if(!$host){
			$host = isset($appParts['host']) ? $appParts['host'] : '';
			if($host && isset($appParts['port'])) $host .= ':'.$appParts['port'];
			if(isset($appParts['scheme'])) $scheme = $appParts['scheme'];
		}
		$path = parse_url(APP_HOST,PHP_URL_PATH);
		$path = $path ? rtrim($path,'/') : '';
		if($path == '/') $path = '';
		return rtrim($scheme.'://'.$host.$path,'/');
	}
	private function nextcloudJson($data){
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		echo json_encode($data);return true;
	}
	private function nextcloudStatusCode($code){
		header(HttpHeader::code($code));
		header('Content-Length: 0');
		return true;
	}
	private function nextcloudStatus(){
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		echo json_encode(array(
			'installed' => true,
			'maintenance' => false,
			'needsDbUpgrade' => false,
			'version' => '33.0.0.0',
			'versionstring' => '33.0.0',
			'edition' => 'kodbox',
			'productname' => 'Kodbox',
		));return true;
	}
	private function nextcloudUser(){
		$user = $this->nextcloudAuthUser();
		if(!$user) return true;
		$this->nextcloudOcsResponse(array(
			'id' => $user['name'],
			'displayname' => $user['nickName'] ? $user['nickName'] : $user['name'],
			'display-name' => $user['nickName'] ? $user['nickName'] : $user['name'],
			'email' => $user['email'] ? $user['email'] : '',
			'quota' => array(
				'free' => -3,
				'used' => 0,
				'total' => -3,
				'relative' => 0,
			),
		));return true;
	}
	private function currentUserName(){
		$user = Session::get("kodUser");
		return is_array($user) ? (_get($user,'name') ? _get($user,'name') : _get($user,'userID')) : '';
	}
	private function nextcloudCapabilities(){
		$data = array(
			'version' => array('major'=>33,'minor'=>0,'micro'=>0,'string'=>'33.0.0','edition'=>'kodbox'),
			'capabilities' => array(
				'core' => array(
					'webdav-root' => 'remote.php/dav',
					'pollinterval' => 1,
				),
				'dav' => array(
					'chunking' => '1.0',
					'bulkupload' => '0.0',
					'chunkingParallelUploadDisabled' => true,
					'httpErrorCodesThatResetFailingChunkedUploads' => array(409,423,507),
					'invalidFilenameRegex' => '',
				),
				'files' => array(
					'bigfilechunking' => true,
					'chunked_upload' => array(
						'max_size' => 107374182400,
						'max_parallel_count' => 1,
					),
					'blacklisted_files' => array('.htaccess'),
				),
				'checksums' => array(
					'supportedTypes' => array('SHA1','MD5'),
					'preferredUploadType' => 'MD5',
				),
				'activity' => array(
					'apiv2' => array(
						'filters' => array('all','files'),
					),
				),
				'files_sharing' => array(
					'api_enabled' => true,
					'public' => array('enabled' => true,'password' => array('enforced' => false)),
					'user' => array('send_mail' => false),
					'group_sharing' => true,
				),
			),
		);
		$this->nextcloudOcsResponse($data);return true;
	}
	private function nextcloudAppPassword(){
		$user = HttpAuth::get();
		$find = ActionCall('user.index.userInfo',$user['user'],$user['pass']);
		if(!is_array($find) || !isset($find['userID'])){
			HttpAuth::error();return false;
		}
		$this->nextcloudOcsResponse(array('apppassword'=>$user['pass']));return true;
	}
	private function nextcloudDeleteAppPassword(){
		$this->nextcloudAuthUser();
		$this->nextcloudOcsResponse(array());return true;
	}
	private function nextcloudNavigationApps(){
		$this->nextcloudOcsResponse(array('apps'=>array(array(
			'id' => 'files',
			'name' => '个人文件',
			'href' => $this->nextcloudBaseUrl().'#explorer&path='.rawurlencode(MY_HOME),
			'icon' => $this->nextcloudBaseUrl().'/favicon.ico',
			'type' => 'filesystem',
			'active' => true,
		))));return true;
	}
	private function nextcloudSharingApi($uri){
		$user = $this->nextcloudAuthUser();
		if(!$user) return true;
		if(preg_match('#/(sharees|sharees_recommended)(?:/.*)?$#',$uri)){
			return $this->nextcloudShareesSearch();
		}
		if(preg_match('#/(deletedshares|remote_shares)(?:/.*)?$#',$uri)){
			$this->nextcloudOcsResponse(array());return true;
		}
		if($_SERVER['REQUEST_METHOD'] == 'POST' && preg_match('#/shares/?$#',$uri)){
			return $this->nextcloudShareCreate($user);
		}
		if($_SERVER['REQUEST_METHOD'] == 'GET' && preg_match('#/shares/?$#',$uri)){
			return $this->nextcloudShareList($user);
		}
		if(preg_match('#/shares/([0-9]+)$#',$uri,$match)){
			if($_SERVER['REQUEST_METHOD'] == 'DELETE') return $this->nextcloudShareDelete($match[1]);
			if($_SERVER['REQUEST_METHOD'] == 'PUT') return $this->nextcloudShareUpdate($match[1]);
			if($_SERVER['REQUEST_METHOD'] == 'GET') return $this->nextcloudShareGet($match[1]);
		}
		return $this->nextcloudOcsError(404,'Sharing endpoint is not implemented.');
	}
	private function nextcloudShareCreate($user){
		$req = $this->nextcloudRequestData();
		$path = _get($req,'path','');
		$source = $this->nextcloudDavPathSourceInfo($path);
		if(!$source) return $this->nextcloudOcsError(404,'File not found.');
		$shareType = intval(_get($req,'shareType',3));
		$data = array(
			'title' => _get($source,'name'),
			'password' => _get($req,'password',''),
			'timeTo' => 0,
			'options' => array(),
			'authTo' => array(),
			'sourcePath' => KodIO::make($source['sourceID']),
			'isLink' => $shareType == 3 ? 1 : 0,
			'isShareTo' => $shareType == 3 ? 0 : 1,
		);
		if($shareType == 3){
			if(!Action('user.authRole')->authCan('explorer.shareLink')) return $this->nextcloudOcsError(403,'No permission to create public link.');
			if(_get($req,'hideDownload') || _get($req,'attributes') == 'notDownload') $data['options']['notDownload'] = 1;
		}else{
			if(!Action('user.authRole')->authCan('explorer.share')) return $this->nextcloudOcsError(403,'No permission to share.');
			$target = $this->nextcloudShareTarget($shareType,_get($req,'shareWith',''),_get($req,'permissions',1));
			if(!$target) return $this->nextcloudOcsError(404,'Share target not found.');
			$data['authTo'] = array($target);
		}
		$shareID = Model('Share')->shareAdd($source['sourceID'],$data);
		if(!$shareID) return $this->nextcloudOcsError(500,'Create share failed.');
		$shareInfo = Model('Share')->getInfo($shareID);
		if($shareType == 3) $shareInfo = Action('explorer.shareOut')->sendShareSiteAppend($shareInfo);
		$this->nextcloudOcsResponse($this->nextcloudShareFormat($shareInfo,$shareType));return true;
	}
	private function nextcloudShareesSearch(){
		$req = $this->nextcloudRequestData();
		$search = trim(_get($req,'search',''));
		$result = array(
			'exact' => array('users'=>array(),'groups'=>array(),'remotes'=>array(),'emails'=>array(),'circles'=>array(),'rooms'=>array()),
			'users' => array(),
			'groups' => array(),
			'remotes' => array(),
			'emails' => array(),
			'lookup' => array(),
			'circles' => array(),
			'rooms' => array(),
		);
		if($search !== ''){
			$users = Model('User')->where(array(
				'status' => 1,
				'name' => array('like','%'.$search.'%'),
			))->limit(20)->select();
			$usersNick = Model('User')->where(array(
				'status' => 1,
				'nickName' => array('like','%'.$search.'%'),
			))->limit(20)->select();
			$seen = array();
			foreach(array_merge(is_array($users) ? $users : array(),is_array($usersNick) ? $usersNick : array()) as $item){
				if(!_get($item,'userID') || isset($seen[$item['userID']])) continue;
				$seen[$item['userID']] = true;
				$name = _get($item,'name');
				$display = _get($item,'nickName') ? _get($item,'nickName') : $name;
				$result['users'][] = array(
					'label' => $display,
					'value' => array('shareType'=>0,'shareWith'=>$name,'shareWithDisplayName'=>$display),
				);
			}
			$groups = Model('Group')->where(array('name'=>array('like','%'.$search.'%')))->limit(20)->select();
			if(is_array($groups)){
				foreach($groups as $item){
					$name = _get($item,'name');
					if(!$name) continue;
					$result['groups'][] = array(
						'label' => $name,
						'value' => array('shareType'=>1,'shareWith'=>$name,'shareWithDisplayName'=>$name),
					);
				}
			}
		}
		$this->nextcloudOcsResponse($result);return true;
	}
	private function nextcloudShareList($user){
		$req = $this->nextcloudRequestData();
		$path = _get($req,'path','');
		$where = array('userID'=>KodUser::id());
		if($path){
			$source = $this->nextcloudDavPathSourceInfo($path);
			if(!$source){
				$this->nextcloudOcsResponse(array());return true;
			}
			$where['sourceID'] = $source['sourceID'];
		}
		$list = Model('Share')->where($where)->select();
		$result = array();
		if(is_array($list)){
			foreach($list as $item){
				$info = Model('Share')->getInfo($item['shareID']);
				if($info) $result[] = $this->nextcloudShareFormat($info,$this->nextcloudShareTypeFromInfo($info));
			}
		}
		$this->nextcloudOcsResponse($result);return true;
	}
	private function nextcloudShareGet($shareID){
		$info = Model('Share')->getInfo($shareID);
		if(!$info || _get($info,'userID') != KodUser::id()) return $this->nextcloudOcsError(404,'Share not found.');
		$this->nextcloudOcsResponse($this->nextcloudShareFormat($info,$this->nextcloudShareTypeFromInfo($info)));return true;
	}
	private function nextcloudShareUpdate($shareID){
		$req = $this->nextcloudRequestData();
		$info = Model('Share')->getInfo($shareID);
		if(!$info || _get($info,'userID') != KodUser::id()) return $this->nextcloudOcsError(404,'Share not found.');
		$data = array();
		if(isset($req['password'])) $data['password'] = _get($req,'password','');
		if(isset($req['expireDate'])) $data['timeTo'] = strtotime($req['expireDate'].' 23:59:59');
		if(isset($req['permissions']) && _get($info,'isShareTo') == '1'){
			$authTo = _get($info,'authList',array());
			if(is_array($authTo)){
				foreach($authTo as &$item){$item['authID'] = $this->nextcloudShareAuthID($req['permissions']);}
				$data['authTo'] = $authTo;
			}
		}
		if(!$data){$this->nextcloudOcsResponse($this->nextcloudShareFormat($info,$this->nextcloudShareTypeFromInfo($info)));return true;}
		Model('Share')->shareEdit($shareID,$data);
		return $this->nextcloudShareGet($shareID);
	}
	private function nextcloudShareDelete($shareID){
		$info = Model('Share')->getInfo($shareID);
		if(!$info || _get($info,'userID') != KodUser::id()) return $this->nextcloudOcsError(404,'Share not found.');
		Model('Share')->remove(array($shareID));
		$this->nextcloudOcsResponse(array());return true;
	}
	private function nextcloudDavPathSourceInfo($path){
		$path = rawurldecode($path);
		if(!$path) return false;
		if($path[0] != '/') $path = '/'.$path;
		require_once($this->pluginPath.'php/webdavServer.class.php');
		require_once($this->pluginPath.'php/webdavServerKod.class.php');
		if(!defined('KOD_NEXTCLOUD_COMPAT')) define('KOD_NEXTCLOUD_COMPAT',1);
		$dav = new webdavServerKod('/remote.php/dav/files/'.rawurlencode(_get(Session::get('kodUser'),'name','')).'/');
		$kodPath = $dav->parsePath($path);
		if(!$kodPath && $this->nextcloudIsMobileClient()) $kodPath = rtrim(MY_HOME,'/').'/'.trim($path,'/');
		$info = $kodPath ? $this->nextcloudSafeInfoFull($kodPath) : false;
		return is_array($info) && _get($info,'sourceID') ? $info : false;
	}
	private function nextcloudShareTarget($shareType,$shareWith,$permissions){
		$shareWith = trim($shareWith);
		if($shareWith === '') return false;
		$targetType = $shareType == 1 ? 2 : 1;
		if($targetType == 1){
			$user = Model('User')->where(array('name'=>$shareWith))->find();
			if(!$user) $user = Model('User')->where(array('nickName'=>$shareWith))->find();
			if(!$user || !_get($user,'userID')) return false;
			$targetID = $user['userID'];
		}else{
			$group = Model('Group')->where(array('name'=>$shareWith))->find();
			if(!$group || !_get($group,'groupID')) return false;
			$targetID = $group['groupID'];
		}
		return array('targetType'=>$targetType,'targetID'=>$targetID,'authID'=>$this->nextcloudShareAuthID($permissions));
	}
	private function nextcloudShareAuthID($permissions){
		return intval($permissions) <= 1 ? '1' : '5';
	}
	private function nextcloudShareTypeFromInfo($shareInfo){
		if(intval(_get($shareInfo,'isLink'))) return 3;
		$authList = _get($shareInfo,'authList',array());
		if(is_array($authList) && count($authList) > 0 && _get($authList[0],'targetType') == '2') return 1;
		return 0;
	}
	private function nextcloudShareFormat($shareInfo,$shareType=3){
		$source = _get($shareInfo,'sourceInfo',array());
		$sourceID = intval(_get($shareInfo,'sourceID',_get($source,'sourceID')));
		$path = $sourceID ? KodIO::make($sourceID) : '';
		$url = '';
		if($shareType == 3){
			if(_get($shareInfo,'shareHash')) $url = urlApi('sitemap/share/'._get($shareInfo,'shareHash'),null);
		}
		$authList = _get($shareInfo,'authList',array());
		$shareWith = '';
		$shareWithDisplay = '';
		if(is_array($authList) && count($authList) > 0){
			$target = $authList[0];
			if(_get($target,'targetType') == '2'){
				$group = Model('Group')->getInfo(_get($target,'targetID'));
				$shareWith = _get($group,'name','');
				$shareWithDisplay = $shareWith;
			}else{
				$user = Model('User')->getInfo(_get($target,'targetID'));
				$shareWith = _get($user,'name','');
				$shareWithDisplay = _get($user,'nickName') ? _get($user,'nickName') : $shareWith;
			}
		}
		$owner = $this->currentUserName();
		$pathDisplay = '/'.trim(_get($source,'pathDisplay',_get($source,'name','')),'/');
		return array(
			'id' => ''.intval(_get($shareInfo,'shareID')),
			'share_type' => $shareType,
			'uid_owner' => $owner,
			'displayname_owner' => $owner,
			'uid_file_owner' => $owner,
			'displayname_file_owner' => $owner,
			'permissions' => 31,
			'stime' => intval(_get($shareInfo,'createTime',time())),
			'expiration' => intval(_get($shareInfo,'timeTo')) ? date('Y-m-d',intval(_get($shareInfo,'timeTo'))) : null,
			'token' => _get($shareInfo,'shareHash',''),
			'url' => $url,
			'path' => $pathDisplay,
			'item_type' => _get($source,'type') == 'folder' ? 'folder' : 'file',
			'mimetype' => _get($source,'type') == 'folder' ? 'httpd/unix-directory' : get_file_mime(_get($source,'ext')),
			'storage_id' => 'kodbox::'.$sourceID,
			'storage' => 1,
			'item_source' => ''.$sourceID,
			'file_source' => ''.$sourceID,
			'file_parent' => ''.intval(_get($source,'parentID')),
			'file_target' => '/'.trim(_get($source,'name',''),'/'),
			'share_with' => $shareWith,
			'share_with_displayname' => $shareWithDisplay,
			'mail_send' => 0,
			'note' => '',
			'hide_download' => intval(_get($shareInfo,'options.notDownload')) ? 1 : 0,
			'label' => '',
		);
	}
	private function nextcloudRequestData(){
		$data = $_REQUEST;
		$raw = file_get_contents('php://input');
		if($raw){
			$json = json_decode($raw,true);
			if(is_array($json)) $data = array_merge($data,$json);
		}
		return $data;
	}
	private function nextcloudOpenInBrowser($fileID){
		$source = Model('Source')->where(array('sourceID'=>intval($fileID),'isDelete'=>0))->find();
		if(!is_array($source) || !isset($source['sourceID'])){
			return $this->nextcloudRedirect($this->nextcloudBaseUrl(),302);
		}
		$path = KodIO::make($source['sourceID']);
		$url = intval(_get($source,'isFolder')) ?
			$this->nextcloudBaseUrl().'#explorer&path='.rawurlencode($path) :
			$this->nextcloudBaseUrl().'#explorer&sidf='.intval($source['sourceID']);
		return $this->nextcloudRedirect($url,302);
	}
	private function nextcloudActivityApi(){
		if(!$this->nextcloudAuthUser()) return true;
		$source = $this->nextcloudActivitySource();
		if(!$source){
			$this->nextcloudOcsResponse(array());return true;
		}
		$data = Model('SourceEvent')->listBySource($source['sourceID']);
		$list = _get($data,'list',array());
		if(!is_array($list)) $list = array();
		$page = max(1,intval(_get($_REQUEST,'page',1)));
		$pageNum = max(1,min(200,intval(_get($_REQUEST,'pageNum',_get($_REQUEST,'limit',200)))));
		$list = array_slice($list,($page - 1) * $pageNum,$pageNum);
		$result = array();
		foreach($list as $index => $item){
			$result[] = $this->nextcloudActivityItem($item,$source,$index);
		}
		$this->nextcloudOcsResponse($result);return true;
	}
	private function nextcloudActivitySource(){
		$fileID = intval(_get($_REQUEST,'fileId',_get($_REQUEST,'fileid',0)));
		if(!$fileID && is_numeric(_get($_REQUEST,'object_id',''))) $fileID = intval($_REQUEST['object_id']);
		if($fileID > 0){
			$source = Model('Source')->where(array('sourceID'=>$fileID,'isDelete'=>0))->find();
			if(is_array($source) && _get($source,'sourceID')) return $source;
		}
		$path = _get($_REQUEST,'path','');
		if(!$path && _get($_REQUEST,'object_id','') && !is_numeric($_REQUEST['object_id'])){
			$path = $_REQUEST['object_id'];
		}
		if($path){
			$source = $this->nextcloudDavPathSourceInfo($path);
			if($source) return $source;
		}
		return IO::infoFull(MY_HOME);
	}
	private function nextcloudActivityItem($item,$source,$index){
		$sourceInfo = _get($item,'sourceInfo',array());
		$eventSourceID = intval(_get($sourceInfo,'sourceID',_get($item,'sourceID',_get($source,'sourceID'))));
		$name = _get($sourceInfo,'name',_get($source,'name',''));
		$type = _get($sourceInfo,'type',_get($source,'type','file')) == 'folder' ? 'folder' : 'file';
		$time = intval(_get($item,'createTime',_get($item,'modifyTime',time())));
		$user = _get($item,'user',_get($item,'userInfo',array()));
		$userName = is_array($user) ? (_get($user,'name',_get($user,'nickName',$this->currentUserName()))) : ($user ? $user : $this->currentUserName());
		$subject = $this->nextcloudActivitySubject($item,$name);
		$link = $eventSourceID ? $this->nextcloudBaseUrl().'#explorer&sidf='.$eventSourceID : $this->nextcloudBaseUrl();
		return array(
			'activity_id' => intval(_get($item,'id',_get($item,'eventID',$time.$index))),
			'app' => 'files',
			'type' => 'file_changed',
			'user' => $userName,
			'affecteduser' => $this->currentUserName(),
			'subject' => $subject,
			'subject_rich' => array($subject,array()),
			'message' => '',
			'message_rich' => array('',array()),
			'object_type' => 'files',
			'object_id' => $eventSourceID,
			'object_name' => $name,
			'datetime' => date('c',$time),
			'link' => $link,
			'icon' => $this->nextcloudBaseUrl().'/favicon.ico',
			'file' => array('id'=>$eventSourceID,'name'=>$name,'path'=>_get($sourceInfo,'pathDisplay',''),'type'=>$type),
		);
	}
	private function nextcloudActivitySubject($item,$name){
		$desc = _get($item,'desc','');
		if(is_array($desc)){
			$text = _get($desc,'text','');
			if(!$text && _get($desc,'from') && _get($desc,'to')) $text = '重命名 '.$name;
			if($text) return $text;
		}
		$action = _get($item,'type',_get($item,'action',''));
		if($action) return $action.($name ? ': '.$name : '');
		return $name ? '更新了 '.$name : '文件有更新';
	}
	private function nextcloudActivityPage(){
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activity</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#f6f8fb;color:#172033;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.box{max-width:420px;background:#fff;border:1px solid #dce5ee;border-radius:10px;padding:30px;text-align:center;box-shadow:0 14px 40px rgba(23,32,51,.08)}h1{font-size:22px;margin:0 0 10px}p{color:#637083;line-height:1.55;margin:0 0 20px}.btn{display:inline-block;background:#0082c9;color:#fff;text-decoration:none;border-radius:6px;padding:10px 18px;font-weight:600}</style></head><body><main class="box"><h1>文件动态</h1><p>当前兼容层暂未提供详细动态记录，可返回网盘查看文件。</p><a class="btn" href="'.htmlspecialchars($this->nextcloudBaseUrl()).'">打开网盘</a></main></body></html>';
		return true;
	}
	private function nextcloudAuthUser($expectUser=false){
		$user = HttpAuth::get();
		if(substr($user['user'],0,2) == '$$'){
			$user['user'] = rawurldecode(substr($user['user'],2));
		}
		$startPose = strrpos($user['user'],"\\");
		if($startPose){$user['user'] = substr($user['user'],$startPose + 1);}
		if($expectUser !== false && rawurldecode($expectUser) !== $user['user']){
			$this->log('nextcloud auth user mismatch: pathUser='.rawurldecode($expectUser).';authUser='.$user['user']);
			HttpAuth::error();return false;
		}
		$find = ActionCall('user.index.userInfo', $user['user'],$user['pass']);
		if(!is_array($find) || !isset($find['userID'])){
			HttpAuth::error();return false;
		}
		$sessionUser = Session::get('kodUser');
		if(!is_array($sessionUser) || _get($sessionUser,'userID') != $find['userID']){
			ActionCall('user.index.loginSuccess',$find);
		}
		return $find;
	}
	private function nextcloudOcsResponse($data){
		if(isset($_GET['format']) && strtolower($_GET['format']) == 'json'){
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			echo json_encode(array('ocs'=>array(
				'meta'=>array('status'=>'ok','statuscode'=>100,'message'=>'OK'),
				'data'=>$data,
			)));return;
		}
		header('Content-Type: application/xml; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		echo '<?xml version="1.0"?>'."\n".$this->nextcloudOcsXml($data);
	}
	private function nextcloudOcsError($code,$message){
		$status = intval($code) == 404 ? 404 : 200;
		header(HttpHeader::code($status));
		if(isset($_GET['format']) && strtolower($_GET['format']) == 'json'){
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('ocs'=>array(
				'meta'=>array('status'=>'failure','statuscode'=>$code,'message'=>$message),
				'data'=>array(),
			)));return true;
		}
		header('Content-Type: application/xml; charset=utf-8');
		echo '<?xml version="1.0"?>'."\n".
			'<ocs><meta><status>failure</status><statuscode>'.$code.'</statuscode><message>'.
			htmlentities($message).'</message></meta><data/></ocs>';
		return true;
	}
	private function nextcloudOcsXml($data){
		$xml = '<ocs><meta><status>ok</status><statuscode>100</statuscode><message>OK</message></meta><data>';
		$xml .= $this->nextcloudXmlItems($data);
		return $xml.'</data></ocs>';
	}
	private function nextcloudXmlItems($data){
		$xml = '';
		foreach($data as $key=>$val){
			$key = is_numeric($key) ? 'element' : preg_replace('/[^a-zA-Z0-9_\-]/','',$key);
			if(is_array($val)){
				$xml .= '<'.$key.'>'.$this->nextcloudXmlItems($val).'</'.$key.'>';
			}else{
				if(is_bool($val)) $val = $val ? 'true':'false';
				$xml .= '<'.$key.'>'.htmlentities($val.'').'</'.$key.'>';
			}
		}
		return $xml;
	}
	public function run($uriDav){
		require($this->pluginPath.'php/webdavServer.class.php');
		require($this->pluginPath.'php/webdavServerKod.class.php');
		register_shutdown_function(array(&$this, 'endLog'));
		
		define('KOD_FROM_WEBDAV',1);
		$this->allowCROS();
		$this->dav = new webdavServerKod($uriDav);
		$this->debug();
		$this->dav->run();
	}
	
	// 允许跨域,兼容以浏览器为客户端的情况;
	private function allowCROS(){
		$allowMethods = 'GET, POST, OPTIONS, DELETE, HEAD, MOVE, COPY, PUT, MKCOL, PROPFIND, PROPPATCH, LOCK, UNLOCK';
		$allerHeaders = 'ETag, Content-Type, Content-Length, Accept-Encoding, X-Requested-with, Origin, Authorization, OCS-APIRequest, X-OC-MTime, If-Match, If-None-Match, Destination, Depth, Overwrite, Lock-Token, Timeout';
		header('Access-Control-Allow-Origin: *');    				// 允许的域名来源;
		header('Access-Control-Allow-Methods: '.$allowMethods); 	// 允许请求的类型
		header('Access-Control-Allow-Headers: '.$allerHeaders);		// 允许请求时带入的header
		header('Access-Control-Allow-Credentials: true'); 			// 设置是否允许发送 cookie; js需设置:xhr.withCredentials = true;
		header('Access-Control-Max-Age: 3600');
	}
	
	public function _checkConfig(){
		$nowSize=_get($_SERVER,'_afileSize','');$enSize=_get($_SERVER,'_afileSizeIn','');
		if(function_exists('_kodDe') && (!$nowSize || !$enSize || $nowSize != $enSize)){exit;}
	}

	private function debug(){
		// $this->log('start;'.$this->dav->pathGet().';'.$this->dav->path);
		// 兼容处理chrome插件访问webdav;
		// PROPFIND;GET;MOVE;COPY,HEAD,PUT
		if( $_SERVER['REQUEST_METHOD'] == 'GET' && 
			strstr($_SERVER['HTTP_USER_AGENT'],'Chrome') &&
			isset($_COOKIE['kodUserID']) ){
			$_SERVER['REQUEST_METHOD'] = 'PROPFIND';
		}
	}
	
	public function endLog(){
		$logInfo = 'dav-error';
		if($this->dav){
			$logInfo = $this->dav->pathGet().';'.$this->dav->path;
		}
		// $logInfo .= get_caller_msg();
		$this->log('end;['.http_response_code().'];'.$logInfo);
	}
	
	private function serverInfo($pick = ''){
		$ignore = 'USER,HOME,PATH_TRANSLATED,ORIG_SCRIPT_FILENAME,HTTP_CONNECTION,HTTP_ACCEPT,HTTP_HOST,SERVER_NAME,SERVER_PORT,SERVER_ADDR,REMOTE_PORT,REMOTE_ADDR,SERVER_SOFTWARE,GATEWAY_INTERFACE,REQUEST_SCHEME,SERVER_PROTOCOL,DOCUMENT_ROOT,DOCUMENT_URI,REQUEST_URI,SCRIPT_NAME,CONTENT_LENGTH,CONTENT_TYPE,REQUEST_METHOD,QUERY_STRING,PATH_INFO,SCRIPT_FILENAME,FCGI_ROLE,PHP_SELF,REQUEST_TIME_FLOAT,REQUEST_TIME,REDIRECT_STATUS,HTTP_ACCEPT_ENCODING,HTTP_CACHE_CONTROL,HTTP_UPGRADE_INSECURE_REQUESTS,HTTP_CONTENT_LENGTH,HTTP_CONTENT_TYPE,HTTP_REFERER';
		$ignore .= ',HTTP_COOKIE,HTTP_ACCEPT_LANGUAGE,HTTP_USER_AGENT';
		$ignore .= ',HTTP_AUTHORIZATION,PHP_AUTH_USER,PHP_AUTH_PW';
		$ignore = explode(',',$ignore);
		$pick   = $pick ? explode(',',$pick) : array();
		
		$result = array();
		foreach($GLOBALS['__SERVER'] as $key => $val){
			if($pick){
				if(in_array($key,$pick)){$result[$key] = $val;}
			}else{
				if(!in_array($key,$ignore)){$result[$key] = $val;}
			}
		}
		return $result ? json_encode($result):'';
	}
	
	public function log($data){
		static $logIndex = 0;
		if(!$this->nextcloudLogEnabled()) return;
		if(is_array($data)){$data = json_encode_force($data);}
		if($_SERVER['REQUEST_METHOD'] == 'PROPFIND' ) return;
		
		$prefix = "     [S-$logIndex] ";
		if(!$logIndex){
			$prefix = "[SERVER-$logIndex] ";$logIndex++;
			$data   = $_SERVER['REQUEST_METHOD'].':'.$_SERVER['REQUEST_URI'].";".$this->serverInfo('').$data;
		}
		write_log($prefix.$data,'webdavNextcloud');
		//write_log($GLOBALS['__SERVER'],'webdavNextcloud');
	}
	public function clientLog($data){
		static $logIndex = 0;
		if(!$this->nextcloudLogEnabled()) return;
		if(is_array($data)){$data = json_encode_force($data);}

		$prefix = "     [C-$logIndex] ";
		if(!$logIndex){$prefix = "[CLIENT-$logIndex] ";$logIndex++;}
		write_log($prefix.$data,'webdavNextcloud');
	}
	private function nextcloudLogEnabled(){
		$config = $this->getConfig();
		return _get($config,'nextcloudCompatLog') == '1' || _get($config,'echoLog') == '1';
	}
}
