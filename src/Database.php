<?php

declare (strict_types=1);

namespace think\log\driver;

use think\App;
use think\facade\Config;
use think\facade\Db;
use think\contract\LogHandlerInterface;

class Database implements LogHandlerInterface
{
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        'time_format'  => 'c',
        'single'       => false,
        'file_size'    => 2097152,
        'path'         => '',
        'apart_level'  => [],
        'max_files'    => 0,
        'json'         => false,
        'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        'format'       => '[%s][%s] %s',
    ];
    protected $app;

    // 实例化并传入参数
    public function __construct(App $app, $config = [])
    {
        $this->app = $app;
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }

        if (empty($this->config['format'])) {
            $this->config['format'] = '[%s][%s] %s';
        }

        if (empty($this->config['path'])) {
            $this->config['path'] = $app->getRuntimePath() . 'log';
        }

        if (substr($this->config['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->config['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 日志写入接口
     * @access public
     * @param array $log 日志信息
     * @param bool $append 是否追加请求信息
     * @return bool
     */
    public function save(array $log, $append = false): bool
    {
        $this->writeDb($log);
        $destination = $this->getMasterLogFile();
        $path        = dirname($destination);
        !is_dir($path) && mkdir($path, 0755, true);

        $info = [];

        foreach ($log as $type => $val) {
            foreach ($val as $msg) {
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }

                $info[$type][] = $this->config['json'] ? $msg : '[ ' . $type . ' ] ' . $msg;
            }

            if (!$this->config['json'] && (true === $this->config['apart_level'] || in_array(
                        $type,
                        $this->config['apart_level']
                    ))) {
                // 独立记录的日志级别
                $filename = $this->getApartLevelFile($path, $type);

                $this->write($info[$type], $filename, true, $append);

                unset($info[$type]);
            }
        }

        if ($info) {
            return $this->write($info, $destination, false, $append);
        }

        return true;
    }

    /**
     * 写入日志到数据库
     * @param $message
     * @return \Exception|string
     */
    protected function writeDb($message)
    {
        if (PHP_SAPI == 'cli') {
            return '';
        }
        if (!isset($message['sql'])) {
            return '';
        }
        $log_db_connect = Config::get('log.db_connect', 'default');
        $app_name       = app('http')->getName();
        $controller     = $this->app->request->controller();
        $action         = $this->app->request->action();

        //忽略操作
        if (in_array($app_name . '/' . $controller . '/' . $action, $this->config['action_filters'])) {
            return '';
        }

        $sql         = [];
        $runtime_max = 0;

        foreach ($message['sql'] as $k => $v) {
            $db_k = 0;
            if (strstr($v, 'SHOW FULL COLUMNS') || strstr($v, 'CONNECT:')) {
                continue;
            }
            $runtime = floatval(substr($v, strrpos($v, 'RunTime:') + 8, -3));
            if ($runtime >= $this->config['slow_sql_time']) {
                $sql[] = [
                    'db'      => substr($message['sql'][$db_k], 30),
                    'sql'     => $v,
                    'runtime' => $runtime,
                ];
                $runtime_max < $runtime && $runtime_max = $runtime;
            }
        }

        if (!$sql) {
            return '';
        }
        $time = time();
        $info = [
            'ip'          => $this->app->request->ip(),
            'method'      => $this->app->request->method(),
            'host'        => $this->app->request->host(),
            'uri'         => $this->app->request->url(),
            'app'         => $app_name,
            'controller'  => $controller,
            'action'      => $action,
            'create_time' => $time,
            'create_date' => date('Y-m-d H:i:s'),
            'runtime'     => $runtime_max,
        ];
        if ($log_db_connect === 'mongodb') {
            $info['sql_list']   = $sql;
            $info['sql_source'] = $message['sql'];
        } else {
            $info['sql_list']   = json_encode($sql);
            $info['sql_source'] = json_encode($message['sql']);
        }
        $log_table = $this->config['db_table'];
        $msg       = 'success';
        if ($log_db_connect === 'default') {
            try {
                Db::name($log_table)->insert($info);
            } catch (\Exception $e) {
                $msg = $e;
            }
        } else {
            try {
                Db::connect($log_db_connect)->name($log_table)->insert($info);
            } catch (\Exception $e) {
                $msg = $e;
            }
        }

        return $msg;
    }

    /**
     * 日志写入
     * @access protected
     * @param array $message 日志信息
     * @param string $destination 日志文件
     * @param bool $apart 是否独立文件写入
     * @param bool $append 是否追加请求信息
     * @return bool
     */
    protected function write(array $message, string $destination, $apart = false, $append = false):bool
    {
        // 检测日志文件大小，超过配置大小则备份日志文件重新生成
        $this->checkLogSize($destination);

        // 日志信息封装
        $info['timestamp'] = date($this->config['time_format']);

        foreach ($message as $type => $msg) {
            $msg         = is_array($msg) ? implode(PHP_EOL, $msg) : $msg;
            $info[$type] = $msg;
        }

        $this->getDebugLog($info, $append, $apart);
        $message = $this->parseLog($info);

        return error_log($message, 3, $destination);
    }

    /**
     * 获取主日志文件名
     * @access public
     * @return string
     */
    protected function getMasterLogFile():string
    {
        if ($this->config['max_files']) {
            $files = glob($this->config['path'] . '*.log');

            try {
                if (count($files) > $this->config['max_files']) {
                    unlink($files[0]);
                }
            } catch (\Exception $e) {
            }
        }

        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';

            $destination = $this->config['path'] . $name  . '.log';
        } else {
            if ($this->config['max_files']) {
                $filename = date('Ymd')  . '.log';
            } else {
                $filename = date('Ym') . DIRECTORY_SEPARATOR . date('d')  . '.log';
            }

            $destination = $this->config['path'] . $filename;
        }

        return $destination;
    }

    /**
     * 获取独立日志文件名
     * @access public
     * @param string $path 日志目录
     * @param string $type 日志类型
     * @return string
     */
    protected function getApartLevelFile(string $path, string $type):string
    {
        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';
        } elseif ($this->config['max_files']) {
            $name = date('Ymd');
        } else {
            $name = date('d');
        }

        return $path . DIRECTORY_SEPARATOR . $name . '_' . $type  . '.log';
    }

    /**
     * 检查日志文件大小并自动生成备份文件
     * @access protected
     * @param string $destination 日志文件
     */
    protected function checkLogSize(string $destination)
    {
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                rename(
                    $destination,
                    dirname($destination) . DIRECTORY_SEPARATOR . time() . '-' . basename($destination)
                );
            } catch (\Exception $e) {
            }
        }
    }


    /**
     * 解析日志
     * @access protected
     * @param array $info 日志信息
     * @return string
     */
    protected function parseLog(array $info):string
    {
        $requestInfo = [
            'ip'     => $this->app->request->ip(),
            'method' => $this->app->request->method(),
            'host'   => $this->app->request->host(),
            'uri'    => $this->app->request->url(),
        ];

        if ($this->config['json']) {
            $info = $requestInfo + $info;
            return json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        array_unshift(
            $info,
            "---------------------------------------------------------------" . PHP_EOL . "\r\n[{$info['timestamp']}] {$requestInfo['ip']} {$requestInfo['method']} {$requestInfo['host']}{$requestInfo['uri']}"
        );
        unset($info['timestamp']);

        return implode(PHP_EOL, $info) . PHP_EOL;
    }

    /**
     * 调试日志
     * @param $info
     * @param $append
     * @param $apart
     */
    protected function getDebugLog(&$info, $append, $apart)
    {
        if ($this->app->isDebug() && $append) {
            if ($this->config['json']) {
                // 获取基本信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';

                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);

                $info = [
                        'runtime' => number_format($runtime, 6) . 's',
                        'reqs'    => $reqs . 'req/s',
                        'memory'  => $memory_use . 'kb',
                        'file'    => count(get_included_files()),
                    ] + $info;
            } elseif (!$apart) {
                // 增加额外的调试信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';

                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);

                $time_str   = '[运行时间：' . number_format($runtime, 6) . 's] [吞吐率：' . $reqs . 'req/s]';
                $memory_str = ' [内存消耗：' . $memory_use . 'kb]';
                $file_load  = ' [文件加载：' . count(get_included_files()) . ']';

                array_unshift($info, $time_str . $memory_str . $file_load);
            }
        }
    }
}
