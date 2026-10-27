<?php 
include("session.inc");
include("global.inc");
include("common.inc");
include "header.inc";

$parms = @trim(strip_tags($_GET['nodes']));
$passedNodes = explode(',', @trim(strip_tags($_GET['nodes'])));

if (count($passedNodes) == 0) {
    die ("Please provide a properly formated URI. (ie link.php?nodes=1234 | link.php?nodes=1234,2345)");
}

$passwdf1 = "/var/www/html/supermon/edit/.htaccess";
$passwdf2 = "/var/www/html/supermon/edit/.htpasswd";
$SUBMITTER = "submit";
if ( (file_exists($passwdf1)) && (file_exists($passwdf2)) ) {
    $SUBMITTER = "submit2";
}

if (isset($_COOKIE['display-data'])) {
    foreach ($_COOKIE['display-data'] as $name => $value) {
        $name = htmlspecialchars($name);
        switch ($name) {
            case "number-displayed";
               $Displayed_Nodes = htmlspecialchars($value);
               break;
            case "show-number";
               $Display_Count = htmlspecialchars($value);
               break;
            case "show-all";
               $Show_All = htmlspecialchars($value);
               break;
        }
    }
}

if (! isset($Displayed_Nodes)) {
    $Displayed_Nodes="999";
} elseif ($Displayed_Nodes === "0") {
    $Displayed_Nodes="999";
}

if (! isset($Display_Count)) {
  $Display_Count=0;
}

if ( ! isset($Show_All)) {
  $Show_All="1";
}

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

if (!file_exists('allmon.ini')) {
    die("Couldn't load allmon ini file.\n");
}
$config = parse_ini_file('allmon.ini', true);

$nodes=array();
foreach ($passedNodes as $i => $node) {
	if (isset($config[$node])) {
		$nodes[] = $node;
	} else {
		print "Warning: Node $node not found in your allmon ini file.";
	}
}

