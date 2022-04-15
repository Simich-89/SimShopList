<?
class sysHelper 
{
	private $mysqli=null;
	
	private $codeStrings = array (
		-100 => "Not auth",
		-101 => "No such user",
		-102 => "Wrong password",
		-103 => "Such user already exists",

		-901 => "Data base error",
		-988 => "Bad params",
		-999 => "Wrong action"
	);
	
	function __construct($userId=null) { $this->openConn(); }
	function __destruct() { $this->closeConn(); }

	public function add2Log($text) { $text = date('Y-m-d H:i:s').' - '.$text."\n"; file_put_contents ('log/SimShopList-'.date("ymd").'.log', $text, FILE_APPEND); }
	public function openConn() { $this->mysqli = new mysqli('localhost', 'SimShopList', 'SimShopList', 'SimShopList'); }
	public function closeConn() { if ($this->mysqli) $this->mysqli->close(); }
	public function sqlQuery($query, $forceLog=false) {
		$qry=$query; if (!$forceLog && strlen($qry)>400) $qry=mb_substr($qry,0, 350,"UTF-8").' ... '.mb_substr($qry,-50,NULL,"UTF-8"); $qry=str_replace("\r\n"," ",$qry); $qry=str_replace("\n"," ",$qry);
		if ($forceLog) $this->add2Log('QUERY: '.$qry.';');
		$rslt = $this->mysqli->query($query);
		if ($rslt===false) { $this->add2Log('Error MySQL: '.$this->mysqli->error."\n".'QUERY: '.$qry); return false; }
		return $rslt;
	}
	public function sqlInsertId() { return $this->mysqli->insert_id; }
	public function retErr($hc,$rc,$txt,$log=false) { 
		//header("Status: $hc"); 
		if (array_key_exists($rc,$this->codeStrings)) $txt = $this->codeStrings[$rc].' -- '.$txt;
		if ($log) $this->add2Log($txt);
		header('HTTP/1.0' . ' ' . $hc . ' ' . $txt);
		return $rc; 
	}
}

$sh=new sysHelper(); session_start();

