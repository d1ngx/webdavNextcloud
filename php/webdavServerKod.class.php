<?php

/**
 * webdav 文件管理处理;
 * 
 * kod自定义扩展支持:
 * 1. 文件属性数组追加 extendFileInfo; 数据:base64_encode(json_encode({}));//hasFile,fileInfoMore,children,fileOutLink...
 * 2. 文件列表数组追加 extendFileList; 数据:base64_encode(json_encode({}));//groupShow,pageInfo,targetSpace
 * 
 * 兼容sabre的文件patch追加协议: https://sabre.io/dav/http-patch/  
 */
class webdavServerKod extends webdavServer {
	private $nextcloudCompatRoot = false;
	public function __construct($DAV_PRE) {
		$this->davPre = $DAV_PRE;
		$this->plugin = Action('webdavNextcloudPlugin');
		Hook::bind('show_json',array($this,'showErrorCheck'));
	}

	public function run(){
		$method = 'http'.HttpHeader::method();
		if(!method_exists($this,$method)){
			return HttpAuth::error();
		}
		if($method == 'httpOPTIONS'){
			return self::response($this->httpOPTIONS());
		}
		
		$this->checkUser();
		$this->initPath($this->davPre);
		$result = $this->$method();
		if(!$result) return;//文件下载;
		$this->response($result);
    }
	
	// head时一直返回200; 登录失败或无权限则直接返回; 登录检测等成功同理多了文件信息;
	// 兼容 win10下office打开异常情况;
	private function checkErrorHead(){		
		if(HttpHeader::method() != 'HEAD') return;
		self::response(array(
			'code' => 200,
			'headers' => array(
				'Content-Type: text/html; charset=utf8',
			)
		));exit;
	}
	
	// 错误处理;(空间不足,无权限等)
	public function showErrorCheck($json){
		if(!is_array($json)) return $json;
		if($json['code'] == true || $json['code'] == 1){
			if(defined('KOD_FROM_WEBDAV') && KOD_FROM_WEBDAV && HttpHeader::method() == 'PUT'){
				$etagPath = $this->path;
				if(!empty($GLOBALS['KOD_WEBDAV_UPLOAD_PATH'])){
					$etagPath = $GLOBALS['KOD_WEBDAV_UPLOAD_PATH'];
				}else if(isset($json['info']) && is_string($json['info']) && IO::infoFull($json['info'])){
					$etagPath = $json['info'];
				}
				$headers = $this->etagHeader($etagPath);
				if(!$headers){
					$etag = md5($etagPath.'|'.time());
					$headers = array('ETag: "'.$etag.'"','OC-ETag: "'.$etag.'"');
				}
				$headers[] = 'Content-Length: 0';
				$this->response(array('code'=>201,'headers'=>$headers));exit;
			}
			return $json;
		}
		
		$this->checkErrorHead();
		$this->lastError = is_string($json['data']) ?$json['data']:'';
		$this->response(array('code'=>404));exit;
	}
	public function getLastError(){
		$error = $this->lastError;
		if(!$error){$error = Action('explorer.auth')->getLastError();}
		if(!$error){$error = IO::getLastError();}
		return $error;
	}