?>
<script type="text/javascript">
$(document).ready(function() {
  if(typeof(EventSource)!=="undefined") {
    var source=new EventSource("server.php?nodes=<?php echo $parms; ?>");

    source.addEventListener('nodes', function(event) {
    var tabledata = JSON.parse(event.data);
    for (var localNode in tabledata) {
        var tablehtml = '';
        var total_nodes = 0;
        var shown_nodes = 0;
        var ndisp = <?php echo (int) $Displayed_Nodes ?>;
	ndisp++;
        var sdisp = <?php echo $Display_Count ?>;
        var sall = <?php echo $Show_All ?>;

var cos_keyed = 0;
var tx_keyed = 0;

for (row in tabledata[localNode].remote_nodes) {
       if (tabledata[localNode].remote_nodes[row].cos_keyed == 1)
		cos_keyed = 1;
	if (tabledata[localNode].remote_nodes[row].tx_keyed == 1)
		tx_keyed = 1;
}

// --- BEGIN STATUS DISPLAY CHANGES ---
// Find the remote_nodes entry that carries the status data.
// On ASL3 3.10.x the local node entry may have node == 1 or node == null
// (null occurs when the node has no connections).
var statusRow = null;
for (var r in tabledata[localNode].remote_nodes) {
    var n = tabledata[localNode].remote_nodes[r].node;
    if (n == 1 || n == "1" || n === null || n === undefined) {
        statusRow = tabledata[localNode].remote_nodes[r];
        break;
    }
}

// Only show the expanded status if at least one of the fields we care about is present.
var hasStatus = false;
if (statusRow) {
    hasStatus = statusRow.ALERT || statusRow.WX || statusRow.LASTUPDATE;
}

if (!hasStatus) {
        // No status data available — show the simple Idle / COS / PTT line
        if (cos_keyed == 0) {
                 if (tx_keyed == 0)
                         tablehtml += '<tr class="gColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>Idle</b></td><td colspan="5"></td></tr>';
                 else
                         tablehtml += '<tr class="tColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>PTT-Keyed</b></td><td colspan="5"></td></tr>';
         } else {
                 if (tx_keyed == 0)
                         tablehtml += '<tr class="lColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>COS-Detected</b></td><td colspan="5"></td></tr>';
                 else
                         tablehtml += '<tr class="bColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="2" align="center"><b>COS-Detected and PTT-Keyed (Full-Duplex)</b></td><td colspan="4"></td></tr>';
         }
} else {
         // Status data is present — build the extra lines and show them under the Idle/COS/PTT label
         var alert = statusRow.ALERT || '';
         var wx    = statusRow.WX || '';
         var last  = statusRow.LASTUPDATE || '';

         var extra = '';
         if (alert) extra += alert + '<br>';
         if (wx)    extra += wx + '<br>';
         if (last)  extra += last;

         if (cos_keyed == 0) {
                 if (tx_keyed == 0)
        tablehtml += '<tr class="gColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>Idle</b><br>' + extra + '</td><td colspan="5"></td></tr>';
         else
tablehtml += '<tr class="tColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>PTT-KEYED</b><br>' + extra + '</td><td colspan="5"></td></tr>';
         } else {
                 if (tx_keyed == 0)
tablehtml += '<tr class="lColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>COS-DETECTED</b><br>' + extra + '</td><td colspan="5"></td></tr>';
                 else
tablehtml += '<tr class="bColor"><td colspan="1" align="center">' + localNode + '</td><td colspan="1" align="center"><b>COS-Detected and PTT-Keyed (Full Duplex)</b><br>' + extra + '</td><td colspan="5"></td></tr>';
         }
}
// --- END STATUS DISPLAY CHANGES ---

	for (row in tabledata[localNode].remote_nodes) {
            if (tabledata[localNode].remote_nodes[row].info === "NO CONNECTION") {
               tablehtml += '<tr><td colspan="7"><b style="color:green"> &nbsp; &nbsp; No Connections.</td></tr>';
            } else {
	       nodeNum=tabledata[localNode].remote_nodes[row].node;	
	       if (nodeNum != 1) {
               total_nodes++
               if (row<ndisp) {
                  if (sall == "1" || tabledata[localNode].remote_nodes[row].last_keyed != "Never" || total_nodes < 2) {
                     shown_nodes++;
	             if (tabledata[localNode].remote_nodes[row].keyed == 'yes') {
		        tablehtml += '<tr class="rColor">';
	             } else if (tabledata[localNode].remote_nodes[row].mode == 'C') {
		        tablehtml += '<tr class="cColor">';
	             } else {
		        tablehtml += '<tr>';
	             }

	             var id = 't' + localNode + 'c0' + 'r' + row;
	             tablehtml += '<td id="' + id + '" align="center" class="nodeNum">' + tabledata[localNode].remote_nodes[row].node + '</td>';

	             if (tabledata[localNode].remote_nodes[row].info != "") {
		     	tablehtml += '<td>' + tabledata[localNode].remote_nodes[row].info + '</td>';
	             } else {
		    	tablehtml += '<td>' + tabledata[localNode].remote_nodes[row].ip + '</td>';
	             }
            	     tablehtml += '<td align="center" id="lkey' + row + '">' + tabledata[localNode].remote_nodes[row].last_keyed + '</td>';
 		     tablehtml += '<td align="center">' + tabledata[localNode].remote_nodes[row].link + '</td>';
	             tablehtml += '<td align="center">' + tabledata[localNode].remote_nodes[row].direction + '</td>';
	             tablehtml += '<td align="right" id="elap' + row +'">' + tabledata[localNode].remote_nodes[row].elapsed + '</td>';

	             if (tabledata[localNode].remote_nodes[row].mode == 'R') {
		      	tablehtml += '<td align="center">Receive Only</td>';
	             } else if (tabledata[localNode].remote_nodes[row].mode == 'T') {
		     	tablehtml += '<td align="center">Transceive</td>';
		     } else if (tabledata[localNode].remote_nodes[row].mode == 'C') {
		    	tablehtml += '<td align="center">Connecting</td>';
	             } else {
		     	tablehtml += '<td align="center">' + tabledata[localNode].remote_nodes[row].mode + '</td>';
	             }
       		     tablehtml += '</tr>';
	          }
                 }  
               }
            }
         }   

         if (sdisp === 1 && total_nodes >= shown_nodes && total_nodes > 1) {
            if (shown_nodes == total_nodes) {
               tablehtml += '<td colspan="2"> &nbsp; &nbsp;' + total_nodes + ' nodes connected.</td></tr>';
            } else {
               tablehtml += '<td colspan="2"> &nbsp; &nbsp;' + shown_nodes + ' shown of ' + total_nodes + ' nodes connected.</td></tr>';
            }
         }

      $('#table_' + localNode + ' tbody:first').html(tablehtml);
    }
});

        source.addEventListener('nodetimes', function(event) {
			var tabledata = JSON.parse(event.data);
			for (localNode in tabledata) {
				tableID = 'table_' + localNode;
				for (row in tabledata[localNode].remote_nodes) {
					rowID='lkey' + row;
					$( '#' + tableID + ' #' + rowID).text( tabledata[localNode].remote_nodes[row].last_keyed );
					rowID='elap' + row;
		 			$( '#' + tableID + ' #' + rowID).text( tabledata[localNode].remote_nodes[row].elapsed );
				}
			}

	                if (spinny == "*") {
                                spinny = "|";
                        } else if (spinny == "|") {
                                spinny = "/";
                        } else if (spinny == "/") {
                                spinny = "-";
                        } else if (spinny == "-") {
                                spinny = "\\";
                        } else if (spinny == "\\") {
                                spinny = "|";
                        } else {
                                spinny = "*";
                        }
                        $('#spinny').html(spinny);
        });

        source.addEventListener('connection', function(event) {
			var statusdata = JSON.parse(event.data);
			tableID = 'table_' + statusdata.node;
			$('#' + tableID + ' tbody:first').html('<tr><td colspan="7">' + statusdata.status + '</td></tr>');
		});

    } else {
        $("#list_link").html("Sorry, your browser does not support server-sent events...");
    }
});
</script>