if (isset($_SESSION['userId'])) { // if logged
	if (isset($_POST['action'])) {
		switch ($_POST['action']) {
			case 'signOut':
				$_SESSION = array();
				if (ini_get("session.use_cookies")) {
					$params = session_get_cookie_params();
					setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"] );
				}
				session_destroy();
				header("Refresh:1");
			break;
			case 'showLists': default:
				$ret='<input id="inpNamList" type="text" placeholder="List name" style="display:none;" />'
					.'<span id="pnlAddList"><input type="button" value="Add list" style="background-color:lightgreen;" onclick="apiAction(\'addList\')" /></span>'
					.'<h3>Your lists</h3>';
				if (!$rslt=$sh->sqlQuery("SELECT * FROM `lists` WHERE `owner`='".$_SESSION['userId']."'")) { return $sh->retErr(402,-901,''); }
				while ($row=$rslt->fetch_assoc()) {
					$shared='';
					if ($row['shared']!='') {
						if (!$rsltS=$sh->sqlQuery("SELECT `name` FROM `users` WHERE `id` IN (".substr($row['shared'],1).")")) { return $sh->retErr(402,-901,''); }
						while ($rowS=$rsltS->fetch_assoc()) $shared.=','.$rowS['name'];
						if ($shared!='') $shared='<br>shared with: '. substr($shared,1);
					}
					$ret.= '<input type="button" value="'.$row['name'].'" onclick="apiAction(\'viewList\',true,\''.$row['id'].'\')" style="background:yellow;" /> '.$shared.'<br/>';
				}
				$ret.= '<h3>Shared with you</h3>';
				if (!$rslt=$sh->sqlQuery("SELECT * FROM `lists` WHERE `shared` LIKE '%,".$_SESSION['userId']."%'")) { return $sh->retErr(402,-901,''); }
				while ($row=$rslt->fetch_assoc()) {
					$ret.= '<input type="button" value="'.$row['name'].'"  onclick="apiAction(\'viewList\',true,\''.$row['id'].'\')" style="background:yellow;" /><br/>';
				}
				echo $ret; return 0;
			break;
			case 'addList':
				if (!isset($_POST['nam'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("INSERT INTO `lists` SET `name`='".$_POST['nam']."', `owner`='".$_SESSION['userId']."'")) { return $sh->retErr(402,-901,''); 
				} else { 
					$lid=$sh->sqlInsertId();
					if (!$sh->sqlQuery("CREATE TABLE IF NOT EXISTS `list_$lid` (`id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(300) NOT NULL, `usr` bigint(20) NOT NULL, `dttm` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `status` int(1) NOT NULL DEFAULT '1', PRIMARY KEY (`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf32 AUTO_INCREMENT=1;")) { 
						if (!$sh->sqlQuery("DELETE FROM `lists` WHERE `id`='$lid'")) return $sh->retErr(402,-901,"Error deleting $lid from list on error creating list table list_$lid", true); 
						return $sh->retErr(402,-901,"Error creating table list_$lid",true);
					} else return $lid;
				}
			break;
			case 'delList':
				if (!isset($_POST['id'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("DELETE FROM `lists` WHERE `id`='".$_POST['id']."' AND `owner`='".$_SESSION['userId']."'")) return $sh->retErr(402,-901,"Error deleting table list_$lid",true);
				else {
					if (!$sh->sqlQuery("DROP TABLE `list_".$_POST['id']."`")) return $sh->retErr(402,-901,"Error deleting ".$_POST['id']." from list on deleting list table list_".$_POST['id'], true); 
					else return $_POST['id'];
				}
			break;
			case 'viewList':
				if (!isset($_POST['id'])) return $sh->retErr(402,-988,'');
				if (!$rslt=$sh->sqlQuery("SELECT `list_".$_POST['id']."`.*, `users`.`name` AS `users_name` FROM `list_".$_POST['id']."` LEFT JOIN `users` on `users`.`id`=`list_".$_POST['id']."`.`usr` ORDER BY `status` DESC ,`dttm` DESC")) { return $sh->retErr(402,-901,''); 
				} else {
					$ret='<input type="button" value="View lists" style="background-color:lightgrey; float:left;" onclick="apiAction(\'showLists\',true);"/> '
.'<input type="button" value="Share list" style="background-color:yellow;" onclick="apiAction(\'getUsrList\',true,\'\','.$_POST['id'].');" /> '
.'<span id="pnlDelList"><input type="button" value="Delete list" style="background-color:#ffa5a5; float:right;" onclick="apiAction(\'delList\',false,'.$_POST['id'].')" /></span><hr/>'
.'<div id="pnlAddItm"><input type="button" value="Add item" style="background-color:lightgreen;" onclick="apiAction(\'addItem\',false,0,'.$_POST['id'].');"/></div>'
.'<div id="pnlNewItm" style="display:none;"><input id="itemName" type="text" /></td><td><input type="button" value="+" style="background-color:lightgreen;" onclick="apiAction(\'addItem\',true,0,'.$_POST['id'].')" /></div>'
.'<table id="tblItems">'
.'<thead><tr><td></td></tr></thead><tbody>';
					while($row=$rslt->fetch_assoc()) { 
						$stAct=''; $stClr='lightgrey'; 
						if ($row['status']==1) { $stClr='yellow'; $stAct='onclick="apiAction(\'buyItem\',true,'.$row['id'].','.$_POST['id'].')"';
						} else if ($row['status']==2) { $stClr='#ff7b7b'; $stAct='onclick="apiAction(\'buyItem\',true,'.$row['id'].','.$_POST['id'].')"';
						} else if ($row['status']==0) { if ((time() - strtotime($row['dttm'])) < 1800) { $stClr='#90ee90'; $stAct='onclick="apiAction(\'retItem\',true,'.$row['id'].','.$_POST['id'].')"'; } }
						$ret.= '<tr id="itm_'.$row['id'].'" style="background-color:'.$stClr.';" ><td '.$stAct.'>'.$row['name'].'<div style="font-size:12px; color:grey; float:right; text-align:center; display:inline-block; margin-left:20px;">'.$row['users_name'].'<br>'.date_format(date_create($row['dttm']),'d.m H:i').'</div></td><td><input type="button" value="-" style="background-color:#ffa5a5;" onclick="apiAction(\'delItem\',true,'.$row['id'].','.$_POST['id'].')" /></td></tr>'; 
					}
					$ret.='</tbody></table>';
					echo $ret;
					return 0;
				}
			break;
			case 'getUsrList':
				if (!$rslt=$sh->sqlQuery("SELECT `id`,`mail`,`name` FROM `users` WHERE `id`!='".$_SESSION['userId']."'")) return $sh->retErr(402,-901,''); 
				else { 
					$ret=array();
					while($row=$rslt->fetch_assoc()) $ret[]=$row;
					echo json_encode($ret); return 0;
				}
			break;
			case 'shareList':
				if (!isset($_POST['id'])||!isset($_POST['list'])) return $sh->retErr(402,-988,'');
				$shared='';
				if (!$rslt=$sh->sqlQuery("SELECT `shared` FROM `lists` WHERE `id`='".$_POST['list']."'")) return $sh->retErr(402,-901,''); 
				else { $row=$rslt->fetch_assoc(); $shared=$row['shared']; if ($shared!='') { if (strpos($shared,','.$_POST['id'])!==false) return 0; } }
				if (!$sh->sqlQuery("UPDATE `lists` SET `shared`='$shared,".$_POST['id']."' WHERE `id`='".$_POST['list']."'")) return $sh->retErr(402,-901,''); 
				else return 0;
			break;
			case 'addItem':
				if (!isset($_POST['nam'])||!isset($_POST['list'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("INSERT INTO `list_".$_POST['list']."` SET `name`='".$_POST['nam']."', `usr`='".$_SESSION['userId']."'")) { return $sh->retErr(402,-901,''); 
				} else return $sh->sqlInsertId();
			break;
			case 'buyItem':
				if (!isset($_POST['id'])||!isset($_POST['list'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("UPDATE `list_".$_POST['list']."` SET `status`='0', `dttm`='".date('Y-m-d H:i:s',time())."', `usr`='".$_SESSION['userId']."' WHERE `id`='".$_POST['id']."'")) { return $sh->retErr(402,-901,''); 
				} else return 0;
			break;
			case 'retItem':
				if (!isset($_POST['id'])||!isset($_POST['list'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("UPDATE `list_".$_POST['list']."` SET `status`='1', `dttm`='".date('Y-m-d H:i:s',time())."', `usr`='".$_SESSION['userId']."' WHERE `id`='".$_POST['id']."'")) { return $sh->retErr(402,-901,''); 
				} else return 0;
			break;
			case 'delItem':
				if (!isset($_POST['id'])||!isset($_POST['list'])) return $sh->retErr(402,-988,'');
				if (!$sh->sqlQuery("DELETE FROM `list_".$_POST['list']."` WHERE `id`='".$_POST['id']."'")) { return $sh->retErr(402,-901,''); 
				} else return 0;
			break;
		}
	} else { //draw user page
?>
<html>
<head>
<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1"><title>SimShopList</title><link rel="icon" type="image/png" href="img/donut.png">
<script type="text/javascript" src="js/jq.min.js"></script>
<style>
body { font-family: Georgia, serif; margin: auto; max-width: 500px; }
input { font-family: Georgia, serif; font-size:18px; padding:7px; border-radius:10px; transition: all 0.2s ease-out; }
input[type=button] { cursor:pointer; }
input[type=button]:hover { filter: brightness(0.9); }
#main { text-align:center; padding:20px; }
#header { border-bottom:1px solid lightgrey; text-align:center; padding:20px; }
#tblItems { margin: auto; }
#tblItems > tbody > tr > td { padding:10px; cursor:pointer; font-size:22px; }
#loadingDiv { opacity:0; z-index:-9999; position:fixed; top:0; bottom:0; left:0; right:0; background-color:#fff; transition: all 0.2s ease-out; }
#pnlShareList { opacity:1; z-index:9999; position:fixed; top:0; bottom:0; left:0; right:0; background-color:rgba(255,255,255,0.85); transition: all 0.2s ease-out; text-align:center; margin: auto; max-width: 500px; }
#shareList > div { cursor:pointer; transition: all 0.2s ease-out; padding: 20px;  }
#shareList > div:hover { background-color:lightgreen; }
</style>
<script>
function apiAction(action,go,id,list) {
	list = typeof list !== 'undefined' ? list : '';
	id = typeof id !== 'undefined' ? id : '';
	go = typeof go !== 'undefined' ? go : false;
	action = typeof action !== 'undefined' ? action : 'showLists';
	if (!go) {
		     if (action=='addItem') { $('#pnlAddItm').css('display','none'); $('#pnlNewItm').css('display','block'); }
		else if (action=='buyItem') {  }
		else if (action=='retItem') {  }
		else if (action=='delItem') {  }
		else if (action=='getUsrList') {  }
		else if (action=='addList') { $('#inpNamList').toggle('fast'); $('#pnlAddList').html('<input type="button" value="Add list" style="background-color:lightgreen;" onclick="apiAction(\'addList\',true)" />'); }
		else if (action=='signOut') { $('#pnlSignOut').html('<input type="button" value="Realy want to sign out?" onclick="apiAction(\'signOut\',true);" />'); }
		else if (action=='delList') { $('#pnlDelList').html('<input type="button" value="Realy delete list?" style="background-color:#ffa5a5;" onclick="apiAction(\'delList\',true,'+id+')" />'); }
	} else {
		var data = new FormData();
		data.append('action',action);
		     if (action=='addItem') { data.append('nam',$('#itemName').val()); data.append('list',list); }
		else if (action=='buyItem') { data.append('id',id); data.append('list',list); }
		else if (action=='retItem') { data.append('id',id); data.append('list',list); }
		else if (action=='delItem') { data.append('id',id); data.append('list',list); }
		else if (action=='shareList') { data.append('id',id); data.append('list',list); }
		else if (action=='viewList') data.append('id',id);
		else if (action=='delList') data.append('id',id);
		else if (action=='addList') {
			var nam = $('#inpNamList').val()
			if (nam.length<3 || nam.length>30) { alert('Enter list name from 3 to 30 chars'); return; }
			data.append('nam',nam);
		}
		
		var xhr = new XMLHttpRequest();                     $("#loadingDiv").css({'opacity':'1','z-index':'9999'});
		xhr.onreadystatechange = function() {
			if (this.readyState == 4) {
				if (this.status == 200) { 
					     if (action=='logIn')     $('#main').html(this.response);
					else if (action=='signOut')   document.location.reload() ;	 
					else if (action=='showLists') $('#main').html(this.response);
					else if (action=='addList') apiAction('showLists',true);
					else if (action=='delList') apiAction('showLists',true);
					else if (action=='addItem') apiAction('viewList',true,list);
					else if (action=='buyItem') apiAction('viewList',true,list);
					else if (action=='retItem') apiAction('viewList',true,list);
					else if (action=='delItem') apiAction('viewList',true,list);
					else if (action=='shareList') apiAction('viewList',true,list);
					else if (action=='viewList')  $('#main').html(this.response);
					else if (action=='getUsrList') {
						try {
							var ret = JSON.parse(this.response);
							var usrs='';
							for (var ii=0; ii<ret.length; ii++) {
								usrs+='<div style="display:none;" onclick="this.parentNode.parentNode.remove(); apiAction(\'shareList\',true,'+ret[ii].id+','+list+');"><span style="display:none;">'+ret[ii].id+'::'+ret[ii].mail+'</span>'+ret[ii].name+'</div>';
							}
							$('body').append('<div id="pnlShareList"><input type="button" value="Close" onclick="this.parentNode.remove();" /><input id="inpShareName" type="text" oninput="filterShareList(this);"/><div id="shareList">'+usrs+'</div></div>');
						} catch (e) { alert(e.message + '\n' + this.response); }
					} else alert('wrong action!');
				} else if (this.status == 401) { alert(this.statusText); location.reload();
				} else alert('Error!\n'+this.statusText);
				
				$("#loadingDiv").css({'opacity':'0','z-index':'-9999'});
			}
		}
		xhr.open("POST", 'index.php', true);
		xhr.send(data);
	}
}
function filterShareList(inp) {
	var pat = inp.value.toLowerCase();
	if (pat.length<3 || pat.length>30) { return; }
	else {
		$('#shareList > div').each(function () {
			if ($(this).text().toLowerCase().indexOf(pat)>0) $(this).css('display','block'); else $(this).css('display','none');
		});
	}
}
</script>
</head>
<body>
<div id="loadingDiv">&nbsp;</div>
<div id="header"><?php echo $_SESSION['userName']; ?> <div id="pnlSignOut" style="display:inline-block"><input type="button" value="Sign out" onclick="apiAction('signOut');" style="margin-left:100px;" /></div> </div>
<div id="main"><script>apiAction('showLists',true);</script></div>
</body>
</html>
<?php
	}
	
} else {
	if (isset($_POST['action'])) {
		if ($_POST['action']=='login') {
			$rslt=$sh->sqlQuery("SELECT * FROM `users` WHERE `mail`='".$_POST['mail']."'");
			if (!$rslt) { return $sh->retErr(402,-901,'');
			} else if ($rslt->num_rows<1) { return $sh->retErr(402,-101,'');
			} else { $row=$rslt->fetch_assoc(); if ($row['pass']!=md5($_POST['pass'])) { return $sh->retErr(402,-102,''); } }
			$_SESSION['userId']=$row['id']; $_SESSION['userName']=$row['name']; return 0;
		} else if ($_POST['action']=='create') {
			$rslt=$sh->sqlQuery("SELECT * FROM `users` WHERE `mail`='".$_POST['mail']."'");
			if (!$rslt) { return $sh->retErr(402,-901,''); }
			else if ($rslt->num_rows>0) { return $sh->retErr(402,-102,''); 
			} else  {
				if (!$sh->sqlQuery("INSERT INTO `users` SET `mail`='".$_POST['mail']."', `pass`='".md5($_POST['pass'])."', `name`='".$_POST['unam']."'",true)) { return $sh->retErr(402,-901,'');
				} else { $_SESSION['userId']=$sh->sqlInsertId(); $_SESSION['userName']=$_POST['unam']; return 0; }
			}
		} else { return $sh->retErr(402,-999,''); }
	}
	//draw login page
?>
<html>
<head>
<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1"><title>SimShopList</title><link rel="icon" type="image/png" href="img/donut.png">
<script type="text/javascript" src="js/jq.min.js"></script>
<style>
#loginScreen { margin:auto; padding:30px; border-radius:30px; border:1px solid grey; text-align:center; display:inline-block; }
#loginScreen input { margin-bottom:20px; padding:10px; font-size:18px; }
</style>
<script>
function validateEmail(email) { const re = /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/; return re.test(String(email).toLowerCase()); }
function validatePass(pass) { const re = /^[\w\[\]!@]*$/; return re.test(String(pass).toLowerCase()); }
function signIn(act,chk){
	if (chk==false) {
		if (act=='login') { if ($('#loginForm').css('display')=='none') { $('#createForm').toggle('fast'); $('#loginForm').toggle('fast'); } else signIn(act,true);
		} else if (act=='create') { if ($('#createForm').css('display')=='none') { $('#createForm').toggle('fast'); $('#loginForm').toggle('fast'); }else signIn(act,true); }
	} else {
		var mail=false;
		var pass=false;
		var unam=false;
		if (act=='login') {
			mail = $('#loginForm [name=mail]').val();
			if (mail.length<1 && mail.length>80) { alert('Enter your correct email (max length 80)'); return; }
			if (!validateEmail(mail)) { alert('Email contains bad characters'); return; }
			pass = $('#loginForm [name=pass]').val();
			if (pass.length<1 && pass.length>20) { alert('Enter your correct password (max length 20)'); return; }
			if (!validatePass(pass)) { alert('Password should be 6 to 20 characters which contain only characters, numeric digits, underscore and first character must be a letter'); return; }
		} else if (act=='create') {
			unam = $('#createForm [name=unam]').val();
			if (unam.length<3 && unam.length>20) { alert('Enter your name (max length 20)'); return; }
			if (!validatePass(unam)) { alert('Name contains bad characters'); return; }
			mail = $('#createForm [name=mail]').val();
			if (mail.length<1 && mail.length>80) { alert('Enter your correct email (max length 80)'); return; }
			if (!validateEmail(mail)) { alert('Email contains bad characters'); return; }
			pass = $('#createForm [name=pass]').val();
			if (pass.length<6 && pass.length>20) { alert('Enter your correct password (max length 20)'); return; }
			if (!validatePass(pass)) { alert('Password should be 6 to 20 characters which contain only characters, numeric digits, underscore and first character must be a letter'); return; }
			if (pass!=$('#createForm [name=pas1]').val()) { alert('Passwords doesn\'t match'); return; }
			if ($('#createForm [name=capt]').val()!=6) { alert('Answer is not correct!'); return; }
		} else { return; }
		if (mail && pass) {
			var data = new FormData();
			data.append('action',act);
			data.append('mail',mail);
			data.append('pass',pass);
			if (unam) data.append('unam',unam);
			var xhr = new XMLHttpRequest();                     //$("#loadingDiv").css({'opacity':'1','z-index':'9999'});
			xhr.onreadystatechange = function() {
				if (this.readyState == 4) {
					if (this.status == 200) { location.reload(); //$("body").html(this.response);
					} else if (this.status == 401) { alert(this.statusText); location.reload();
					} else alert('Error!\n'+this.statusText);
					//$("#loadingDiv").css({'opacity':'0','z-index':'-9999'});
				}
			}
			xhr.open("POST", 'index.php', true);
			xhr.send(data);
		}
	}
}

</script>
</head>
<body>
<center><div id="loginScreen">
<div id="loginForm" style="display:block;">
<input name="mail" type="text" placeholder="Enter your email" /><br>
<input name="pass" type="password" placeholder="Enter your password" /><br>
</div>

<div id="createForm" style="display:none;">
<input name="unam" type="text" placeholder="Enter your name" /><br>
<input name="mail" type="text" placeholder="Enter your email" /><br>
<input name="pass" type="password" placeholder="Enter your password" /><br>
<input name="pas1" type="password" placeholder="Confirm your password" /><br>
<p id="quest" style="font-size:18px;">2 + 4 = ?</p>
<input name="capt" type="text" placeholder="Answer question" /><br>
</div>
<input type="button" value="Log in" onclick="signIn('login',false)" style="background-color:lightgreen;"/>
<input type="button" value="Create" onclick="signIn('create',false); $('#createForm').toggle(true);"  style="background-color:yellow;" />
</div></center>
</body>
</html>
<?php
	return;
}
?>
