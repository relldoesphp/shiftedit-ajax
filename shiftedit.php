<?php
/*
Used by ShiftEdit.net to connect to server and perform file ops over http
Author: Adam Jimenez <adam@shiftcreate.com>
Last Modified: 06/03/2014
URL: https://github.com/adamjimenez/shiftedit-ajax

Edit the username and password below to enable authentication
*/

//config
$username = 'test';
$password = 'test';
$dir = dirname(__FILE__).'/';

//restrict access by ip
$ip_restrictions = false;

//allowed ips. get your ip from https://www.google.co.uk/search?q=ip+address
$ips = array('');

//api version
$version = '1.01';

//cors origin
$origin = 'https://shiftedit.net';

//set error level
error_reporting(E_ALL ^ E_NOTICE);

// CORS Allow from shiftedit
if (isset($_SERVER['HTTP_ORIGIN'])) {
	header('Access-Control-Allow-Origin: '.$origin);
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Max-Age: 86400');
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])){
		header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
	}

	if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])){
		header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
	}
	exit;
}

//ip restrictions
if( $ip_restrictions and !in_array($_SERVER['REMOTE_ADDR'], $ips) ){
    die('{"success":false,"error":"access denied"}');
}

//authentication
if( $username ){
    if( $username!==$_POST['user'] or sha1($password)!==$_POST['pass'] ){
        //delay to protect against brute force attack
        sleep(1);
        
        die('{"success":false,"error":"Login incorrect"}');
    }
}

header('Content-Type: application/json, charset=utf-8');

class local{
	function local()
	{
		global $dir;
		$this->dir = $dir;
	}

	function chdir($path){
		if( $path === $this->pwd ){
			return true;
		}else{
			if( chdir($path) ){
				$this->pwd = $path;
				return true;
			}else{
				return false;
			}
		}
	}

	function get($remote_file)
	{
		$path = $this->dir.$remote_file;
		return file_get_contents($path);
	}

	function put($file, $content)
	{
		if( !$file ){
			return false;
		}

		$path = $this->dir.$file;
		return file_put_contents($path, $content)!==false;
	}

	function last_modified($file)
	{
		$file=$this->dir.$file;
		return filemtime($file);
	}

	function is_dir($dir)
	{
		$dir = $this->dir.$dir;
		return is_dir($dir);
	}

	function file_exists($file)
	{
		$file = $this->dir.$file;
		return file_exists($file);
	}

	function chmod($mode,$file)
	{
		$file=$this->dir.$file;
		return chmod($mode, $file);
	}

	function rename($old_name, $new_name)
	{
		$old_name = $this->dir.$old_name;
		$new_name = $this->dir.$new_name;
		return rename($old_name, $new_name);
	}

	function mkdir($dir)
	{
		$dir = $this->dir.$dir;
		return mkdir($dir);
	}

	function delete($file)
	{
		if( !$file ){
			$this->log[] = 'no file';
			return false;
		}

		$path = $this->dir.$file;

		if( $this->is_dir($file) ){
			$list = $this->parse_raw_list($file);
			foreach ($list as $item){
				if( $item['name'] != '..' && $item['name'] != '.' ){
					$this->delete($file.'/'.$item['name']);
				}
			}

			chdir('../');

			if( !rmdir($path) ){
				$this->log[]= 'rmdir '.$this->pwd;
				return false;
			}else{
				return true;
			}
		}else{
			if( $this->file_exists($file) ){
				$this->log[]='delete '.$path;
				return unlink($path);
			}
		}
	}

	function chmod_num($permissions)
	{
		$mode = 0;

		if ($permissions[1] == 'r') $mode += 0400;
		if ($permissions[2] == 'w') $mode += 0200;
		if ($permissions[3] == 'x') $mode += 0100;
		else if ($permissions[3] == 's') $mode += 04100;
		else if ($permissions[3] == 'S') $mode += 04000;

		if ($permissions[4] == 'r') $mode += 040;
		if ($permissions[5] == 'w') $mode += 020;
		if ($permissions[6] == 'x') $mode += 010;
		else if ($permissions[6] == 's') $mode += 02010;
		else if ($permissions[6] == 'S') $mode += 02000;

		if ($permissions[7] == 'r') $mode += 04;
		if ($permissions[8] == 'w') $mode += 02;
		if ($permissions[9] == 'x') $mode += 01;
		else if ($permissions[9] == 't') $mode += 01001;
		else if ($permissions[9] == 'T') $mode += 01000;

		return sprintf('%o', $mode);
	}

