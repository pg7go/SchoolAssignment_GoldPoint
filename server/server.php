<?php




//记得在最下方改IP
//!!!!!!!!!!!!!!!!



error_reporting(E_ALL);
set_time_limit(0);// 设置超时时间为无限,防止超时
date_default_timezone_set('Asia/shanghai');
class WebSocket {
    const LOG_PATH = '';
    const LISTEN_SOCKET_NUM = 9;

    private $rooms=[];
    private $sockets = [];
    private $master;
    //当前最大的房间id
    private $max_room_id=0;
    //过去的时间
    private $deltaTime=0;
    //上次的时间戳
    private $lastTime=0;
    private $oneSecond=0;

    public function __construct($host, $port) {

        if(!$this->checkPortBindable($host,$port))
            die('create error');

        try {
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 设置IP和端口重用,在重启服务器后能重新使用此端口;
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
            // 将IP和端口绑定在服务器socket上;
            socket_bind($this->master, $host, $port);
            // listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
            socket_listen($this->master, self::LISTEN_SOCKET_NUM);
            //设置异步非阻塞
            socket_set_nonblock($this->master);
        } catch (Exception $e) {

            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);
            $this->error([
                'error_init_server',
                $err_code,
                $err_msg
            ]);
            die('create error');
        }
        $this->sockets[0] = ['resource' => $this->master];
        $pid = function_exists('posix_getpid')?posix_getpid():get_current_user();
        $this->debug(["server: {$this->master} started ,pid: {$pid}"]);//
        while (true) {
            try {
                $this->doServer();
                $this->doGame();
            } catch (Exception $e) {
                echo 'error';
                $this->error([
                    'error_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
        }
    }


    /**
     * 检查端口是否可以被绑定
     * @author flynetcn
     */
    private function checkPortBindable($host, $port, &$errno=null, &$errstr=null)
    {
        $socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
        if (!$socket) {
            return false;
        }
        fclose($socket);
        unset($socket);
        return true;
    }

    private function doServer() {
        $write = $except = NULL;
        $sockets = array_column($this->sockets, 'resource');
        $read_num = socket_select($sockets, $write, $except, 1);
        // select作为监视函数,参数分别是(监视可读,可写,异常,超时时间),返回可操作数目,出错时返回false;
        if (false === $read_num) {
            $this->error([
                'error_select',
                $err_code = socket_last_error(),
                socket_strerror($err_code)
            ]);
            return;
        }
        foreach ($sockets as $socket) {
            // 如果可读的是服务器socket,则处理连接逻辑
            if ($socket == $this->master) {
                $client = socket_accept($this->master);
                // 创建,绑定,监听后accept函数将会接受socket要来的连接,一旦有一个连接成功,将会返回一个新的socket资源用以交互,如果是一个多个连接的队列,只会处理第一个,如果没有连接的话,进程将会被阻塞,直到连接上.如果用set_socket_blocking或socket_set_noblock()设置了阻塞,会返回false;返回资源后,将会持续等待连接。
                if (false === $client) {
                    $this->error([
                        'err_accept',
                        $err_code = socket_last_error(),
                        socket_strerror($err_code)
                    ]);
                    continue;
                } else {
                    self::connect($client);
                    continue;
                }
            } else {
                // 如果可读的是其他已连接socket,则读取其数据,并处理应答逻辑
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes < 9) {
                    $this->disconnect($socket);
                } else {
                    if (!$this->sockets[(int)$socket]['handshake']) {
                        self::handShake($socket, $buffer);
                        continue;
                    } else {
                        $recv_msg = self::parse($buffer);
                    }
                    array_unshift($recv_msg, 'receive_msg');
                    self::dealMsg($socket, $recv_msg);
                }


            }
        }
    }

    public function doGame(){
        //刷新delta时间
        if($this->lastTime==0)
            $this->lastTime=floatval(microtime(true));
        else{
            $time=floatval(microtime(true));
            $this->deltaTime=$time-$this->lastTime;
            $this->lastTime=$time;

            $this->oneSecond+=$this->deltaTime;
            if($this->oneSecond<1)
            {

                return;
            }
            $this->deltaTime=$this->oneSecond;
            $this->oneSecond=0;
        }


        foreach ($this->rooms as &$game)
        {
            #$this->debug($game);

            if($game['started']!==true||$game['ended']!==false)
                continue;
            $game['time_left']-=$this->deltaTime;
            if($game['time_left']<0)
            {
                //----------游戏逻辑
                $sumValue=0;
                $sum=0;
                $room_players=&$this->gerRoomPlayers($game['id']);
                foreach ($room_players as $p)
                {
                    $this->debug($p);
                    if($p['num']!=-1)
                    {
                        $sum++;
                        $sumValue+=$p['num'];
                    }else
                    {
                        $this->sendRoomInfo($game['id'],$p['uname'].' 未在时间内做出决定，将在此局不算入');
                    }
                }


                if($sum>0)
                {
                    $game['point']=$sumValue/$sum*0.618;

                    reset($room_players);
                    $closet_player=current($room_players);
                    $farst_player=current($room_players);

                    foreach ($room_players as $p)
                    {
                        if(abs($p['num']-$game['point'])<abs($closet_player['num']-$game['point']))
                            $closet_player=$p;
                        else if(abs($p['num']-$game['point'])>abs($farst_player['num']-$game['point']))
                            $farst_player=$p;
                    }

                    foreach ($room_players as &$p)
                    {
                        $p['num']=-1;
                        if($p['uname']==$closet_player['uname'])
                            $p['score']+=($sum+2);
                        else if($p['uname']==$farst_player['uname'])
                            $p['score']+=0;
                        else
                            $p['score']+=2;
                    }
                    unset($p);

                    $this->sendRoomInfo($game['id'],$closet_player['uname'].' 是本局的胜利玩家，加'.($sum+2).'分');
                    if($sum>1)
                        $this->sendRoomInfo($game['id'],$farst_player['uname'].' 是本局离黄金点最远的玩家，不加分');
                    if($sum>2)
                        $this->sendRoomInfo($game['id'],'其他玩家各加2分！');

                }else
                {
                    $game['point']=0;
                }




                //----------游戏逻辑结束

                $game['time_left']=$game['time_one'];
                //增加round
                $game['round']++;
                if($game['round']>$game['max_round'])
                {
                    $game['round']--;
                    $game['ended']=true;
                    $this->sendRoomInfo($game['id'],'所有'.$game['max_round'].'局已经结束！');
                }else{
                    $this->sendRoomInfo($game['id'],'第'.$game['round'].'局开始');
                }

                $this->debug($game);
                $this->refreshRoom($game['id']);


            }

        }
        //清除一个很容易犯的bug
        unset($game);

    }


    /**
     * 将socket添加到已连接列表,但握手状态留空;
     *
     * @param $socket
     */
    public function connect($socket) {
        socket_getpeername($socket, $ip, $port);
        $socket_info = [
            'resource' => $socket,
            'uname' => '',
            'handshake' => false,
            'ip' => $ip,
            'port' => $port,
            'score'=>-1,
            'room'=>-1,
            'admin'=> false,
            'num'=>-1
        ];
        $this->sockets[(int)$socket] = $socket_info;
        //$this->debug(array_merge(['socket_connect'], $socket_info));
        //$this->debug(['id:'.(string)(int)$socket]);
    }

    private function disconnect($socket) {
        $this-> leaveRoom($this->sockets[(int)$socket]);
        unset($this->sockets[(int)$socket]);
    }
    /**
     * 用公共握手算法握手
     *
     * @param $socket
     * @param $buffer
     *
     * @return bool
     */
    public function handShake($socket, $buffer) {
        // 获取到客户端的升级密匙
        $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));
        // 生成升级密匙,并拼接websocket升级头
        $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
        $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade_message .= "Upgrade: websocket\r\n";
        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade_message .= "Connection: Upgrade\r\n";
        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";
        socket_write($socket, $upgrade_message, strlen($upgrade_message));// 向socket里写入升级信息
        $this->sockets[(int)$socket]['handshake'] = true;
        socket_getpeername($socket, $ip, $port);
        $this->debug([
            'hand_shake',
            $socket,
            $ip,
            $port
        ]);
        // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
        $msg = [
            'type' => 'handshake',
            'content' => 'done',
        ];
        $msg = $this->build(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }
    /**
     * 解析数据
     *
     * @param $buffer
     *
     * @return bool|string
     */
    private function parse($buffer) {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return json_decode($decoded, true);
    }
    /**
     * 将普通信息组装成websocket数据帧
     *
     * @param $msg
     *
     * @return string
     */
    private function build($msg) {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;
        $data = implode('', $frame);
        return pack("H*", $data);
    }