<?php
if ($_SESSION['sm61loggedin'] === true) {
?>

<div style="border-radius: 10px;" id="connect_form">
<?php 
if (count($nodes) > 0) {
    if (count($nodes) > 1) {
        print "<select id=\"localnode\" class=\"submit\">";
        foreach ($nodes as $node) {
        if (isset($astdb[$node]))
                $info = $astdb[$node][1] . ' ' . $astdb[$node][2] . ' ' . $astdb[$node][3];
        else
                $info = "Node not in astdb database - update the databse";
            print "<option value=\"$node\">$node => $info</option>";
        }
        print "</select>";
    } else {
        print " <input class=\"submit\" type=\"hidden\" id=\"localnode\" value=\"{$nodes[0]}\">";
    }
?>
 <input style="margin-top:10px;" type="text" id="node">
Permanent <input class="submit" type="checkbox"><br/>
Local and Remote:<br>
<input type="button" class="submit" value="Connect" id="connect">
<input type="button" class="submit" value="Disconnect" id="disconnect">
<input type="button" class="submit" value="Monitor" id="monitor">
<input type="button" class="submit" value="Local Monitor" id="localmonitor">
<input type="button" class="<?php echo $SUBMITTER ?>" value="DTMF" id="dtmf">
<input type="button" class="submit" value="Lookup" id="astlookup">
<input type="button" class="submit" value="Rpt Stats" id="rptstats">
<input type="button" class="submit" value="Bubble Chart" id="map">
<input type="button" class="submit" value="Control" id="controlpanel">
<input type="button" class="submit" value="Favorites" id="favoritespanel">

<SCRIPT>
    function OpenActiveNodes () { window.open('http://stats.allstarlink.org'); }
    function OpenAllNodes () { window.open('https://allstarlink.org/nodelist/'); }
    function OpenHelp () { window.open('https://allstarlink.github.io/config/'); }
    function OpenConfigEditor () { window.open('edit/configeditor.php'); }
    function OpenWiki () { window.open('https://allstarlink.github.io/'); }
    function OpenArchiveURL () { window.open('<?php if (! empty($ARCHIVE_URL)) print "$ARCHIVE_URL"; ?>'); }
</SCRIPT>
<br>

Local Only:<br>
<input type="button" class="<?php echo $SUBMITTER ?>" value="Configuration Editor" OnClick="OpenConfigEditor()">
<input type="button" class="<?php echo $SUBMITTER ?>" value="Iax/Rpt/DP RELOAD" id="astreload">
<input type="button" class="<?php echo $SUBMITTER ?>" value="AST START" id="astaron">
<input type="button" class="<?php echo $SUBMITTER ?>" value="AST STOP" id="astaroff">
<input type="button" class="<?php echo $SUBMITTER ?>" value="RESTART" id="fastrestart">
<input style="margin-bottom:1px;" type="button" class="<?php echo $SUBMITTER ?>" value="Server REBOOT" id="reboot">
<br>
<input type="button" class="submit" value="AllStar3 Manual" OnClick="OpenWiki()">
<input style="margin-top:1px;" type="button" class="submit" value="ASL3 Config Files" OnClick="OpenHelp()">
<input type="button" class="submit" value="CPU Status" id="cpustats">
<?php if ($EXTN) { ?>
   <input type="button" class="submit" value="Registry" id="extnodes">
<?php } ?>
<input type="button" class="submit" value="Node Info" id="astnodes">
<input type="button" class="submit" value="Active Nodes" OnClick="OpenActiveNodes()">
<input style="margin-bottom:2px;" type="button" class="submit" value="All Nodes" OnClick="OpenAllNodes()">
<br>
<?php
   if ((! empty($ARCHIVE_URL)) || (`cat /etc/asterisk/rpt.conf |egrep -c ^"archivedir"` > 0)) {
      ?> <input type="button" class="submit" value="Archive" OnClick="OpenArchiveURL()">
      <?php
   }
?>
<input type="button" class="<?php echo $SUBMITTER ?>" value="Linux Log" id="linuxlog">
<input type="button" class="<?php echo $SUBMITTER ?>" value="AST Log" id="astlog">
<input type="button" class="submit" value="Connection Log" id="clog">
<?php if ($IRLPLOG) { ?>
<input type="button" class="submit" value="IRLP Log" id="irlplog">
<?php } ?>
<input type="button" class="<?php echo $SUBMITTER ?>" value="Web Access Log" id="webacclog">
<input type="button" class="submit" value="Web Error Log" id="weberrlog">
<input style="margin-bottom:10px;" type="button" class="submit" value="Restrict" id="openbanallow">
<?php
   if (! empty($DATABASE_TXT)) {
      ?> <input style="margin-bottom:1px;" type="button" class="submit" value="Database" id="database">
      <?php
   }
?>
<br>
<?php
$uptime = exec("$UPTIME");
$hostname = exec("$HOSTNAME |$AWK -F '.' '{print $1}'");
$myday = exec("$DATE '+%A, %B %e, %Y %Z'");
$astport = exec("$CAT /etc/asterisk/iax.conf |$EGREP '^bindport' |$AWK -F\= '{print $2}' |$AWK '{print $1}' |$CUT -f1");
$mgrport = exec("$CAT /etc/asterisk/manager.conf |$EGREP '^port =' |$SED 's/port = //g'");

if (empty($WANONLY)) {
   $myip = exec("$WGET -t 1 -T 3 -q -O- http://checkip.dyndns.org:8245 |$CUT -d':' -f2 |$CUT -d' ' -f2 |$CUT -d'<' -f1");
   $WL=""; $mylanip = exec("ip -4 addr show scope global | awk '/inet/ {print $2}' | cut -d/ -f1 | head -1");
   if ($mylanip == "127.0.0.1") {
      $mylanip = exec("$IFCONFIG |$EGREP inet | grep -v inet6 | $TAIL -1 |$AWK '{print $2}'"); $WL="W";
   }
} else {
   $mylanip = exec("$IFCONFIG |$GREP inet |$HEAD -1 |$AWK '{print $2}'");
   $myip = $mylanip;
}
$myssh = exec("$CAT /etc/ssh/sshd_config |$EGREP '^Port' |$TAIL -1 |$CUT -d' ' -f2");
$webport = exec("$CAT /etc/apache2/ports.conf |$EGREP '^Listen' |$TAIL -1 |$CUT -d' ' -f2 |$SED 's/0.0.0.0://g'");
if ($myip == $mylanip) {
   print "[ $hostname ] [ WAN IP: <a href=\"custom/iplog.txt\" target=\"_blank\">${myip}</a> ] [ WebP: ${webport} ] [ AstP: ${astport} ] [ MgrP: ${mgrport} ] [ SShP: ${myssh} ]";
} else {
   print "[ $hostname ] [ WAN: ${myip} ] [ LAN: ${mylanip} ] [ WebP: ${webport} ] [ AstP: ${astport} ] [ MgrP: ${mgrport} ] [ SShP: ${myssh} ]";
}
}
print "<br>";
print "[ $myday - $uptime ]";
?>
</div>

<?php
}
?>