	function parse_raw_list( $path )
	{
		$path = $this->dir.$path;

		if( $path and !$this->chdir($path) ){
			return false;
		}

		$d = dir($path);

		if( $d === false ){
			return false;
		}

		$items=array();

		while (false !== ($entry = $d->read())) {
			$items[] = array(
				'name' => $entry,
				'permsn' => substr(decoct( fileperms($entry) ), 2),
				'size' => filesize($entry),
				'modified' => filemtime($entry),
				'type' => is_dir($entry) ? 'folder' : 'file',
			);
		}
		$d->close();

		return $items;
	}

	function search_nodes($s, $path, $file_extensions)
	{
		$list = $this->parse_raw_list($path);

		if( !$list ){
			return array();
		}

		$items = array();

		foreach( $list as $v ){
			if( $v['type']!='file' ){
				if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
					continue;
				}

				$arr = $this->search_nodes($s, $path.$v['name'].'/', $file_extensions);
				$items = array_merge($items,$arr);
			}else{
				if( strstr($v['name'], $s) and in_array(file_ext($v['name']), $file_extensions) ){
					$items[] = $path.$v['name'];
					$this->send_msg($this->startedAt , $path.$v['name']);
				}
			}
		}

		return $items;
	}

	function search($s, $path, $file_extensions)
	{
		$this->startedAt = time();
		return $this->search_nodes($s, $path, $file_extensions);
	}

	function send_msg($id , $msg) {
		echo "id: $id" . PHP_EOL;
		echo "data: {\n";
		echo "data: \"msg\": \"$msg\", \n";
		echo "data: \"id\": $id\n";
		echo "data: }\n";
		echo PHP_EOL;
		ob_flush();
		flush();
	}
}

function basename_safe($path){
	if( mb_strrpos($path, '/')!==false ){
		return mb_substr($path, mb_strrpos($path, '/')+1);
	}else{
		return $path;
	}
}

function file_ext($file){
	return strtolower(end(explode('.', $file)));
}

function so($a, $b) //sort files
{
	if( $a['leaf']==$b['leaf'] ){
		return (strcasecmp($a['text'],$b['text']));
	}else{
		return $a['leaf'];
	}
}

function get_nodes($path, $paths)
{
	global $server;

	if( !$paths ){
		$paths = array();
	}

	$list = $server->parse_raw_list($path);

	if( $list === false ){
		return false;
	}

	$files = array();

	$i=0;
	foreach( $list as $v ){
		if( $v['type'] != 'file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' or $v['name']=='' ){
				continue;
			}

			$files[$i] = array(
				'text' => basename_safe($v['name']),
				'iconCls' => 'folder',
				'disabled' => false,
				'leaf' => false,
				'perms' => $v['permsn'],
				'modified' =>  $v['modified'],
				'size' => '',
				'id' => $path.$v['name']
			);

			// which paths to preload
			$subdir = $path.$v['name'].'/';

			$expand = false;

			foreach( $paths as $p ){
				if( substr($p, 0, strlen($path.$v['name'])+1) == $path.$v['name'].'/' ){
					$expand=true;
					break;
				}
			}

			if( $expand	){
				$files[$i]['expanded']=true;

				if( $_GET['root']!=='false' ){
					//$files[$i]['children']=get_nodes($path.$v['name'].'/', $paths);
				}
			}
		}else{
			$ext = file_ext(basename_safe($v['name']));

			if( $ext == 'lck' ){
				continue;
			}

			$files[$i] = array(
				'text' => basename_safe($v['name']),
				'iconCls' => 'file-'.$ext,
				'disabled' => false,
				'leaf' => true,
				'perms' => $v['permsn'],
				'modified' =>  $v['modified'],
				'size' => $v['size'],
				'id' => $path.$v['name']
			);
		}

		$i++;
	}

	usort($files, 'so');
	return $files;
}