	/**
	 * 用户登录校验;权限判断;
	 * 性能优化: 通过cookie处理为已登录; (避免ad域用户或用户集成每次进行登录验证;)
	 * 
	 */
	public function checkUser(){
		$userInfo = Session::get("kodUser");
	    if(!$userInfo || !is_array($userInfo)){
    	    $user = HttpAuth::get();
			// 兼容webdav挂载不支持中文用户名; 中文名用户名编解码处理;
			if(substr($user['user'],0,2) == '$$'){
				$user['user'] = rawurldecode(substr($user['user'],2));
			}
			// Windows下wps打开文件需要再次输入用户名密码情况; 用户名带入了电脑名称兼容(eg:'DESKTOP-E12RTST\admin:123')
			$startPose = strrpos($user['user'],"\\"); 
			if($startPose){$user['user'] = substr($user['user'],$startPose + 1);}
			
    		$find = ActionCall('user.index.userInfo', $user['user'],$user['pass']);
    		if ( !is_array($find) || !isset($find['userID']) ){
    			// $this->plugin->log(array($user,$find,$_SERVER['HTTP_AUTHORIZATION'],$GLOBALS['_SERVER']));
				$this->checkErrorHead();
    			return HttpAuth::error();
    		}
    		ActionCall('user.index.loginSuccess',$find);
			
			// 登录日志;
			$needLog = time() - intval($find['lastLogin']) >= 60; // 超过1分钟才记录
			if($needLog && HttpHeader::method() == 'PROPFIND'){
				Model('User')->userEdit($find['userID'],array("lastLogin"=>time()));
				ActionCall('admin.log.loginLog');
			}
	    }
		
		// 自适应多语言;
		$lang = Model('UserOption')->get('language');
		if($lang && method_exists('I18n','setLanguageAllow') && $lang != I18n::getType()){
			I18n::setLanguage($lang);
		}
		
		if(!$this->plugin->authCheck()){
			$this->checkErrorHead();
			$this->lastError = LNG('common.noPermission');
			$this->response(array('code'=>404));exit;
		}
	}
	public function parsePath($path){
		if(defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT){
			$this->nextcloudCompatRoot = (!$path || $path == '/');
			if($this->nextcloudCompatRoot) return MY_HOME;
			$pathArr = explode('/',KodIO::clear(trim($path,'/')));
			$rootList = $this->nextcloudCompatRootList();
			return $this->pathInfoDeep($rootList,$pathArr);
		}
		$options   = $this->plugin->getConfig();
		$rootBlock = '{block:files}/';
		$rootPath  = $options['pathAllow'] == 'self' ? MY_HOME:$rootBlock;
		if(!$path || $path == '/') return $rootPath;

		$pathArr = explode('/',KodIO::clear(trim($path,'/')));
		if($rootPath == $rootBlock){
			$rootList = $this->pathBlockRoot();
			$this->rootPathAutoLang($rootList,$pathArr);
		}else{
			$rootList = Action('explorer.list')->path($rootPath);
		}
		return $this->pathInfoDeep($rootList,$pathArr);
	}
	private function nextcloudCompatRootList(){
		$current = $this->nextcloudCompatVirtualItem(MY_HOME,'',array());
		$current['sourceID'] = $this->nextcloudCompatVirtualID('root');
		$current['fileID'] = $current['sourceID'];
		$current['fileInfo']['fileID'] = $current['sourceID'];
		$current['metaInfo']['webdavEtag'] = 'root';
		$current['name'] = '';
		$current['type'] = 'folder';
		$list = array(
			'current' => $current,
			'folderList' => array(),
			'fileList' => array(),
		);
		$exists = array();
		$homeName = LNG('explorer.toolbar.rootPath');
		$list['folderList'][] = $this->nextcloudCompatRootItem(MY_HOME,$homeName);
		$exists[MY_HOME] = true;
		$exists[$homeName] = true;
		foreach($this->nextcloudEnterpriseRoots() as $item){
			if(!$item || !$item['path'] || !$item['name']) continue;
			if(isset($exists[$item['path']]) || isset($exists[$item['name']])) continue;
			$exists[$item['path']] = true;
			$exists[$item['name']] = true;
			$list['folderList'][] = $this->nextcloudCompatRootItem($item['path'],$item['name'],$item);
		}
		$list['current']['children'] = array('fileNum'=>0,'folderNum'=>count($list['folderList']));
		$list['current']['metaInfo']['webdavEtag'] = 'root-'.$this->nextcloudCompatRootsSignature($list['folderList']);
		return $list;
	}
	private function nextcloudCompatRootItem($path,$name,$item=false){
		$info = is_array($item) ? $item : array();
		$listAction = Action('explorer.list');
		if(is_callable(array($listAction,'pathCurrent'))){
			$current = $listAction->pathCurrent($path);
			if(is_array($current)) $info = array_merge($info,$current);
		}
		return $this->nextcloudCompatVirtualItem($path,$name,$info);
	}
	private function nextcloudCompatVirtualItem($path,$name,$info=array()){
		if(!is_array($info)) $info = array();
		$stableID = $this->nextcloudCompatVirtualID($path.'|'.$name);
		$stableTime = 1600000000;
		if(!isset($info['sourceID']) || !$info['sourceID']) $info['sourceID'] = $stableID;
		if(!isset($info['fileID']) || !$info['fileID']) $info['fileID'] = $info['sourceID'];
		if(!isset($info['fileInfo']) || !is_array($info['fileInfo'])) $info['fileInfo'] = array();
		if(!isset($info['fileInfo']['fileID']) || !$info['fileInfo']['fileID']) $info['fileInfo']['fileID'] = $info['sourceID'];
		$info['modifyTime'] = $stableTime;
		$info['createTime'] = $stableTime;
		if(!isset($info['metaInfo']) || !is_array($info['metaInfo'])) $info['metaInfo'] = array();
		if(!isset($info['metaInfo']['webdavEtag']) || !$info['metaInfo']['webdavEtag']){
			$info['metaInfo']['webdavEtag'] = 'virtual-'.$stableID;
		}
		if(!isset($info['children']) || !is_array($info['children'])){
			$info['children'] = array('fileNum'=>0,'folderNum'=>0);
		}
		$info['path'] = $path;
		$info['name'] = $name;
		$info['type'] = 'folder';
		$info['size'] = 0;
		return $info;
	}
	private function nextcloudCompatVirtualID($seed){
		return sprintf('%u',crc32('nextcloud-compat|'.$seed));
	}
	private function nextcloudCompatRootsSignature($items){
		$parts = array();
		foreach($items as $item){
			$parts[] = _get($item,'name').':'.$this->nextcloudFolderTreeSignature($item);
		}
		return md5(implode('|',$parts));
	}
	private function nextcloudEnterpriseRoots(){
		$list = array();
		$blockFiles = Action('explorer.listBlock')->blockChildren('files');
		if(is_array($blockFiles)){
			foreach($blockFiles as $item){
				if(!is_array($item) || !$item['path']) continue;
				$isGroupRoot = _get($item,'sourceRoot') == 'groupPublic';
				$isMyGroup = trim($item['path'],'/') == trim(KodIO::KOD_GROUP_ROOT_SELF,'/');
				if(!$isGroupRoot && !$isMyGroup) continue;
				$list[] = $item;
			}
		}
		$list[] = array("path"=> KodIO::KOD_GROUP_ROOT_SELF,'name'=>LNG('explorer.toolbar.myGroup'));
		$groupArray = Action('filter.userGroup')->userGroupRoot();
	    if(is_array($groupArray)){
			foreach($groupArray as $groupID){
				$groupInfo = Model('Group')->getInfo($groupID);
				if($groupInfo && $groupInfo['sourceInfo']){
					$list[] = array("path"=> KodIO::make($groupInfo['sourceInfo']['sourceID']),'name'=>$groupInfo['name']);
				}
			}
		}
		if(!$groupArray && KodUser::isRoot()){
			$groups = Model('Group')->where(array('parentID'=>0))->select();
			if(is_array($groups)){
				foreach($groups as $groupInfo){
					$groupInfo = Model('Group')->getInfo($groupInfo['groupID']);
					if($groupInfo && $groupInfo['sourceInfo']){
						$list[] = array("path"=> KodIO::make($groupInfo['sourceInfo']['sourceID']),'name'=>$groupInfo['name']);
					}
				}
			}
		}
		return $list;
	}