<?php
   print "<p style=\"margin-bottom:5px;margin-top:10px;\"><input type=\"button\" class=\"submit\" Value=\"Support\" onclick=\"window.open('https://groups.io/g/Supermon','SupermonSupport','status=no,location=no,toolbar=no,width=940,height=970,left=50,top=5')\">";

if (isset($DVM_URL) && isset($DVM_URL_NAME)) {
   print "&nbsp <input type=\"button\" class=\"submit\" Value=\"$DVM_URL_NAME\" onclick=\"window.open('$DVM_URL','DigitalConfiguration','status=no,location=no,toolbar=no,width=940,height=890,left=10,top=10')\">
&nbsp<input type=\"button\" class=\"submit\" Value=\"Display Configuration\" onclick=\"window.open('display-config.php','DisplayConfiguration','status=no,location=no,toolbar=no,width=650,height=610,left=100,top=100')\">";
} else {
   print "&nbsp <input type=\"button\" class=\"submit\" Value=\"Display Configuration\" onclick=\"window.open('display-config.php','DisplayConfiguration','status=no,location=no,toolbar=no,width=650,height=610,left=100,top=100')\">";
}

if (file_exists("/sys/class/thermal/thermal_zone0/temp")) {
    $CPUTemp = exec("/usr/local/sbin/supermon/get_temp");
    print " [ $CPUTemp]";
}

