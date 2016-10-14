<?php

$config = [
    'default' => [
        'localhost:7001',
        'localhost:7002',
        'localhost:7003',
    ],
];
$module = isset($_COOKIE['module']) ? $_COOKIE['module'] : array_keys($config)[0];

$DS = '_';
$HTML = '';

$redisCluster = new RedisCluster(null, $config[$module], 0.1, 1);
$redisCluster->setOption(RedisCluster::OPT_SERIALIZER, RedisCluster::SERIALIZER_PHP);
$redisCluster->setOption(RedisCluster::OPT_SLAVE_FAILOVER, RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
register_shutdown_function(function ($redisCluster) {
    $redisCluster->close();
}, $redisCluster);
$typeList = ['NOT_FOUND', 'STRING', 'SET', 'LIST', 'ZSET', 'HASH'];

if (isset($_GET['o'])) {
    switch ($_GET['o']) {
        case 'load':
            $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : '';
            $pattern = $prefix ? "{$prefix}{$DS}*" : "*";
            $keyList = $redisCluster->keys($pattern);
            sort($keyList);
            $data = [];
            $lastVal = '';
            foreach ($keyList as $k => $v) {
                $key = $v;
                if ($prefix) {
                    $v = substr($v, strlen($prefix . $DS));
                }
                $splitData = explode($DS, $v);
                $splitCount = count($splitData);
                $name = $splitData[0];
                if ($splitCount > 1) {
                    if ($name == $lastVal) continue;
                    $isParent = true;
                    $lastVal = $name;
                } else {
                    $isParent = false;
                }
                $data[] = [
                    'name' => $name,
                    'isParent' => $isParent,
                    'prefix' => $isParent ? ($prefix ? "{$prefix}{$DS}{$name}" : $name) : '',
                    'key' => $isParent ? '' : $key,
                ];
            }
            echo json_encode($data);
            break;
        case 'get':
            $key = isset($_GET['key']) ? $_GET['key'] : '';
            $type = $redisCluster->type($key);
            $ttl = $redisCluster->ttl($key);
            $ttl = $ttl < 0 ? ($ttl == -1 ? 'forever' : 'not found') : "{$ttl} s";
            $size = 0;
            switch ($type) {
                case 0:
                    $data = '((NOT FOUND))';
                    break;
                case 1:
                    $data = $redisCluster->get($key);
                    break;
                case 2:
                    $data = $redisCluster->hGetAll($key);
                    break;
                case 3:
                    $data = $redisCluster->lRange($key, 0, -1);
                    break;
                case 4:
                    $data = $redisCluster->sMembers($key);
                    break;
                case 5:
                    $data = $redisCluster->zRange($key, 0, -1, 1);
                    break;
            }

            if ($type) {
                $size = strlen(serialize($data)) . ' b';
            }
            if (is_array($data)) {
                $data = print_r($data, 1);
            }

            $HTML .= '<table width="100%" cellspacing="0" cellpadding="0" border="0">';
            $HTML .= "<tr><td>KEY</td><td>{$key}</td></tr>";
            $HTML .= "<tr><td>TYPE</td><td>{$typeList[$type]}</td></tr>";
            $HTML .= "<tr><td>TTL</td><td>{$ttl}</td></tr>";
            $HTML .= "<tr><td>SIZE</td><td>{$size}</td></tr>";
            $HTML .= "<tr><td colspan='2'><pre>{$data}</pre></td></tr>";
            $HTML .= '</table>';
            echo $HTML;
            break;
        case 'count':
            $prefix = isset($_GET['prefix']) ? $_GET['prefix'] : '';
            $pattern = $prefix ? "{$prefix}{$DS}*" : "*";
            $keyList = $redisCluster->keys($pattern);
            sort($keyList);
            $count = count($keyList);

            $HTML .= '<table width="100%" cellspacing="0" cellpadding="0" border="0">';
            $HTML .= "<tr><td>KEYS</td><td>{$pattern}</td></tr>";
            $HTML .= "<tr><td>COUNT</td><td>{$count}</td></tr>";
            $HTML .= '</table>';
            echo $HTML;
            break;
        case 'del':
            $key = isset($_GET['key']) ? $_GET['key'] : '';
            if ($key) {
                $redisCluster->del($key);
                break;
            }

            $prefix = isset($_GET['prefix']) ? $_GET['prefix'] : '';
            if ($prefix) {
                $pattern = $prefix ? "{$prefix}{$DS}*" : "*";
                $keyList = $redisCluster->keys($pattern);
                $redisCluster->del($keyList);
                break;
            }

            break;
    }
    exit;
}

$sysInfo = '<table width="100%" height="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
$masterList = [];
foreach ($redisCluster->_masters() as $v) {
    $masterList[$v[0] . $v[1]] = $v;
}
ksort($masterList);
foreach ($masterList as $v) {
    $sysInfo .= '<td valign="top"><table cellspacing="0" cellpadding="0" border="0">';

    $sysInfo .= "<tr><td>HOST</td><td>{$v[0]}:{$v[1]}</td></tr>";

    $dbSize = $redisCluster->dbSize($v);
    $sysInfo .= "<tr><td>DBSIZE</td><td>{$dbSize}</td></tr>";

    $time = implode('.', $redisCluster->time($v));
    $sysInfo .= "<tr><td>TIME</td><td>{$time}</td></tr>";

    $info = $redisCluster->info($v);
    $optList = [
        'redis_version', 'redis_mode', 'process_id', 'run_id', 'uptime_in_days',
        'connected_clients', 'blocked_clients',
        'used_memory_human', 'used_memory_rss_human', 'used_memory_peak_human', 'total_system_memory_human', 'maxmemory_human', 'maxmemory_policy',
        'total_connections_received', 'rejected_connections', 'expired_keys', 'evicted_keys', 'keyspace_hits', 'keyspace_misses',
        'role', 'connected_slaves',
        'used_cpu_sys', 'used_cpu_user',
        'cluster_enabled',
    ];
    $sysInfo .= "<tr><td colspan='2'>&nbsp;</td></tr>";
    $sysInfo .= "<tr><td colspan='2'>INFO</td></tr>";
    foreach ($info as $kk => $vv) {
        if (in_array($kk, $optList)) {
            $sysInfo .= "<tr><td>{$kk}</td><td style='word-break: break-all;'>{$vv}</td></tr>";
        }
    }

    $client = $redisCluster->client($v, 'LIST');
    $sysInfo .= "<tr><td colspan='2'>&nbsp;</td></tr>";
    $sysInfo .= "<tr><td colspan='2'>CLIENT</td></tr>";
    foreach ($client as $vv) {
        $sysInfo .= "<tr><td>{$vv['addr']}</td><td>{$vv['cmd']}</td></tr>";
    }

    $sysInfo .= '</table></td>';
}
$sysInfo .= '</tr></table>';

$optStr = '';
foreach ($config as $k => $v) {
    $optStr .= "<option>{$k}</option>";
}

$HTML .= '<!DOCTYPE html>
<html>
<head>
<title>Redis Cluster Admin</title>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
<link rel="stylesheet" href="http://cdn.staticfile.org/ztree/3.5.16/css/zTreeStyle/zTreeStyle.css" type="text/css">
<script type="text/javascript" src="http://cdn.staticfile.org/jquery/2.2.1/jquery.min.js"></script>
<script type="text/javascript" src="http://cdn.staticfile.org/jquery-cookie/1.4.1/jquery.cookie.min.js"></script>
<script type="text/javascript" src="http://cdn.staticfile.org/ztree/3.5.16/js/jquery.ztree.all-3.5.min.js"></script>
</head>
<body>
<table width="100%" height="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td width="200" valign="top">
<select id="module">' . $optStr . '</select>
<ul id="tree" class="ztree"></ul>
</td>
<td id="content" valign="top">' . $sysInfo . '</td>
</tr>
</table>
<script>
    var zTreeObj;
    var setting = {
        async: {
            enable: true,
            url:"?o=load",
            autoParam:["name","prefix"]
        },
        callback: {
            beforeRemove: function(treeId, treeNode) {
                if(treeNode.isParent && !confirm("Delete？")) {
                    return false;
                }
            },
            onClick: function(event, treeId, treeNode) {
                if(treeNode.isParent) {
                    var url = "?o=count&prefix=" + treeNode.prefix;
                } else {
                    var url = "?o=get&key=" + treeNode.key;
                }
                jQuery("#content").load(url);
            },
            onRemove: function(event, treeId, treeNode) {
                if(treeNode.isParent) {
                    var url = "?o=del&prefix=" + treeNode.prefix;
                } else {
                    var url = "?o=del&key=" + treeNode.key;
                }
                jQuery.get(url);
            }
        },
        edit: {
            enable: true,
            showRenameBtn: false
        },
        view: {
            showIcon: false
        }
    };
    jQuery(function($) {
        zTreeObj = $.fn.zTree.init($("#tree"), setting);
        $("#module").change( function(e) {
            $.cookie("module", $(this).val());
        });
    });
</script>
</body>
</html>';
echo $HTML;