function list_nodes($path)
{
	global $server, $server_src, $id;

	$list = $server_src->parse_raw_list($path);

	if( !$list ){
		return array();
	}

	$items=array();

	foreach( $list as $v ){
		if( $v['name']=='' ){
			continue;
		}

		if( $v['type']!='file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
				continue;
			}

			$items[]=array(
				'path'=>$path.$v['name'],
				'isDir'=>true
			);

			$arr=list_nodes($path.$v['name'].'/',$dest.'/'.$v['name']);

			$items=array_merge($items,$arr);
		}else{
			$items[]=array(
				'path'=>$path.$v['name'],
				'isDir'=>false
			);
		}
	}

	return $items;
}

function copy_nodes($path,$dest)
{
	global $server,$server_src,$id;

	$list = $server_src->parse_raw_list($path);

	if( $list===false ){
		return false;
	}

	$server->mkdir($dest);

	$i=0;
	foreach( $list as $v ){
		if( $v['type']!='file' ){
			if( $v['name']=='.' or $v['name']=='..' or $v['name']=='.svn' ){
				continue;
			}

			copy_nodes($path.$v['name'].'/',$dest.'/'.$v['name']);
		}else{
			$content=$server_src->get($path.'/'.$v['name']);
			$server->put($dest.'/'.$v['name'],$content);
		}

		$i++;
	}
}

if( $_POST['path'] == 'root' ){
	$_POST['path'] = '';
}

$site = $_GET['site'];

if( $_POST['server_type'] ){
	$options = array(
		'site'=>$_POST
	);
}

$server = new local();