	//获取{block:files}/下面的子文件夹;(从pathList直接获取较耗时(70ms),性能优化)  
	private function pathBlockRoot(){
		$list = array(
			array("path"=> KodIO::KOD_USER_FAV,'name'=>LNG('explorer.toolbar.fav')),
			array("path"=> KodIO::make(Session::get('kodUser.sourceInfo.sourceID')),'name'=>LNG('explorer.toolbar.rootPath')),
			array("path"=> KodIO::KOD_GROUP_ROOT_SELF,'name'=>LNG('explorer.toolbar.myGroup')),
			array("path"=> KodIO::KOD_USER_SHARE_TO_ME,'name'=> LNG('explorer.toolbar.shareToMe')),
		);
		// 企业网盘;
		$groupArray = Action('filter.userGroup')->userGroupRoot();
	    if (is_array($groupArray) && $groupArray[0]){
			$groupInfo = Model('Group')->getInfo($groupArray[0]);
			$list[] = array("path"=> KodIO::make($groupInfo['sourceInfo']['sourceID']),'name'=>$groupInfo['name']);
		}
		return array('folderList'=>$list,'fileList'=>array());
	}
	
	// 如果挂载全部路径; 第一层路径自适应多语言处理;
	private function rootPathAutoLang($rootList,&$pathArr){
		$rootPathName = array_to_keyvalue($rootList['folderList'],'','name');
		if(in_array($pathArr[0],$rootPathName)) return;

		$langKeys = $this->loadLangKeys();
		foreach($langKeys as $key=>$langValues){
			if(in_array($pathArr[0],$langValues)){
				$pathArr[0] = LNG($key);break;
			}
		}
	}
	
	// 获取key对应多个语言的值; [收藏夹,个人空间,我所在的部门,与我协作]; //企业网盘为部门名
	private function loadLangKeys(){
		$langKeys = Cache::get('webdav_lang_path_root');
		if(is_array($langKeys)) return $langKeys;
		
		$langKeys = array(
			'explorer.toolbar.fav'			=> array(),	// 收藏夹
			'explorer.toolbar.rootPath'		=> array(),	// 个人空间
			'explorer.toolbar.myGroup'		=> array(),	// 我所在的部门
			'explorer.toolbar.shareToMe'	=> array(),	// 与我协作
		);
		$languageList = $GLOBALS['config']['settingAll']['language'];
		foreach($languageList as $lang=>$info){
			$langFile = LANGUAGE_PATH.$lang.'/index.php';
			$langArr  = include($langFile);
			if(!is_array($langArr)) continue;
			foreach ($langKeys as $key=>$val){
				if(!$langArr[$key]) continue;
				$langKeys[$key][] = $langArr[$key];
			}
		}
		$langKeys['explorer.toolbar.rootPath'][] = 'my'; // 增加;
		Cache::set('webdav_lang_path_root',$langKeys,3600);
		return $langKeys;
	}
	
	/**
	 * 向下回溯路径;
	 */
	private function pathInfoDeep($parent,$pathArr){
		$list = $this->pathListMerge($parent);
		$itemArr = array_to_keyvalue($list,'name');
		$item = $itemArr[$pathArr[0]];
		if(!$item) return false;
		if(count($pathArr) == 1) return $item['path'];
		
		$pathAppend = implode('/',array_slice($pathArr,1));
		$newPath = KodIO::clear($item['path'].'/'.$pathAppend);
		$info = IO::infoFull($newPath);
		if($info) return $info['path'];

		$parent = Action('explorer.list')->path($item['path']);
		$result  = $this->pathInfoDeep($parent,array_slice($pathArr,1));
		if(!$result){
			$result = $newPath;
			//虚拟目录追; 没找到字内容;则认为不存在;
			if(Action('explorer.auth')->pathOnlyShow($item['path']) ){
				$result = false;
			}
		}
		return $result;
	}
	
