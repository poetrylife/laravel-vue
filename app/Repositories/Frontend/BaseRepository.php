<?php
namespace App\Repositories\Frontend;

use App\Models\Dict;
use App\Models\UserOperateRecord;
use Illuminate\Database\Eloquent\Model as Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Auth;

abstract class BaseRepository
{
    protected static $instance;
    protected $user_id;

    //获取实例化
    public static function getInstance()
    {
        $class = get_called_class();
        if (!isset(self::$instance[$class])) {
            self::$instance[$class] = new $class;
        }
        return self::$instance[$class];
    }

    /**
     * 响应返回
     * @param  bool $status  true or false
     * @param  array  $data    返回结果集
     * @param  string $message 消息提示
     * @return json
     */
    public function responseResult($status, $data = [], $message = '')
    {
        return [
            'status' => $status,
            'data' =>  $data,
            'message' => $message === '' ? (!$status ? '失败' : '成功') : $message,
        ];
    }

    /**
     * 过滤，重组查询参数
     * @param  Array $params
     * @return Array [key => [condition=>'', value=>'']]
     */
    public function parseParams($table_name, $params)
    {
        if (empty($params)) {
            return [];
        }
        $field_lists = Schema::getColumnListing($table_name); // 获取数据表所有字段
        $param_rules = isset(config('ububs.param_rules')[$table_name]) ? config('ububs.param_rules')[$table_name] : []; // 获取过滤规则
        $result = [];
        foreach ($params as $key => $value) {
            // 参数不在表内直接过滤
            if (!in_array($key, $field_lists) || $value === '' || $value === [] || $value === null) {
                continue;
            }
            // 参数过滤方式
            $result[$key] = [
                'condition' => isset($param_rules[$key]) ? $param_rules[$key] : '=',
                'value' => $value
            ];
        }
        return $result;
    }

    /**
     * 获取字典数据
     * @param  Array $code_arr
     * @return Object
     */
    public function getDictsByCodeArr($code_arr)
    {
        $result = [];
        if (!empty($code_arr) && is_array($code_arr)) {
            $result = Dict::whereIn('code', $code_arr)->get();
        }
        return $result;
    }

    /**
     * 记录操作日志
     * @param  Array  $input [action, params, text, status]
     * @return Array
     */
    public function saveOperateRecord($input)
    {
        UserOperateRecord::create([
            'user_id'    => $this->getUserId(),
            'action'     => isset($input['action']) ? validateValue($input['action']) : '',
            'params'     => isset($input['params']) ? json_encode($input['params']) : '',
            'text'       => isset($input['text']) ? validateValue($input['text'], 'string') : '操作成功',
            'ip_address' => getClientIp(),
            'status'     => isset($input['status']) ? validateValue($input['status'], 'int') : 1,
        ]);
    }

    /**
     * 获取当前用户id
     * @return Int
     */
    public function getUserId()
    {
        if (Auth::guard('web')->check()) {
            return Auth::guard('web')->id();
        } else {
            return 0;
        }
    }

    /**
     * 获取redis的值
     * @param  Array $key_type_arr  [redis key_type_arr],只包含string  和 hash
     * @return Array
     */
    public function getRedisListsValue($key_item_arr, $flag = true)
    {
        $result = [];
        if (!empty($key_type_arr)) {
            return $result;
        }
        foreach ($key_type_arr as $redis_key => $redis_item) {
            // 如果redis值为空，则清除所有缓存，重新生成
            if ($flag && !Redis::exist($redis_key)) {
                $flag = false;
                $this->refreshRedisCache();
            }
            // 表示为 string 类型
            if (is_string($redis_item)) {
                $result[$redis_key] = Redis::get($redis_key);
            } else {
                // 表示为 hash
                 if ($flag && !Redis::hexists($redis_key, $redis_item[0])) {
                    $flag = false;
                    $this->refreshRedisCache();
                 }
                 $result[$redis_key] = Redis::hget($redis_key, $redis_item[0]);
            }
            if ($redis_type == 'string') {
                $result[$redis_key] = Redis::get($redis_key);
            } else if ($redis_type == 'hash') {
                $result[$redis_key] = Redis::hget($redis_key);
            }
        }
        return $result;
    }

    /**
     * 清空redis缓存，并且重新生成缓存
     * @return [type] [description]
     */
    public function refreshRedisCache()
    {
        Redis::flushdb();
        $dict_lists = DB::table('dicts')->where('status', 1)->get();

        if (!empty($dict_lists)) {
            $dict_redis_key = 'dicts_';
            foreach ($dict_lists as $key => $dict) {
                Redis::hset('dicts_' . $dict->code, $dict->text_en, $dict->value);
            }
        }
        return true;
    }
}
