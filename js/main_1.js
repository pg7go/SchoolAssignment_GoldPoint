// 存储用户名到全局变量,握手成功后发送给服务器
var uname = '玩家' + uuid(8, 16);


var time = 0;
var pasdTime = 0;

$.ajax({
	'type':'GET',
	'url':'http://goldpoint.doingtest.top/server/server.php',
	'timeout':1000
});

var ws = new WebSocket("ws://goldpoint.doingtest.top:25101");
ws.onopen = function() {

};

ws.onerror = function() {
	alert("系统消息 : 出错了，不能连接到服务器，请退出重试.");
};

/**
 * 分析服务器返回信息
 *
 * msg.type : user 普通信息;system 系统信息;handshake 握手信息;login 登陆信息; logout 退出信息;
 * msg.from : 消息来源
 * msg.content: 消息内容
 */
ws.onmessage = function(e) {
	var msg = JSON.parse(e.data);
	console.log("Receive：" + e.data);

	switch(msg.type) {
		case 'handshake':
			refresh();
			break;
		case 'create':
			if(msg.success) {
				join(msg.room);
			} else {
				alert(msg.reason);
			}
			break;
		case 'join':
			if(msg.success) {
				listMsg("成功加入房间 id:" + msg.content.id);
				$('#rooms').attr('hidden', 'true');
				$('#game').removeAttr('hidden');

			} else {
				alert(msg.reason);
			}
			break;
		case 'info':
			listMsg(msg.content);
			break;
		case 'room':
			var room = msg.room;
			var players = msg.players;
			$('#round').text('局数：' + room.round + '/' + room.max_round);
			$('#point').text(room.point);
			time = room.time_left;
			pasdTime = 0;

			$('#players').html('');
			players.forEach(function(x) {
				if(x.admin == true) {
					$('#players').append('<p>[房主]' + x.uname + ' 分数：' + x.score + '</p>');
				} else {
					$('#players').append('<p>' + x.uname + ' 分数：' + x.score + '</p>');
				}

				if(x.uname == uname) {
					$('#score').text('你的分数：' + x.score);
				}

			});
			
			
			
			var state = '';
				if(room.ended)
					state = '游戏已经结束';
				else if(room.started)
					state = '游戏已经开始';
				else state = '游戏未开始';
			$('#state').text(state);

			break;
		case 'lobby':
			var rooms = msg.content;
			$('#roomCards').html('');
			rooms.forEach(function(x) {

				var state = '';
				if(x.ended)
					state = '游戏已经结束';
				else if(x.started)
					state = '游戏已经开始';
				else state = '游戏未开始';
/*添加了样式，已修改 */
				$('#roomCards').append('<div class="col-md-5 style="height:50%;margin-left:40px;border-radius:10px;margin-top:10px;background-color:#4dcca3;"><div class="card" style="background-color:#4dcca3;border:1px solid #4dcca3;"><div class="card-body"style="backgroun-color:#4dcca3;"><div class="card"><div class="card-body"><h5 class="card-title">房间 id：' + x.id +'    [' +state+']</h5>' +
					'<p class="card-text">人数：' + x.person + '/' + 10 + '</p>' +
					'<p class="card-text">局数：' + x.round + '/' + x.max_round + '</p>' +
					'<a href="javascript:join(' + x.id + ')" class="btn btn-primary" style="background:#4db4b3;border:1px solid #4db4b3;color:black;">加入</a></div></div></div>');
			});
			break;

	}

	//var data = sender + msg.content;
	//listMsg(data);
};

//bind
$(document).ready(function() {
	$('#input_name').val(uname);
	
	
	$("#confirm").on('click', function() {

		var num = $('#input_number').val();
		if(!isNaN(num) && num != '') {
			sendMsg({
				'type': 'num',
				'content': num
			});
		} else {
			alert("请输入数字！");
		}
		$('#input_number').val('');
	});

	$('#input_number').keyup(function(event) {
		if(event.keyCode == 13) {
			$("#confirm").trigger("click");
		}
	});

	$("#start").on('click', function() {
		sendMsg({
			'type': 'start',
			'content': ""
		});

	});

	$("#refresh").on('click', function() {
		refresh();

	});

	$("#confirm_name").on('click', function() {
		var name = $('#input_name').val();
		if(name == '') {
			alert('名称不能为空！');
		} else {
			uname = name;
		}

	});

	$("#create").on('click', function() {
		var create = {
			'type': 'create',
			'content': ''
		};
		sendMsg(create);

	});

	$("#quit").on('click', function() {
		var leave = {
			'type': 'leave',
			'content': ''
		};
		sendMsg(leave);
		$('#msg_list').html('');
		$('#game').attr('hidden', 'true');
		$('#rooms').removeAttr('hidden');

	});

	$("#join").on('click', function() {
		var num = $('#input_room').val();
		if(!isNaN(num) && num != '') {
			join(Number(num));
		} else {
			alert("请输入数字！");
		}

	});

	setInterval("timeRefresh()", 100);
	setInterval("refresh()", 3000);

});

function timeRefresh() {
	pasdTime += 0.1;
	if(time - pasdTime <= 0) {
		$('#time').text('0');
	} else {
		$('#time').text(Math.floor(time - pasdTime));
	}

}

function join(id) {
	sendMsg({
		'type': 'join',
		'content': {
			'room': id,
			'uname': uname
		}
	});
}

function refresh(id) {
	sendMsg({
		'type': 'lobby',
		'content': ''
	});
}

function listMsg(data) {
	var msg_list = document.getElementById("msg_list");
	var msg = document.createElement("p");

	msg.innerHTML = data;
	msg_list.appendChild(msg);
	msg_list.scrollTop = msg_list.scrollHeight;
}

/**
 * 生产一个全局唯一ID作为用户名的默认值;
 *
 * @param len
 * @param radix
 * @returns {string}
 */
function uuid(len, radix) {
	var chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'.split('');
	var uuid = [],
		i;
	radix = radix || chars.length;

	if(len) {
		for(i = 0; i < len; i++) uuid[i] = chars[0 | Math.random() * radix];
	} else {
		var r;

		uuid[8] = uuid[13] = uuid[18] = uuid[23] = '-';
		uuid[14] = '4';

		for(i = 0; i < 36; i++) {
			if(!uuid[i]) {
				r = 0 | Math.random() * 16;
				uuid[i] = chars[(i == 19) ? (r & 0x3) | 0x8 : r];
			}
		}
	}

	return uuid.join('');
}

/**
 * 将数据转为json并发送
 * @param msg
 */
function sendMsg(msg) {
	var data = JSON.stringify(msg);
	console.log("send：" + data);
	ws.send(data);
}
/*监听代码的改动 */

$(window).resize(function() {  
var width = $(this).width();  
var height = $(this).height();  
  if(width<400){
	$("#gameleft").removeClass("col-md-2");
	$("#gamecenter").removeClass("col-md-8");
	$("#gameright").removeClass("col-md-2");
	
	  $("#gameleft").addClass("wdheig");
	  $("#gamecenter").addClass("wdheig");
	  $("#gameright").addClass("wdheig");
	 
  }else{
	$("#gameleft").removeClass("wdheig");
	$("#gamecenter").removeClass("wdheig");
	$("#gameright").removeClass("wdheig");
	$("#gameleft").addClass("col-md-2");
	$("#gamecenter").addClass("col-md-8");
	$("#gameright").addClass("col-md-2");
  }
  
});  
					
			