	public function pathInfo($path){
		return IO::info($path);
	}
	protected function pathEtag($path){
		$info = IO::infoFull($path);
		if(!$info || !is_array($info)) $info = $this->pathInfo($path);
		if(!$info || !is_array($info)) return false;
		return $this->itemEtag($info);
	}
	protected function itemEtag($item){
		if(!defined('KOD_NEXTCLOUD_COMPAT') || !KOD_NEXTCLOUD_COMPAT || _get($item,'type') != 'folder'){
			return parent::itemEtag($item);
		}
		$sourceID = isset($item['sourceID']) ? $item['sourceID']:'';
		$type = isset($item['type']) ? $item['type']:'';
		$size = isset($item['size']) ? $item['size']:0;
		$version = _get($item,'metaInfo.webdavEtag');
		$children = _get($item,'children.fileNum','').':'._get($item,'children.folderNum','');
		$mtime = isset($item['modifyTime']) ? $item['modifyTime']:'';
		$tree = $this->nextcloudFolderTreeSignature($item);
		return md5(implode('|',array($sourceID,$type,$mtime,$size,$children,$version,$tree)));
	}
	private function nextcloudFolderTreeSignature($item){
		$sourceID = intval(_get($item,'sourceID'));
		if(!$sourceID) return _get($item,'metaInfo.webdavEtag','');
		$cacheKey = 'webdav_nextcloud_compat_tree_sig_'.$sourceID;
		$cached = Cache::get($cacheKey);
		if($cached) return $cached;
		$whereAll = array(
			'isDelete' => 0,
			'parentLevel' => array('like','%,'.$sourceID.',%'),
		);
		$whereDirect = array(
			'isDelete' => 0,
			'parentID' => $sourceID,
		);
		$direct = intval(Model('Source')->where($whereDirect)->count());
		$summary = Model('Source')->field(array(
			'count(*)' => 'total',
			'sum(size)' => 'sumSize',
			'max(createTime)' => 'maxCreate',
			'max(modifyTime)' => 'maxModify',
		))->where($whereAll)->find();
		$total = intval(_get($summary,'total'));
		$sumSize = intval(_get($summary,'sumSize'));
		$maxCreate = intval(_get($summary,'maxCreate'));
		$maxModify = intval(_get($summary,'maxModify'));
		$value = implode(':',array($sourceID,$direct,$total,$sumSize,$maxCreate,$maxModify));
		Cache::set($cacheKey,$value,1);
		return $value;
	}
	protected function afterWrite($path,$destPath=false,$action=''){
		$paths = array();
		if($action == 'DELETE'){
			$paths[] = IO::pathFather($path);
		}else if($action == 'MOVE'){
			$paths[] = IO::pathFather($path);
			$paths[] = $destPath;
			$paths[] = IO::pathFather($destPath);
		}else if($action == 'COPY'){
			$paths[] = $destPath;
			$paths[] = IO::pathFather($destPath);
		}else{
			$paths[] = $path;
			$paths[] = IO::pathFather($path);
		}
		$this->etagBumpPaths($paths);
		return true;
	}
	private function etagBumpPaths($paths){
		$done = array();
		foreach($paths as $path){
			if(!$path || isset($done[$path])) continue;
			$done[$path] = true;
			$info = IO::infoFull($path);
			if(!$info || !$info['sourceID']) continue;
			$value = time().'.'.rand_string(8);
			Model("Source")->metaSet($info['sourceID'],'webdavEtag',$value);
		}
	}
	protected function itemPermissions($item){
		$path = $item['path'];
		if(defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT && !$path){
			return $item['type'] == 'folder' ? 'RGDNVCK' : 'RGDNVW';
		}
		$canShow = $this->can($path,'show') || $this->can($path,'view');
		if(!$canShow){
			if(defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT){
				return $item['type'] == 'folder' ? 'RGDNVCK' : 'RGDNVW';
			}
			return '';
		}
		$perm = 'RGV';
		if($this->can($path,'remove')) $perm .= 'D';
		if($this->can($path,'edit')) $perm .= 'N';
		if($item['type'] == 'folder'){
			if($this->can($path,'edit')) $perm .= 'CK';
		}else{
			if($this->can($path,'edit')) $perm .= 'W';
		}
		return $perm;
	}
	protected function itemLockDiscovery($item){
		$userID = _get($item,'metaInfo.systemLock');
		if(!$userID) return '<D:lockdiscovery/>';
		$time = intval(_get($item,'metaInfo.systemLockTime'));
		$timeout = max(1,3600 - (time() - $time));
		return '<D:lockdiscovery><D:activelock>'.
			'<D:locktype><D:write/></D:locktype>'.
			'<D:lockscope><D:exclusive/></D:lockscope>'.
			'<D:depth>0</D:depth>'.
			'<D:owner>'.htmlentities($userID.'').'</D:owner>'.
			'<D:timeout>Second-'.$timeout.'</D:timeout>'.
			'</D:activelock></D:lockdiscovery>';
	}
	
	public function can($path,$action){
		$result = Action('explorer.auth')->fileCan($path,$action);
		// 编辑;则检测当前存储空间使用情况;
		if($result && $action == 'edit'){
			$result = Action('explorer.auth')->spaceAllow($path);
		}
		return $result;
	}
	public function pathExists($path,$allowInRecycle = false){
		$info = IO::infoFull($path);
		if(!$info) return false;
		if(!$allowInRecycle && $info['isDelete'] == '1') return false;
		return true;
	}
	
