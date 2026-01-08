<?php

/**
 * Yii2 框架智能提示配置
 * 
 * 这个文件帮助 IDE 更好地理解 Yii2 框架的结构和方法
 */

// Yii 核心类的智能提示
namespace {
    /**
     * Yii 是 Yii 框架的辅助类
     * @property \yii\web\Application|\yii\console\Application $app 应用实例
     */
    class Yii
    {
        /**
         * @var \yii\web\Application|\yii\console\Application
         */
        public static $app;

        /**
         * 记录错误日志
         * @param string $message
         * @param string $category
         */
        public static function error($message, $category = 'application') {}

        /**
         * 记录警告日志
         * @param string $message
         * @param string $category
         */
        public static function warning($message, $category = 'application') {}

        /**
         * 记录信息日志
         * @param string $message
         * @param string $category
         */
        public static function info($message, $category = 'application') {}

        /**
         * 创建对象
         * @param mixed $type
         * @param array $params
         * @return object
         */
        public static function createObject($type, array $params = []) {}
    }
}

namespace yii\web {
    /**
     * Web 应用类
     */
    class Application
    {
        /**
         * @var \yii\web\Request
         */
        public $request;

        /**
         * @var \yii\web\Response
         */
        public $response;

        /**
         * @var \yii\web\User
         */
        public $user;

        /**
         * @var mixed
         */
        public $helper;
    }

    /**
     * HTTP 请求类
     */
    class Request
    {
        /**
         * 获取 GET 参数
         * @param string|null $name
         * @param mixed $defaultValue
         * @return mixed
         */
        public function get($name = null, $defaultValue = null) {}

        /**
         * 获取 POST 参数
         * @param string|null $name
         * @param mixed $defaultValue
         * @return mixed
         */
        public function post($name = null, $defaultValue = null) {}

        /**
         * 获取用户 IP
         * @return string
         */
        public function getUserIP() {}
    }

    /**
     * HTTP 响应类
     */
    class Response
    {
        const FORMAT_RAW = 'raw';
        const FORMAT_HTML = 'html';
        const FORMAT_JSON = 'json';
        const FORMAT_JSONP = 'jsonp';
        const FORMAT_XML = 'xml';

        /**
         * @var int HTTP 状态码
         */
        public $statusCode;

        /**
         * @var string 响应格式
         */
        public $format;
    }

    /**
     * HTTP 异常类
     */
    class HttpException extends \yii\base\Exception
    {
        /**
         * @param int $status HTTP 状态码
         * @param string $message 错误消息
         * @param int $code 错误代码
         * @param \Exception $previous 前一个异常
         */
        public function __construct($status, $message = null, $code = 0, \Exception $previous = null) {}
    }
}

namespace yii\db {
    /**
     * ActiveRecord 基类
     */
    class ActiveRecord
    {
        /**
         * 查找一条记录
         * @param mixed $condition
         * @return static|null
         */
        public static function findOne($condition) {}

        /**
         * 创建查询对象
         * @return ActiveQuery
         */
        public static function find() {}

        /**
         * 保存记录
         * @param bool $runValidation
         * @param array $attributeNames
         * @return bool
         */
        public function save($runValidation = true, $attributeNames = null) {}
    }

    /**
     * ActiveQuery 查询类
     */
    class ActiveQuery
    {
        /**
         * 添加 WHERE 条件
         * @param mixed $condition
         * @return $this
         */
        public function where($condition) {}

        /**
         * 获取一条记录
         * @return ActiveRecord|null
         */
        public function one() {}

        /**
         * 获取所有记录
         * @return ActiveRecord[]
         */
        public function all() {}
    }
}