if (isset($SHOW_COREDUMPS) && ($SHOW_COREDUMPS == 'yes')) {
    $Cores = `ls /var/lib/systemd/coredump |wc -w`;
    if (($Cores == 1) || ($Cores == 2)) {
       print " [ Core dump: <span style=\"background-color: yellow; color: black;\">&nbsp;$Cores</span>&nbsp;]";
    } elseif ($Cores > 2) {
       print " [ Core dumps: <span style=\"background-color: red; color: yellow; font-weight: bold;\">&nbsp;$Cores</span>&nbsp;]";
    }
}
if ((`cat /etc/asterisk/rpt.conf |egrep -c ^"outstreamcmd"` > 0) && (`cat /etc/asterisk/rpt.conf |egrep -c ^"archivedir"` > 0) && ($STREAMING_NODE == $ARCHIVING_NODE)) {
   print " [ Streaming & Archiving nodes: $STREAMING_NODE ]";
} else {
   if (`cat /etc/asterisk/rpt.conf |egrep -c ^"outstreamcmd"` > 0) {
      if (isset($STREAMING_NODE)) {
         print " [ Streaming nodes: $STREAMING_NODE ]";
      }
   }
   if (`cat /etc/asterisk/rpt.conf |egrep -c ^"archivedir"` > 0) {
      if (isset($ARCHIVING_NODE)) {
         print " [ Archiving nodes: $ARCHIVING_NODE ]";
      }
   }
}

