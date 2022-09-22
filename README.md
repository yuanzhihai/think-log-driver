# think-log-driver
think-log-driver

## 依赖
适用于`thinkphp6.*`
php: >=7.1

## 安装 
1. 安装`think-log-driver`
```sh
composer require yzh52521/think-log-driver
```

## 使用
1. 更改配置  
在`config/log.php` 中的配置修改
```php
// 日志记录方式
// 日志通道列表

    'channels'     => [
        'file'=>[],
        'database' => [
            // 日志记录方式
            'type'           => 'Database',
            // 大于0.05秒的sql将被记录
            'slow_sql_time'  => 0.5,
            // 记录日志的数据库配置，即在database.php中的driver
            // 如果设置该值为'default'，则使用系统数据库的实例
            'db_connect'     => 'default', //mongodb 
            // 记录慢日志查询的数据表名
            'db_table'       => 'log_sql',
            // 忽略的操作，在以下数据中的操作不会被记录
            'action_filters' => [
                // 'index/Index/lst'
            ],
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => [],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
],
```

2. 创建数据库  
用于记录日志的mysql数据表,如果使用mongodb则无需创建
```sql
CREATE TABLE `th_log_sql` (
	`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`host` CHAR(200) NOT NULL DEFAULT '',
	`uri` CHAR(200) NOT NULL DEFAULT '',
	`ip` CHAR(50) NOT NULL DEFAULT '',
	`method` CHAR(50) NOT NULL DEFAULT '',
	`app` CHAR(30) NOT NULL DEFAULT '',
	`controller` CHAR(30) NOT NULL DEFAULT '',
	`action` CHAR(50) NOT NULL DEFAULT '',
	`create_time` INT(11) NOT NULL DEFAULT '0',
	`create_date` DATETIME NULL DEFAULT NULL,
	`runtime` DECIMAL(10,3) UNSIGNED NOT NULL DEFAULT '0.000',
	`sql_list` TEXT NULL,
	`param` TEXT NULL,
	PRIMARY KEY (`id`),
	INDEX `runtime` (`runtime`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB
AUTO_INCREMENT=1
;
```