    private  function &gerRoomPlayers($id)
    {
        $playerlist=[];
        foreach ($this->sockets as &$socket) {
            if ($socket['resource'] == $this->master) {
                continue;
            }

            if($socket['room'] ==$id)
            {
                $playerlist[]=&$socket;
            }
        }

        return $playerlist;
    }

    private function refreshRoom($id)
    {
        if(!isset($this->rooms[$id]))
            return;
        $playerlist=$this-> gerRoomPlayers($id);
        $playerInfos=[];
        foreach ($playerlist as $player) {
            unset($player['num']);
            unset($player['port']);
            unset($player['ip']);
            unset($player['resource']);
            unset($player['handshake']);
            $playerInfos[]=$player;
        }
        foreach ($playerlist as $player) {
            $this->sendPack($player,['type' => 'room',"room"=>$this->rooms[$id],"players"=>$playerInfos]);

        }
    }

    private  function sendPack($socket,$response)
    {
        $msg=$this->build(json_encode($response));
        socket_write($socket['resource'], $msg, strlen($msg));
        //$this->debug(array_merge(['send:'],$response));
    }


    private function sendRoomInfo($roomId,$info)
    {
        if(isset($this->rooms[$roomId]))
        {
            $playerlist=$this->gerRoomPlayers($roomId);
            foreach ($playerlist as $player) {
                $this->sendInfo($player,$info);
            }
        }
    }