print "</p><center>";

foreach($nodes as $node) {
    if (isset($astdb[$node]))
        $info = $astdb[$node][1] . ' ' . $astdb[$node][2] . ' ' . $astdb[$node][3];
      else
        $info = "Node not in astdb database - update the database";

    if (($info == "Node not in astdb database - update the database") || (isset($config[$node]['hideNodeURL']) && $config[$node]['hideNodeURL'] == 1)) {
        $nodeURL = $node;
        $title = "&nbsp; Private Node $node => $info &nbsp; ";
        if (isset($config[$node]['lsnodes'])) {
            $lsNodesChart = $config[$node]['lsnodes'];
            $title .= "<a href=\"$lsNodesChart\" target=\"_blank\" id=\"lsnodeschart\">LsNodes</a> &nbsp;";
        } else if ((preg_match("/localhost/", $config[$node]['host'])) || ((preg_match("/127.0.0.1/", $config[$node]['host'])))) {
            $lsNodesChart = "/cgi-bin/sm_lsnodes?node=$node";
            $title .= "<a href=\"$lsNodesChart\" target=\"_blank\" id=\"lsnodeschart\">LsNodes</a> &nbsp;";
        }
    } else {
        $nodeURL = "http://stats.allstarlink.org/nodeinfo.cgi?node=$node";
        $bubbleChart = "http://stats.allstarlink.org/getstatus.cgi?$node";
    	$title = "&nbsp; Node <a href=\"$nodeURL\" target=\"_blank\">$node</a> => $info &nbsp;";
    	$title .= "&nbsp; <a href=\"$bubbleChart\" target=\"_blank\" id=\"bubblechart\">Bubble Chart</a> &nbsp;" ;
        if (isset($config[$node]['lsnodes'])) {
            $lsNodesChart = $config[$node]['lsnodes'];
            $title .= "<a href=\"$lsNodesChart\" target=\"_blank\" id=\"lsnodeschart\">LsNodes</a> &nbsp;";
        } else if ((preg_match("/localhost/", $config[$node]['host'])) || ((preg_match("/127.0.0.1/", $config[$node]['host'])))) {
            $lsNodesChart = "/cgi-bin/sm_lsnodes?node=$node";
            $title .= "<a href=\"$lsNodesChart\" target=\"_blank\" id=\"lsnodeschart\">LsNodes</a> &nbsp;";
        }
        if (isset($config[$node]['listenlive'])) {
            $ListenLiveLink = $config[$node]['listenlive'];
            $title .= "<a href=\"$ListenLiveLink\" target=\"_blank\" id=\"lsnodeschart\">Listen Live</a> &nbsp;";
        }
        if (isset($config[$node]['website'])) {
            $WebSiteLink = $config[$node]['website'];
            $title .= "<a href=\"$WebSiteLink\" target=\"_blank\" id=\"website\">Web Site</a> &nbsp;";
        }
    }
?>
	<table class=gridtable id="table_<?php echo $node ?>">
	<colgroup>
           <col span="1"><col span="1"><col span="1"><col span="1"><col span="1"><col span="1"><col span="1">
        </colgroup>
	<thead>
        <tr><th colspan="7"><i><?php echo $title; ?></i></th></tr>
	<tr><th>&nbsp;&nbsp;Node&nbsp;&nbsp;</th><th>Node Information</th><th>Received</th><th>Link</th><th>Direction</th><th>Connected</th><th>Mode</th></tr>
	</thead>
	<tbody>
	<tr><td colspan="7"> &nbsp; Waiting...</td></tr>
	</tbody>
	</table><br />
<?php
}
?>
</div>
<div style="font-weight: bold;" id="spinny"></div>

<?php include "footer.inc"; ?>