	/**
	 * 文档属性及列表;
	 * 不存在:404;存在207;  文件--该文件属性item; 文件夹--该文件属性item + 多个子内容属性
	 */
	public function pathList($path){
		if(!$path) return false;
		if(defined('KOD_NEXTCLOUD_COMPAT') && KOD_NEXTCLOUD_COMPAT && $this->nextcloudCompatRoot){
			return $this->nextcloudCompatRootList();
		}
		$info  = IO::infoFull($path);
		if(!$info && !Action('explorer.auth')->pathOnlyShow($path) ){
			return false;
		}
		
		// if($info && $info['isDelete'] == '1') return false;//回收站中; 允许复制下载等操作;
		if(!$this->can($path,'show')) return false;
		if($info && $info['type'] == 'file'){ //单个文件;
			return array('fileList'=>array($info),'current'=>$info);
		}
		
		$pathParse = KodIO::parse($path);
		// 分页大小处理--不分页; 搜索结果除外;
		if($pathParse['type'] != KodIO::KOD_SEARCH){
			$GLOBALS['in']['pageNum'] = -1;
		}
		// write_log([$path,$pathParse,$GLOBALS['in']],'test');		
		return Action('explorer.list')->path($path);
	}
	
	public function pathMkdir($pathBefore){
		$path = $this->pathCreateParent($pathBefore);
		if(!$path || !$this->can($path,'edit')) return false;
		return IO::mkdir($path);
	}
	public function pathOut($path){
		if(!$this->pathExists($path) || !$this->can($path,'view')){
			$this->response(array('code' => 404));exit;
		}
		foreach($this->etagHeader($path) as $header){
			header($header);
		}
		if(IO::size($path)<=0) return;//空文件处理;
		//部分webdav客户端不支持301跳转;
		if($this->notSupportHeader()){
			IO::fileOutServer($path); 
		}else{
			// $GLOBALS['config']['settings']['ioFileOutServer'] = 1;
			IO::fileOut($path); 
		}
	}
	// GET 下载文件;是否支持301跳转;对象存储下载走直连;
	private function notSupportHeader(){
		$software = array(
			'ReaddleDAV Documents',	// ios Documents 不支持;
			'GstpClient',			// goodsync 同步到对象存储问题
		);
		$ua = $_SERVER['HTTP_USER_AGENT'];
		foreach ($software as $type){
			if(stristr($ua,$type)) return true;
		}
		return false;
	}
	
	// 收藏夹下文件夹处理;(新建,上传)
	private function pathCreateParent($path){
		if($path) return $path;
		$inPath  = $this->pathGet();
		if(IO::pathFather($inPath) == '.recycle') return false;
		$pathFather = rtrim($this->parsePath(IO::pathFather($inPath)),'/');
		return $pathFather.'/'.IO::pathThis($inPath);
	}
	
	public function pathPut($path,$localFile=''){
		$pathBefore = $path;
		$path = $this->pathCreateParent($path);
		if(!$path || !$this->can($path,'edit')) return false;
		$name = IO::pathThis($this->pathGet());
		$info = IO::infoFull($path);
		if($info){	// 文件已存在; 则使用文件父目录追加文件名;
			$uploadPath = rtrim(IO::pathFather($info['path']),'/').'/'.$name; //构建上层目录追加文件名;
		}else{
			// 首次请求创建,文件不存在; 则使用{source:xx}/newfile.txt; 自动创建文件夹: /src/aa/s.txt => / [文件夹不存在时]
			$pathFatherStr = get_path_father($path);
			$pathFather    = IO::mkdir($pathFatherStr); 
			$uploadPath    = rtrim($pathFather,'/').'/'.$name;
			$this->plugin->log("pathPut-mkdir:pathFatherStr=$pathFatherStr;pathFather=$pathFather;uploadPath=$uploadPath");
			//$uploadPath = $path;
		}
		$this->pathPutCheckKod($uploadPath);

		// 传入了文件; wscp等直接一次上传处理的情况;  windows/mac等会调用锁定,解锁,判断是否存在等之后再上传;
		// 文件夹下已存在,或在回收站中处理;
		// 删除临时文件; mac系统生成两次 ._file.txt;
		$size = 0;
		if($localFile){
			$size = filesize($localFile);
			$result = $this->pathPutUpload($uploadPath,$localFile);
			// $result = IO::move($localFile,$uploadPath,REPEAT_REPLACE);
			$resultPath = is_string($result) ? $result : $uploadPath;
			$uploadInfo = $result ? self::pathPutUploadLocateInfo($resultPath,$size) : false;
			if($result && !$uploadInfo && $resultPath != $uploadPath){
				$uploadInfo = self::pathPutUploadLocateInfo($uploadPath,$size);
			}
			if($result && (!$uploadInfo || intval(_get($uploadInfo,'size')) != $size)){
				$this->plugin->log("upload failed verify: uploadPath=$uploadPath;resultPath=$resultPath;local=$localFile;size=".$size.';remoteSize='.(is_array($uploadInfo) ? intval(_get($uploadInfo,'size')) : -1).';lastError='.json_encode(IO::getLastError()));
				$result = false;
			}
			$this->pathPutRemoveTemp($uploadPath);
		}else{
			if(!$info){ // 不存在,创建;
				$result = IO::mkfile($uploadPath,'',REPEAT_REPLACE);
			}
			$result = true;	
		}
		if($result){$this->pathPutMtime($uploadPath);}
		$this->plugin->log("upload=$uploadPath;path=$path,$pathBefore;res=$result;local=$localFile;size=".$size);
		return $result ? $uploadPath : false;
	}
	private function pathPutUpload($uploadPath,$localFile){
		self::pathPutUploadShutdownRegister();
		$oldUploadPath = array_key_exists('KOD_WEBDAV_UPLOAD_PATH',$GLOBALS) ? $GLOBALS['KOD_WEBDAV_UPLOAD_PATH'] : null;
		$oldCapture = array_key_exists('KOD_WEBDAV_CAPTURE_UPLOAD',$GLOBALS) ? $GLOBALS['KOD_WEBDAV_CAPTURE_UPLOAD'] : null;
		$oldNotExit = array_key_exists('SHOW_JSON_NOT_EXIT',$GLOBALS) ? $GLOBALS['SHOW_JSON_NOT_EXIT'] : null;
		$oldNotExitDone = array_key_exists('SHOW_JSON_NOT_EXIT_DONE',$GLOBALS) ? $GLOBALS['SHOW_JSON_NOT_EXIT_DONE'] : null;

		$GLOBALS['KOD_WEBDAV_UPLOAD_PATH'] = $uploadPath;
		$GLOBALS['KOD_WEBDAV_CAPTURE_UPLOAD'] = true;
		$GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN'] = array(
			'path' => $uploadPath,
			'localFile' => $localFile,
		);
		$GLOBALS['SHOW_JSON_NOT_EXIT'] = true;
		unset($GLOBALS['SHOW_JSON_NOT_EXIT_DONE']);