    private function sendInfo($player,$info)
    {
        $response=[];
        $response['type'] = 'info';
        $response['content'] = $info;

        $this->sendPack($player,$response);
    }

    private function sendLobby($player)
    {
        $response=[];
        $response['type'] = 'lobby';
        $response['content'] = [];
        foreach ($this->rooms as $r) {
            unset($r['point']);
            unset($r['time_one']);
            unset($r['time_left']);
            $response['content'][]=$r;
        }


        $this->sendPack($player,$response);
    }



    private function leaveRoom(&$player)
    {
        if($player['room']!=-1)
        {
            $player['admin']=false;
            $room=&$this->rooms[$player['room']];
            $player['room']=-1;
            $room['person']--;
            $ps=$this->gerRoomPlayers($room['id']);
            if($room['person']>0)
            {
                $hasAdmin=false;
                foreach ($ps as $p)
                {
                    if($p['admin'])
                    {
                        $hasAdmin=true;
                        break;
                    }
                }

                $this->sendRoomInfo($room['id'],$player['uname']." 离开了房间");

                if(!$hasAdmin)
                {
                    $ps[0]['admin']=true;
                    $this->sendRoomInfo($room['id'],'现在 '.$player['uname']." 是房主了");

                }

                $this->refreshRoom($room['id']);
                $this->debug(array_merge(['playerLeft'],$player));
            }else{
                unset($this->rooms[$room['id']]);
            }

        }

    }



