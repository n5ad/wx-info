<?php
// New features, IRLP capability, Paul Aidukas KN2R (Copyright) July 15, 2013-2017
// For ham radio use only, NOT for comercial use!

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
date_default_timezone_set('America/New_York');
include('amifunctions.inc');
include('nodeinfo.inc');
include("global.inc");
include("common.inc");

if (empty($_GET['nodes'])) {
    $data['status'] = 'Unknown request!';
    echo 'data: ' .  json_encode($data) . "\n\n";
    ob_flush();
    flush();
    exit;
}

$passedNodes = explode(',', @trim(strip_tags($_GET['nodes'])));

$db = $ASTDB_TXT;
$astdb = array();
if (file_exists($db)) {
    $fh = fopen($db, "r");
    if (flock($fh, LOCK_SH)){
        while(($line = fgets($fh)) !== FALSE) {
            $arr = preg_split("/\|/", trim($line));
            $astdb[$arr[0]] = $arr;
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

$elnk_cache = array();
$irlp_cache = array();

if (!file_exists('allmon.ini')) {
    die("Couldn't load ini file.\n");
}
$config = parse_ini_file('allmon.ini', true);

$nodes = array();
foreach($passedNodes as $i => $node) {
    if (isset($config[$node])) {
        $nodes[] = $node;
    } else {
        $data = array('node' => $node, 'status' => "Node $node is not in allmon ini file");
        echo "event: nodes\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }
}

$servers = array(); $fp = array();
foreach($nodes as $node) {
    $host = $config[$node]['host'];
    
    if (!array_key_exists($host, $servers)) {
        $data = array('host' => $host, 'node' => $node, 'status'=>' &nbsp; Connecting to Asterisk Manager...');
        echo "event: connection\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();

        $fp[$host] = AMIconnect($config[$node]['host']);
        if ($fp[$host] === FALSE) {
            $data = array('host' => $host, 'node' => $node, 'status' => '<b style="color:firebrick"> &nbsp; Could not connect to Asterisk Manager.');
            echo "event: connection\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            ob_flush();
            flush();
        } else {
            if (FALSE !== AMIlogin($fp[$host], $config[$node]['user'], $config[$node]['passwd'])) {
                $servers[$host] = 'y';
            } else {
                $data = array('host' => $host, 'node' => $node, 'status' => '<b style="color:firebrick"> &nbsp; Could not login to Asterisk Manager.');
                echo "event: connection\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                ob_flush();
                flush();
            }
        }
    }
}

$current=array();
$saved=array();
$nodeTime=array();
while(TRUE) {
    foreach ($nodes as $node) {
        
        if (!isset($servers[$config[$node]['host']])) {
            continue;
        }
        
        $connectedNodes = getNode($fp[$config[$node]['host']], $node);
        $sortedConnectedNodes = sortNodes($connectedNodes);

        $nodeTime[$node]['node']=$node;
        $nodeTime[$node]['info'] = getAstInfo($fp[$config[$node]['host']], $node);
        
        $current[$node]['node']=$node;
        $current[$node]['info'] = getAstInfo($fp[$config[$node]['host']], $node);
        
        $current[$node]['remote_nodes'] = array(); $i=0;
        foreach ($sortedConnectedNodes as $remoteNode => $arr) {
            $nodeTime[$node]['remote_nodes'][$i]['elapsed'] = $arr['elapsed'];
            $nodeTime[$node]['remote_nodes'][$i]['last_keyed'] = $arr['last_keyed'];
            
            $current[$node]['remote_nodes'][$i]['node']=$arr['node'];
            $current[$node]['remote_nodes'][$i]['info']=$arr['info'];
            $current[$node]['remote_nodes'][$i]['link']=$arr['link'];
            $current[$node]['remote_nodes'][$i]['ip']=$arr['ip'];
            $current[$node]['remote_nodes'][$i]['direction']=$arr['direction'];
            $current[$node]['remote_nodes'][$i]['keyed']=$arr['keyed'];
            $current[$node]['remote_nodes'][$i]['mode']=$arr['mode'];
            $current[$node]['remote_nodes'][$i]['elapsed'] = '&nbsp;';

            if ( $arr['last_keyed'] == "Never" ) {
                 $current[$node]['remote_nodes'][$i]['last_keyed'] = 'Never';
            } else {   
                 $current[$node]['remote_nodes'][$i]['last_keyed'] = '&nbsp';
            }

            $current[$node]['remote_nodes'][$i]['cos_keyed']=$arr['cos_keyed'];
            $current[$node]['remote_nodes'][$i]['tx_keyed']=$arr['tx_keyed'];

            // Copy status fields from parseNode() so link.php can display them.
            // These come from the AMI XStat response (or are empty if not present).
            $current[$node]['remote_nodes'][$i]['ALERT']    = $arr['ALERT'] ?? '';
            $current[$node]['remote_nodes'][$i]['WX']       = $arr['WX'] ?? '';
            $current[$node]['remote_nodes'][$i]['LASTUPDATE'] = $arr['LASTUPDATE'] ?? '';

        $i++;
        }
    }

    if ($current !== $saved) {
        $saved = $current;
        echo "event: nodes\n";
        echo 'data: ' . json_encode($current) . "\n\n";
    }
    
    echo "event: nodetimes\n";
    echo 'data: ' . json_encode($nodeTime) . "\n\n";
    
    ob_flush();
    flush();

    if (isset($SMLOOPDELAY) && ($SMLOOPDELAY > 299999 && $SMLOOPDELAY < 30000001)) {
        usleep($SMLOOPDELAY);
    } else {
        usleep(500000);
    }
}

fwrite($fp, "ACTION: Logoff\r\n\r\n");
exit;

function getNode($fp, $node) {
    $actionRand = mt_rand();
    
    $actionID = 'xstat' . $actionRand;
    if ((fwrite($fp,"ACTION: RptStatus\r\nCOMMAND: XStat\r\nNODE: $node\r\nActionID: $actionID\r\n\r\n")) !== FALSE ) {
        $rptStatus = AMIget_response($fp, $actionID);
    } else {
        $data['status'] = 'XStat() failed!';
        echo 'data: ' .  json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }
    
    $actionID = 'sawstat' . $actionRand;
    if ((fwrite($fp,"ACTION: RptStatus\r\nCOMMAND: SawStat\r\nNODE: $node\r\nActionID: $actionID\r\n\r\n")) !== FALSE ) {
        $sawStatus = AMIget_response($fp, $actionID);
    } else {
        $data['status'] = 'sawStat failed!';
        echo 'data: ' .  json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }
    
    $current = parseNode($fp, $rptStatus, $sawStatus);
    return($current);
}

function sortNodes($nodes) {
    $arr=array();
    $never_heard=array();
    $sortedNodes=array();
    
    foreach($nodes as $nodeNum => $row) {
        if ($row['last_keyed'] == '-1') {
            $never_heard[$nodeNum]='Never heard';
        } else {
            $arr[$nodeNum]=$row['last_keyed'];
        }
    }
    
    if (count($arr) > 0) {
        asort($arr, SORT_NUMERIC);
    }
    
    if (count($never_heard) > 0) {
        ksort($never_heard, SORT_NUMERIC);
        foreach($never_heard as $nodeNum => $row) {
            $arr[$nodeNum] = $row;
        }
    }
    
    foreach($arr as $nodeNum => $row) {
        if ($nodes[$nodeNum]['last_keyed'] > -1) {
            $t = $nodes[$nodeNum]['last_keyed'];
            $h = floor($t / 3600);
            $m = floor(($t / 60) % 60);
            $s = $t % 60;
            $nodes[$nodeNum]['last_keyed'] = sprintf("%03d:%02d:%02d", $h, $m, $s);
        } else {
            $nodes[$nodeNum]['last_keyed'] = "Never";
        }
        $sortedNodes[$nodeNum]=$nodes[$nodeNum];
    }
    
    return ($sortedNodes);
}

function parseNode($fp, $rptStatus, $sawStatus) {

    $curNodes = array();
    $conns = array();
    $rxKeyed = $txKeyed = "0";

    // Status fields extracted from the XStat response.
    // These are set by the ast_node_status_update script via "rpt set variable".
    $ALERT = $WX = $lastupdate = '';

    $lines = explode("\n", $rptStatus);
    foreach ($lines as $line) {
        if (preg_match('/Conn: (.*)/', $line, $matches)) {
            $arr = preg_split("/\s+/", trim($matches[1]));
            if(is_numeric($arr[0]) && $arr[0] > 3000000) {
                $conns[] = array($arr[0], "", $arr[1], $arr[2], $arr[3], $arr[4]);
            } else {
                $conns[] = $arr;
            }
        }

        if (preg_match('/Var: RPT_RXKEYED=./', $line, $matches)) {
           $rxKeyed=substr($matches[0], strpos($matches[0], "=") + 1);
        }
        if (preg_match('/Var: RPT_TXKEYED=./', $line, $matches)) {
           $txKeyed=substr($matches[0], strpos($matches[0], "=") + 1);
        }

        // Extract custom status variables from the AMI XStat response.
        // On ASL3 3.10.x these may be absent even if set with "rpt set variable".
        if (preg_match('/Var: ALERT=.*/', $line, $matches)) {
            $ALERT = substr($matches[0], strpos($matches[0], "=") + 1);
        }
        if (preg_match('/Var: WX=.*/', $line, $matches)) {
            $WX = substr($matches[0], strpos($matches[0], "=") + 1);
        }
        if (preg_match('/Var: LASTUPDATE=.*/', $line, $matches)) {
            $lastupdate = substr($matches[0], strpos($matches[0], "=") + 1);
        }
    }

    $keyups = array();
    $lines = explode("\n", $sawStatus);
    foreach ($lines as $line) {
        if (preg_match('/Conn: (.*)/', $line, $matches)) {
            $arr = preg_split("/\s+/", trim($matches[1]));
            $keyups[$arr[0]] = array('node' => $arr[0], 'isKeyed' => $arr[1], 'keyed' => $arr[2], 'unkeyed' => $arr[3]);
        }
    }

    if (preg_match("/LinkedNodes: (.*)/", $rptStatus, $matches)) {
        $longRangeLinks = preg_split("/, /", trim($matches[1]));
        foreach ($longRangeLinks as $line) {
            $n = substr($line,1);
            $modes[$n]['mode'] = substr($line,0,1);
        }
    }

    if (count($conns) > 0 ) {
        foreach($conns as $node) {
            $n = $node[0];
            $curNodes[$n]['node'] = $node[0];
            $curNodes[$n]['info'] = getAstInfo($fp, $node[0]);
            $curNodes[$n]['ip'] = $node[1];
            $curNodes[$n]['direction'] = $node[3];
            $curNodes[$n]['elapsed'] = $node[4];
            $curNodes[$n]['link'] = @$node[5];

            if (isset($node[5])) {
                $curNodes[$n]['link'] = @$node[5];
            } else {
                $curNodes[$n]['direction'] = $node[2];
                $curNodes[$n]['elapsed'] = $node[3];
                if (isset($modes[$n]['mode'])) {
                    if ($modes[$n]['mode'] == 'C') {
                        $curNodes[$n]['link'] = "CONNECTING";
                    } else {
                        $curNodes[$n]['link'] = "ESTABLISHED";
                    }
                }
            }

            $curNodes[$n]['keyed'] = 'n/a';
            $curNodes[$n]['last_keyed'] = 'n/a';

            if (isset($modes[$n])) {
                $curNodes[$n]['mode'] = $modes[$n]['mode'];
            } else {
                $curNodes[$n]['mode'] = 'Local Monitor';
            }
        }

        foreach($keyups as $node => $arr) {
            if ($arr['isKeyed'] == 1) {
                $curNodes[$node]['keyed'] = 'yes';
            } else {
                $curNodes[$node]['keyed'] = 'no';
            }
            $curNodes[$node]['last_keyed'] = $arr['keyed'];
        }

        $curNodes[1]['node'] = 1;
    } else {
        $curNodes[1]['info'] = "NO CONNECTION";
    }    

    $curNodes[1]['cos_keyed'] = ($rxKeyed === "1") ? 1 : 0;
    $curNodes[1]['tx_keyed'] = ($txKeyed === "1") ? 1 : 0;

    // Attach the status fields to the local-node entry (node == 1).
    // link.php looks for these on the node==1 (or node==null) row.
    $curNodes[1]['ALERT']    = $ALERT;
    $curNodes[1]['WX']       = $WX;
    $curNodes[1]['LASTUPDATE'] = $lastupdate;

    return $curNodes;
}
?>