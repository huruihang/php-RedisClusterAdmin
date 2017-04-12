<?php

ini_set('memory_limit', '1G');

$config = [
#    'Aniu' => [
#        '172.17.10.79:7001',
#        '172.17.10.79:7002',
#        '172.17.10.79:7003',
#    ],
    '网站开发' => [
        '10.16.6.89:8001',
        '10.16.6.89:8002',
        '10.16.6.89:8003',
    ],
    '网站测试' => [
        '10.16.6.90:8001',
        '10.16.6.90:8002',
        '10.16.6.90:8003',
    ],
    '平台测试' => [
        '10.16.6.16:7000',
        '10.16.6.16:7001',
        '10.16.6.16:7002',
    ],
];
$module = isset($_COOKIE['module']) ? $_COOKIE['module'] : array_keys($config)[0];

$DS = '_';
$HTML = '';

$redisCluster = new \RedisCluster(null, $config[$module], 1, 1, false);
$redisCluster->setOption(\RedisCluster::OPT_SERIALIZER, \RedisCluster::SERIALIZER_PHP);
$redisCluster->setOption(\RedisCluster::OPT_SLAVE_FAILOVER, \RedisCluster::FAILOVER_ERROR);
register_shutdown_function(function ($redisCluster) {
    $redisCluster->close();
}, $redisCluster);
$typeList = ['NOT_FOUND', 'STRING', 'SET', 'LIST', 'ZSET', 'HASH'];