    private function dealMsg($player, $recv_msg) {
        $msg_type = $recv_msg['type'];
        $content = $recv_msg['content'];
        $response = [];

        //将int转为结构体
        $player=&$this->sockets[(int)$player];

        switch ($msg_type) {
            //创建房间
            case 'create':

                $response['type'] = 'create';
                $response['success'] = false;
                //如果玩家已经加入了其他房间则不能创建房间
                if($player['room']!=-1)
                {
                    $response['reason'] = '您已经加入了其他房间不能再创建房间了！';
                    $this->sendPack($player,$response);
                    return;
                }
                $response['success'] = true;
                //创建房间
                $room_info = [
                    'id' => ++$this->max_room_id,
                    'point'=>0.618,
                    'round'=>1,
                    'max_round'=>10,
                    'time_left'=>10,
                    'time_one'=>15,
                    'started'=>false,
                    'ended'=>false,
                    'person'=>0
                ];

                $this->rooms[$room_info['id']]=$room_info;
                $player['room']=$room_info['id'];
                $player['admin']=true;

                $response['room'] = $room_info['id'];
                $this->sendPack($player,$response);

                $this->debug(array_merge(['roomCreated'],$room_info));
                return;


            case 'join':
                $response['type'] = 'join';
                $response['success'] = false;
                $content['room']=intval($content['room']);
                if(!isset($this->rooms[$content['room']]))
                {
                    $response['reason'] = '该房间不存在！';
                    $this->sendPack($player,$response);
                    return;
                }

                $room=&$this->rooms[$content['room']];
                if($room['person']>=10)
                {
                    $response['reason'] = '人数已满！';
                    $this->sendPack($player,$response);
                    return;
                }

                if($room['started']===true)
                {
                    $response['reason'] = '房间已经开始游戏！';
                    $this->sendPack($player,$response);
                    return;
                }

                if($room['ended']===true)
                {
                    $response['reason'] = '房间游戏已经结束！';
                    $this->sendPack($player,$response);
                    return;
                }

                #防止刷管理员
                if($player['room']!=$room['id'])
                    $player['admin']=false;

                if($player['admin']==false)
                    foreach ($this->gerRoomPlayers($room['id']) as $p)
                    {

                        if($p['uname']==$content['uname'])
                        {
                            $response['reason'] = $p['uname'].' 你的名字在该房间已经存在！';
                            $this->sendPack($player,$response);
                            return;
                        }
                    }

                $response['success'] = true;
                $response['content']=$room;
                $room['person']++;
                $room['time_left']=0;
                $player['room']=$content['room'];
                $player['uname']=$content['uname'];
                $player['score']=0;

                $this->sendPack($player,$response);

                $this->sendRoomInfo($player['room'],$player['uname']." 加入游戏");
                $this->refreshRoom($player['room']);
                break;

            case 'leave':
                $this-> leaveRoom($player);

                break;

            case 'start':
                if($player['admin']!==true)
                    return;
                $room=&$this->rooms[$player['room']];
                if($room['started']&&!$room['ended'])
                    return;

                $room['round']=1;
                $room['point']=0;
                $room['time_left']=10;
                $room['started']=true;
                $this->sendRoomInfo($player['room'],"游戏开始！现在是第一局");
                $this->refreshRoom($room['id']);
                break;
            case 'num':

                $room=&$this->rooms[$player['room']];
                if($room['started']===false)
                {
                    $this->sendInfo($player,'你现在不能选择数字，游戏未开始！');
                    return;
                }

                $origin=$player['num'];
                $player['num']=floatval($content);
                if($player['num']>100||$player['num']<0)
                {
                    $this->sendInfo($player,'你只能选择0~100之间的数字！');
                    $player['num']=-1;
                }else if($origin==-1)
                {
                    $this->sendInfo($player,'你选择了：'.$player['num']);
                }else if($origin!=$player['num']){
                    $this->sendInfo($player,'你改变了自己的选择：'.$origin.' -> '.$player['num']);
                }else
                {
                    $this->sendInfo($player,'你刚刚选择的就是：'.$player['num']);
                }

                break;

            case 'lobby':
                $this->sendLobby($player);
                break;

        }
        //$msg=$this->build(json_encode($response));
        //$this->broadcast($msg);
    }
    /**
     * 广播消息
     *
     * @param $data
     */
    private function broadcast($data) {
        foreach ($this->sockets as $socket) {
            if ($socket['resource'] == $this->master) {
                continue;
            }
            socket_write($socket['resource'], $data, strlen($data));
        }
    }
    /**
     * 记录debug信息
     *
     * @param array $info
     */
    public function debug(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
    /**
     * 记录错误信息
     *
     * @param array $info
     */
    public function error(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}
global $ws;
$ws = new WebSocket("172.17.10.112", "25101");
#fputs('',"GET timer.php");