switch( $_POST['cmd'] ){
    case 'test':
        $files = $server->parse_raw_list('/');
        $response['success'] = $files!==false;
		print json_encode($response);
    break;

	case 'save':
		if( $server->put($_POST['file'], $_POST['content']) ){
		    $response['success'] = true;
		}else{
		    $response['success'] = false;
		    $response['error'] = 'Failed saving '.$_POST['file'];
		}
		
		print json_encode($response);
	break;

	case 'open':
		$response['content'] = $server->get($_POST['file']);
		$response['success'] = true;
		print json_encode($response);
	break;

	case 'get':
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$files=array();

		//$_POST['path']=substr($_POST['path'],1);

		if( $_POST['path'] == '/' ){
			$_POST['path'] = '';
		}

		if( $_POST['path']=='' and $_GET['path'] ){ //used by save as
			$files = get_nodes($_POST['path'], array(dirname($_GET['path']).'/'));
		}else{ //preload paths
			$files = get_nodes($_POST['path'], $paths);
		}

		//include root
		if( $_POST['path'] == '' and $_GET['root']==='false' ){
			$root[0] = array(
				'text' => $site['dir'],
				'iconCls' => 'folder',
				'disabled' => false,
				'leaf' => false,
				'modified' => '',
				'size' => '',
				'expanded' => true,
				'children'=>$files
			);

			$files = $root;
		}

		echo json_encode($files);
	break;

	case 'list':
		if( $_POST['path'] and substr($_POST['path'],-1)!=='/' ){
			$_POST['path'].='/';
		}

		$server_src = $server;

		$response = array();
		$response['success'] = true;
		$response['files'] = list_nodes($_POST['path']);

		print json_encode($response);
	break;

	case 'rename':
		$old_name = $_POST['oldname'];
		$new_name = $_POST['newname'];

		if( $server->rename($old_name, $new_name) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot rename file"}';
		}
	break;

	case 'newdir':
		$dir=$_POST['dir'];

		if( $server->mkdir($dir) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot create directory"}';
		}
	break;

	case 'newfile':
		$content='';

		if( $server->put($_POST['file'], $content) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot create file"}';
		}
	break;

	case 'duplicate':
	case 'paste':
		if( !$_POST['dest'] or !$_POST['path'] ){
			echo '{"success":false,"error":"Cannot create file"}';
		}else{

			if( $_POST['site'] and $_POST['site']!=$_GET['site'] and $_POST['isDir']!=='true' ){
				$server_src = open_site($_POST['site']);
			}else{
				$server_src = $server;
			}

			if( $_POST['isDir']=="true" ){
				if( $_POST['dest'] and $server->file_exists($_POST['dest']) ){
					echo '{"success":true}';
				}elseif( $_POST['dest'] and $server->mkdir($_POST['dest']) ){
					echo '{"success":true}';
				}else{
					echo '{"success":false,"error":"Cannot create folder: '.$_POST['dest'].'"}';
				}
			}else{
				$content = $server_src->get($_POST['path']);

				if( $content === false ){
					echo '{"success":false,"error":"Cannot read file: '.$_POST['path'].'"}';
				}elseif(  $_POST['dest'] and $server->put($_POST['dest'], $content) ){
					echo '{"success":true}';
				}else{
					echo '{"success":false,"error":"Cannot create file: '.$_POST['dest'].'"}';
				}
			}

			if( $_POST['path'] and $_POST['cut']=='true' ){
				$server_src->delete($_POST['path']);
			}
		}
	break;

	case 'delete':
		$file = $_POST['file'];

		if( !$server->is_dir($file) ){
			if( $server->delete($file) ){
				echo '{"success":true}';
			}else{
				echo '{"success":false,"error":"Cannot delete file: '.end($server->log).'"}';
			}
		}else{
			if( $server->delete($file) ){
				echo '{"success":true}';
			}else{
				echo '{"success":false,"error":"Cannot delete directory: '.end($server->log).'"}';
			}
		}
	break;

	case 'upload':
		$response=array();

		$response['success']=true;

		if( isset($_POST['file']) and isset($_POST['content']) ){
			$content = $_POST['content'];

			if( substr($content,0,5)=='data:' ){
				$pos = strpos($content, 'base64');

				if( $pos ){
					$content = base64_decode(substr($content, $pos+6));
				}
			}

			if( strstr($_POST['file'],'.') ){
				if( $server->put($_POST['file'], $content) ){
					//success
				}else{
					$response['success']=false;

					$response['error'] = 'Can\'t create file '.$file['name'];
				}
			}else{
				if( $server->mkdir($_POST['file']) ){
					//success
				}else{
					$response['success']=false;

					$response['error'] = 'Can\'t create folder '.$file['name'];
				}
			}
		}else{
			foreach( $_FILES as $key=>$file ){
				if( $file['error'] == UPLOAD_ERR_OK ){
					$content = file_get_contents($file['tmp_name']);

					if( $server->put($_POST['path'].'/'.$file['name'], $content) ){
						//success
					}else{
						$response['success']=false;

						$response['error']='Can\'t create file '.$file['name'];
					}
				}else{
					$response['success']=false;
					$response['error'] = $error.' '.$file['name'];
				}
			}
		}

		print json_encode($response);
	break;

	case 'chmod':
		$file=$_POST['file'];

		if( $server->chmod(intval($_POST['mode'], 8), $file) ){
			echo '{"success":true}';
		}else{
			echo '{"success":false,"error":"Cannot chmod file"}';
		}
	break;

	case 'uploadByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['success']=false;
			$response['error']='Invalid URL';
		}else{
			$content = file_get_contents($_POST['url']);

			$file = basename_safe($_POST['url']);

			if( !$file ){
				$response['success']=false;

				$response['error']='Missing file name';
			}else{
				if( $server->put($_POST['path'].'/'.$file, $content) ){
					//success
					$response['success']=true;
				}else{
					$response['success']=false;
					$response['error']='Can\'t save file';
				}
			}
		}

		print json_encode($response);
	break;

	case 'saveByURL':
		if( substr($_POST['url'],0,7)!='http://' && substr($_POST['url'],0,8)!='https://' ){
			$response['success']=false;

			$response['error']='Invalid URL';
		}else{
			$content=file_get_contents($_POST['url']);

			if( $server->put($_POST['path'], $content) ){
				//success
				$response['success']=true;
			}else{
				$response['success']=false;
				$response['error']='Can\'t save file';
			}
		}

		print json_encode($response);
	break;

	case 'extract':
		echo '{"success":false,"error":"Not supported"}';
	break;
	default:
		echo '{"success":false,"error":"No command"}';
	break;
}
?>