if (isset($_GET['o'])) {
    switch ($_GET['o']) {
        case 'load':
            $prefix = isset($_GET['prefix']) ? $_GET['prefix'] : '';
            $pattern = $prefix === '' ? "*" : "{$prefix}*";
            $keyList = $redisCluster->keys($pattern);
            natsort($keyList);
            $data = [];
            $tmp = [];
            foreach ($keyList as $v) {
                $code = '$tmp';
                foreach (explode($DS, $v) as $kk => $vv) {
                    $code .= "['{$vv}']";
                }
                $code .= '=false;';
                eval($code);
            }
            $code = '$tmp';
            foreach (explode($DS, $prefix) as $kk => $vv) {
                $code .= "['{$vv}']";
            }
            $childList = eval("return isset($code) ? $code : [];");
            $tmp = $childList ? $childList : $tmp;
            foreach ($tmp as $k => $v) {
                $isParent = (bool)$v;
                $data[] = [
                    'name' => $k,
                    'isParent' => $isParent,
                    'prefix' => $isParent ? ($prefix === '' ? $k : $prefix . $DS . $k) : '',
                    'key' => $isParent ? '' : ($prefix === '' ? $k : $prefix . $DS . $k),
                ];
            }

            echo json_encode($data);
            unset($tmp, $childList);
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
            if (is_array($data) || is_object($data)) {
                $data = print_r($data, 1);
            } else {
                $data = htmlspecialchars($data);
                $data = wordwrap($data, 300, "\n", true);
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

    try {
        $dbSize = $redisCluster->dbSize($v);
    } catch (\Exception $e) {
        $dbSize = "";
    }
    $sysInfo .= "<tr><td>DBSIZE</td><td>{$dbSize}</td></tr>";

    try {
        $info = $redisCluster->info($v);
    } catch (\Exception $e) {
        $info = [];
    }
    $optList = [
        'redis_version', 'uptime_in_days',
        'connected_clients', 'blocked_clients',
        'used_memory_human', 'used_memory_rss_human', 'used_memory_peak_human', 'total_system_memory_human', 'maxmemory_human', 'maxmemory_policy',
        'used_cpu_sys', 'used_cpu_user',
    ];
    $sysInfo .= "<tr><td colspan='2'>&nbsp;</td></tr>";
    $sysInfo .= "<tr><td colspan='2'>INFO</td></tr>";
    foreach ($info as $kk => $vv) {
        if (in_array($kk, $optList)) {
            $sysInfo .= "<tr><td>{$kk}</td><td style='word-break: break-all;'>{$vv}</td></tr>";
        }
    }

    try {
        $client = $redisCluster->client($v, 'LIST');
    } catch (\Exception $e) {
        $client = [];
    }
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
    $optStr .= "<option " . ($module == $k ? "selected" : "") . ">{$k}</option>";
}

$HTML .= '<!DOCTYPE html>
<html>
<head>
<title>Redis Cluster Admin</title>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
<link rel="stylesheet" href="http://cdn.staticfile.org/ztree/3.5.16/css/zTreeStyle/zTreeStyle.css" type="text/css">
<link href="http://cdn.bootcss.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
<script type="text/javascript" src="http://cdn.staticfile.org/jquery/2.2.1/jquery.min.js"></script>
<script type="text/javascript" src="http://cdn.staticfile.org/jquery-cookie/1.4.1/jquery.cookie.min.js"></script>
<script type="text/javascript" src="http://cdn.staticfile.org/ztree/3.5.16/js/jquery.ztree.all-3.5.min.js"></script>
</head>
<body>
<table width="100%" height="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td width="200" valign="top">
            <table cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td valign="top" colspan="2"><select id="module">' . $optStr . '</select></td>
                </tr>
                <tr>
                    <td id="nav" valign="top"></td>
                    <td valign="top"><ul id="tree" class="ztree"></ul></td>
                </tr>
            </table>
        </td>
        <td id="content" valign="top">' . $sysInfo . '</td>
    </tr>
</table>
<script>
    var zTreeObj;
    var setting = {
        async: {
            enable: true,
            type: "get",
            url:"?o=load",
            autoParam:["name","prefix"]
        },
        callback: {
            beforeExpand: function(treeId, treeNode) {
                if(treeNode.level==0) {
                    if(treeNode.isParent) {
                        treeNode.prefix = treeNode.name;
                    } else {
                        treeNode.key = treeNode.name;
                    }
                }
            },
            beforeRemove: function(treeId, treeNode) {
                if(treeNode.isParent && !confirm("Delete？")) {
                    return false;
                }
            },
            onClick: function(event, treeId, treeNode) {
                if(treeNode.level==0) {
                    if(treeNode.isParent) {
                        treeNode.prefix = treeNode.name;
                    } else {
                        treeNode.key = treeNode.name;
                    }
                }
                if(treeNode.isParent) {
                    var url = "?o=count&prefix=" + treeNode.prefix;
                } else {
                    var url = "?o=get&key=" + treeNode.key;
                }
                jQuery("#content").load(url);
            },
            onRemove: function(event, treeId, treeNode) {
                if(treeNode.level==0) {
                    if(treeNode.isParent) {
                        treeNode.prefix = treeNode.name;
                    } else {
                        treeNode.key = treeNode.name;
                    }
                }
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
        $("#module").change(function(e) {
            $.cookie("module", $(this).val());
            $(this).delay(200).queue(function(){
                location.reload();
            });
        });
        var i = 97;
        var html = "<i class=\"fa fa-font\" style=\"cursor: pointer;\" id=\"conv\" val=\"0\"></i><br>";
        while (i <= 122) {
            html += "<label style=\"cursor: pointer;\" class=\"navChr\">" + String.fromCharCode(i) + "</label><br>";
            i++;
        }
        i = 48;
        while (i <= 57) {
            html += "<label style=\"cursor: pointer;\" class=\"navNum\">" + String.fromCharCode(i) + "</label><br>";
            i++;
        }
        $("#nav").append(html);
        $(".navChr,.navNum").click(function(e) {
            zTreeObj.destroy();
            setting.async.url = "?o=load&prefix=" + $(this).text();
            zTreeObj = $.fn.zTree.init($("#tree"), setting);
        });
        $("#conv").click(function(e) {
            var $this = $(this);
            var isUpper = $this.attr("val") | 0;
            var i = 0;
            $(".navChr").each(function(k, v) {
                if(isUpper) {
                    i = $(v).text().charCodeAt() | 0;
                    $(v).text(String.fromCharCode(i + 32));
                    $this.attr("val", 0);
                } else {
                    i = $(v).text().charCodeAt() | 0;
                    $(v).text(String.fromCharCode(i - 32));
                    $this.attr("val", 1);
                }
            });
        });
    });
</script>
</body>
</html>';
echo $HTML;