		$result = false;
		$output = '';
		ob_start();
		try{
			$result = IO::upload($uploadPath,$localFile,true,REPEAT_REPLACE);
			$output = ob_get_clean();
		}catch(Throwable $e){
			$output = ob_get_clean();
			$output .= $e->getMessage();
		}

		$this->pathPutUploadRestore('KOD_WEBDAV_UPLOAD_PATH',$oldUploadPath);
		$this->pathPutUploadRestore('KOD_WEBDAV_CAPTURE_UPLOAD',$oldCapture);
		unset($GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN']);
		$this->pathPutUploadRestore('SHOW_JSON_NOT_EXIT',$oldNotExit);
		$this->pathPutUploadRestore('SHOW_JSON_NOT_EXIT_DONE',$oldNotExitDone);

		$json = $this->pathPutUploadJson($output);
		if(!$result && is_array($json) && !empty($json['code'])){
			$result = $uploadPath;
		}
		if($output !== ''){
			$this->plugin->log('webdav upload captured output: path='.$uploadPath.';len='.strlen($output).';success='.(is_array($json) && !empty($json['code']) ? 1:0));
		}
		if(!$result && is_array($json)){
			$this->lastError = is_string($json['data']) ? $json['data'] : json_encode_force($json['data']);
		}
		return $result;
	}
	private static function pathPutUploadShutdownRegister(){
		if(!empty($GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN_REGISTERED'])) return;
		$GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN_REGISTERED'] = true;
		register_shutdown_function(array('webdavServerKod','pathPutUploadShutdownResponse'));
	}
	public static function pathPutUploadShutdownResponse(){
		if(empty($GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN']) || !is_array($GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN'])) return;
		$data = $GLOBALS['KOD_WEBDAV_UPLOAD_SHUTDOWN'];
		$uploadPath = isset($data['path']) ? $data['path'] : '';
		$localFile = isset($data['localFile']) ? $data['localFile'] : '';
		$localSize = ($localFile && is_file($localFile)) ? @filesize($localFile) : 0;
		$info = false;
		for($i = 0;$i < 10;$i++){
			$info = ($uploadPath && class_exists('IO')) ? self::pathPutUploadLocateInfo($uploadPath,$localSize) : false;
			if(is_array($info) && ($localSize <= 0 || intval(_get($info,'size')) == $localSize)) break;
			usleep(200000);
		}
		$remoteSize = is_array($info) ? intval(_get($info,'size')) : -1;
		$ok = is_array($info) && ($localSize <= 0 || $remoteSize == $localSize);
		if(function_exists('write_log')){
			write_log('webdav upload shutdown: ok='.($ok ? 1 : 0).';path='.$uploadPath.';localSize='.$localSize.';remoteSize='.$remoteSize.';error='.json_encode(error_get_last()),'webdavNextcloud');
		}
		while(ob_get_level() > 0){
			@ob_end_clean();
		}
		if(headers_sent()) return;
		$etagSeed = $uploadPath.'|'.time();
		if(is_array($info)){
			$etagSeed = implode('|',array(
				_get($info,'sourceID'),
				_get($info,'modifyTime'),
				_get($info,'size'),
				_get($info,'fileInfo.hashMd5'),
				_get($info,'hashMd5'),
			));
		}
		$etag = md5($etagSeed);
		header($ok ? 'HTTP/1.1 201 Created' : 'HTTP/1.1 503 Service Unavailable');
		header('ETag: "'.$etag.'"');
		header('OC-ETag: "'.$etag.'"');
		header('Content-Length: 0');
		header('X-DAV-BY: kodbox-shutdown');
		if(!$ok){header('X-DAV-ERROR: shutdown-verify-failed');}
	}
	private static function pathPutUploadLocateInfo($uploadPath,$localSize = 0){
		if(!$uploadPath || !class_exists('IO')) return false;
		$info = IO::infoFull($uploadPath);
		if(is_array($info) && ($localSize <= 0 || intval(_get($info,'size')) == intval($localSize))) return $info;
		$parent = IO::pathFather($uploadPath);
		$name = IO::pathThis($uploadPath);
		if(!$parent) $parent = get_path_father($uploadPath);
		if(!$name) $name = get_path_this($uploadPath);
		if(!$parent || !$name) return $info;
		$list = IO::listPath($parent);
		if(!is_array($list)) return $info;
		$found = self::pathPutUploadFindInList($list,$name,$localSize);
		return $found ? $found : $info;
	}
	private static function pathPutUploadFindInList($list,$name,$localSize = 0){
		foreach($list as $key => $value){
			if(!is_array($value)) continue;
			if(isset($value['name']) && $value['name'] === $name){
				if($localSize <= 0 || intval(_get($value,'size')) == intval($localSize)) return $value;
			}
			$found = self::pathPutUploadFindInList($value,$name,$localSize);
			if($found) return $found;
		}
		return false;
	}
	private function pathPutUploadRestore($key,$value){
		if($value === null){
			unset($GLOBALS[$key]);
		}else{
			$GLOBALS[$key] = $value;
		}
	}
	private function pathPutUploadJson($output){
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
	private function pathPutMtime($path){
		$mtime = _get($_SERVER,'HTTP_X_OC_MTIME');
		if(!$mtime) return;
		$mtime = intval($mtime);
		if($mtime <= 1000 || $mtime > time() + 86400) return;
		IO::setModifyTime($path,$mtime);
	}
	private function pathPutRemoveTemp($path){
		$pathArr = explode('/',$path);
		$pathArr[count($pathArr) - 1] = '._'.$pathArr[count($pathArr) - 1];
		$tempPath = implode('/',$pathArr);
		
		$tempInfo = IO::infoFull($tempPath);
		if($tempInfo && $tempInfo['type'] == 'file'){
			IO::remove($tempInfo['path'],false);
		}
	}
	
	// kodbox 挂载链接
	private function pathPutCheckKod($uploadFile){
		if($_SERVER['HTTP_X_DAV_UPLOAD'] != 'kodbox') return;
		if(!$_SERVER['HTTP_X_DAV_ARGS']) return;
		
		$args = json_decode(base64_decode($_SERVER['HTTP_X_DAV_ARGS']),true);
		if(!is_array($args)) return false;
		$io   = IO::init('/');
		$info = array(
			'name' => $io->pathThis($uploadFile),
			'path' => $io->pathFather($uploadFile)
		);
		if($args['uploadWeb'] && $args['checkType'] == 'checkHash'){
			// 前端上传文件夹,层级处理; eg: /self/a1/a2/a3.txt ; fullPath: /a1/a2/a3.txt ===> /self/
			$fullPath = $args['fullPath'] ? $args['fullPath']:'';
			$fullArr  = explode('/', trim($fullPath,'/'));
			if(count($fullArr) > 1){
				$uriArr = explode('/', trim($this->pathGet(),'/'));
				$uriArr = array_slice($uriArr,0,count($uriArr) - count($fullArr));
				$info['path'] = $this->parsePath('/'.implode('/',$uriArr).'/');
			}
			
			$argsCheck = array('path'=>$info['path']);//,'size'=>$args['size']
			$link = Action('user.index')->apiSignMake('explorer/upload/fileUpload',$argsCheck,false,false,true);
			$info['addUploadParam'] = $link;
		}
		$GLOBALS['in'] = array_merge($GLOBALS['in'],$args,$info);
		Action('explorer.upload')->fileUpload();exit;
	}
	
	public function pathRemove($path){
		if(!$this->can($path,'remove')) return false;
		$tempInfo = IO::infoFull($path);
		if(!$tempInfo) return true;
		
		$toRecycle = Model('UserOption')->get('recycleOpen');
		if($tempInfo['isDelete'] == '1'){$toRecycle = false;}
		return IO::remove($tempInfo['path'], $toRecycle);
	}
	public function pathMove($path,$dest){
		$pathUrl = $this->pathGet();
		$destURL = $this->pathGet(true);		
		$path 	= $this->parsePath($pathUrl);
		$dest   = $this->parsePath(IO::pathFather($destURL)); //多出一层-来源文件(夹)名
		$this->plugin->log("from=$path;to=$dest;$pathUrl;$destURL");

		// 目录不变,重命名,(编辑文件)
		$io = IO::init('/');
		if($io->pathFather($pathUrl) == $io->pathFather($destURL)){
			if(!$this->can($path,'edit')) return false;
			$destFile = rtrim($dest,'/').'/'.$io->pathThis($destURL);
			$this->plugin->log("edit=$destFile;exists=".intval($this->pathExists($destFile)));

			/**
			 * office 编辑保存最后落地时处理（导致历史记录丢失）
			 * window下文件保存处理(office文件保存时 file=>file.tmp 不做该操作,避免历史版本丢失)
			 * 
			 * 0. 上传~tmp1601041332501525796.TMP //锁定,上传,解锁;
			 * 1. 移动 test.docx => test~388C66.tmp 				// 改造,识别到之后不进行移动重命名;
			 * 2. 移动 ~tmp1601041332501525796.TMP => test.docx; 	// 改造;目标文件已存在则更新文件;删除原文件;
			 * 3. 删除 test~388C66.tmp  
			 * 
			 * window + raidrive + wps编辑
			 *      delete ~$file.docx
             *      put    ~$file.docx
             *      put    ~tmpxxx.TMP
             *      delete ~$file.docx
             *      move   file.docx   file~xxx.tmp
             *      move   ~tmpxxx.TMP file.docx
             *      delete file~xxx.tmp
			 */
			$fromFile 	= $io->pathThis($pathUrl);
			$toFile 	= $io->pathThis($destURL);
			$fromExt 	= get_path_ext($pathUrl);
			$toExt   	= get_path_ext($destURL);// 误判情况: 将xx/aa.docx 移动到xx/aa~xxx.tmp会失败;
			$officeExt 	= array('doc','docx','xls','xlsx','ppt','pptx');
			if( $toExt == 'tmp' && in_array($fromExt,$officeExt) && strstr($toFile,'~')){
				$result =  IO::mkfile($destFile);
			    $this->plugin->log("move mkfile=$path;$pathUrl;$destURL;result=".$result);
			    return $result;
			}
			// 都存在则覆盖；
			if( $this->pathExists($path,true) && $this->pathExists($destFile) ){
				$destFileInfo = IO::infoFull($destFile);

				// $content = IO::getContent($path);
				// IO::setContent($destFileInfo['path'],$content);
				// IO::remove($path);$result = $destFileInfo['path'];
				$result  = IO::saveFile($path,$destFileInfo['path']);//覆盖保存;
				$this->plugin->log("move saveFile; to=$path;toFile=".$destFileInfo['path'].';result='.$result);
				return $result;
			}
			return IO::rename($path,$io->pathThis($destURL));
		}
		
		if(!$this->can($path,'remove')) return false;
		if(!$this->can($dest,'edit')) return false;
		
		// 名称不同先重命名;
		if( $io->pathThis($destURL) != $io->pathThis($pathUrl) ){
			$path = IO::rename($path,$io->pathThis($destURL));
		}
		return IO::move($path,$dest);
	}
	public function pathCopy($path,$dest){
		$pathUrl = $this->pathGet();
		$destURL = $this->pathGet(true);		
		$path 	= $this->parsePath($pathUrl);
		$dest   = $this->parsePath(IO::pathFather($destURL)); //多出一层-来源文件(夹)名
		$this->plugin->log("from=$path;to=$dest;$pathUrl;$destURL");

		if(!$this->can($path,'download')) return false;
		if(!$this->can($dest,'edit')) return false;
		
		$fromName = get_path_this($pathUrl); 
		$destName = get_path_this($destURL);
		$destName = $fromName != $destName ? $destName : '';
		return IO::copy($path,$dest,false,$destName);
	}
	
	// 上传临时目录; 优化: 默认存储io为本地时,临时目录切换到对应目录的temp/下;(减少从头temp读取->写入到存储i)
	public function uploadFileTemp(){
		$tempPath = TEMP_FILES;
		$path = $this->pathCreateParent();// 上传到目录转换; /dav/test/1.txt=> {source:23}/1.txt;
		$driverInfo = KodIO::pathDriverType($path);
		if($driverInfo && $driverInfo['type'] == 'local'){
			$truePath = rtrim($driverInfo['path'],'/').'/';
			$isSame = KodIO::isSameDisk($truePath,TEMP_FILES);
			if(!$isSame && file_exists($truePath)){$tempPath = $truePath;}
		}
		
		if(!file_exists($tempPath)){
			@mk_dir($tempPath);
			touch($tempPath.'index.html');
		}
		return $tempPath;
	}
	
	// 文件编辑锁添加或移除;(office/wps: 打开编辑时会添加; 保存时会添加/解除; 关闭文件时会解锁)
	public function fileLock($path){
		if(!IO::infoFull($path)){
		    $path = IO::mkfile($path);// 不存在时,自动创建文件;
		}
		
		$info = $this->fileLockCheck($path);		
		$lock = $this->fileLockAllow($path, $info);
		if(!$lock) return;
		
		$this->fileLockCache($path, $lock, $info);
		Model("Source")->metaSet($info['sourceID'],'systemLock',USER_ID);
		Model("Source")->metaSet($info['sourceID'],'systemLockTime',time());		
	}
	public function fileUnLock($path){
		$info = $this->fileLockCheck($path);if(!$info) return;
		if($this->fileLockCache($path)) return;
		if(!$this->fileLockAllow($path, $info)) return;
		Model("Source")->metaSet($info['sourceID'],'systemLock',null);
		Model("Source")->metaSet($info['sourceID'],'systemLockTime',null);
	}
	private function fileLockCheck($path){
		$info = IO::infoFull($path);
		if(!$info || !$info['sourceID'] || !USER_ID) return;
		if(!$this->can($path,'edit')) return;
		return $info;
	}
	// 判断文件是否已加锁：未加锁=>true；已加锁：自己=>userID；他人=>false
	private function fileLockAllow($path, $info) {
		$isLock = _get($info, 'metaInfo.systemLock');
		if(!$isLock) return true;	// 未被锁定
		return $isLock == USER_ID ? $isLock : false;	// 被自己、别人锁定
	}
	private function fileLockCache($path, $lock=false, $info=false) {
		$key = md5('before_webdav_locked_'.USER_ID.'_'.$path);
		// 获取缓存：是否为自己手动锁定
		if(!$lock){return Cache::get($key);}
		// 未锁定(true)：删除可能的缓存
		if($lock === true){return Cache::remove($key);}
		Cache::set($key, 1);
	}
}
