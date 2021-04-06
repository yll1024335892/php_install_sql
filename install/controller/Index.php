<?php
declare (strict_types = 1);


namespace app\install\controller;

class Index
{   
    /**
     * 使用协议
     */
    public function index()
    {
        return view();
    }

    /**
     * 检测安装环境
     */
    public function step1() {

        if (request()->isPost()) {

            // 检测生产环境
            foreach (checkenv() as $key => $value) {

                if ($key == 'php' && (float)$value < 8) {
                    return json(['code'=>101,'msg'=>'PHP版本过低！']);
                }

                if ($value == false && $value != 'redis') {
                    return json(['code'=>101,'msg'=>$key.'扩展未安装！']);
                }
            }

            // 检测目录权限
            foreach (check_dirfile() as $value) {
                if ($value[1] == ERROR 
                    || $value[2] == ERROR) {
                    return json(['code'=>101,'msg'=>$value[3].' 权限读写错误！']);   
                }
            }

            cache('checkenv','success',3600);
            return json(['code'=>200,'url'=>'/install.php/index/step2']);
        }

        return view('',[
            'checkenv' => checkenv(),
            'checkdirfile' => check_dirfile(),
        ]);
    }

    /**
     * 检查环境变量
     */
    public function step2() {

        if (!cache('checkenv')) {
            return redirect('/install.php/index/step1');
        }
 
        if (request()->isPost()) {

            // 链接数据库
            $post = input();
            $connect = @mysqli_connect($post['hostname'] . ':' . $post['hostport'], $post['username'], $post['password']);
            if (!$connect) {
                return json(['code'=>101,'msg'=>'数据库链接失败！']);
            }
    
            // 检测MySQL版本
            $mysqlInfo = mysqli_get_server_info($connect);
            if ((float)$mysqlInfo < 5.6) {
                return json(['code'=>101,'msg'=>'MySQL版本过低！']);
            }
    
            // 查询数据库名
            $database = mysqli_select_db($connect, $post['database']);
            if (!$database) {
                $query = "CREATE DATABASE IF NOT EXISTS `".$post['database']."` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;";
                if (!mysqli_query($connect, $query)) {
                    return json(['code'=>101,'msg'=>'数据库创建失败或已存在，请手动修改！']);
                }
            }
            else {
                $mysql_table = mysqli_query($connect, 'SHOW TABLES FROM'.' '.$post['database']);
                $mysql_table = mysqli_fetch_array($mysql_table);
                if (!empty($mysql_table) && is_array($mysql_table)) {
                    return json(['code'=>101,'msg'=>'数据表已存在，请勿重复安装！']);
                }
            }
            
            cache('mysql',$post,3600);
            return json(['code'=>200,'url'=>'/install.php/index/step3']);
        }

        return view();
    }

    /**
     * 初始化数据库
     */
    public function step3() {

        $mysql = cache('mysql');
        if (!$mysql) {
            return redirect('/install.php/index/step2');
        }

        return view();
    }

    /**
     * 启动安装
     */
    public function install() 
    {
        if (request()->isAjax()) {

            $mysql = cache('mysql');
            if (is_file('../extend/conf/install.lock') || !$mysql) {
                return '请勿重复安装本系统';
            }
    
            // 获取变量文件
            $env = app_path().'install.env';
            $parse = parse_ini_file($env,true);
            $parse['DATABASE']['HOSTNAME'] = $mysql['hostname'];
            $parse['DATABASE']['HOSTPORT'] = $mysql['hostport'];
            $parse['DATABASE']['DATABASE'] = $mysql['database'];
            $parse['DATABASE']['USERNAME'] = $mysql['username'];
            $parse['DATABASE']['PASSWORD'] = $mysql['password'];
            $parse['DATABASE']['PREFIX'] = $mysql['prefix'];
            $content = parse_array_ini($parse);
            write_file(root_path().'.env',$content);
    
            // 读取MySQL数据
            $path = app_path().'install.sql';
            $sql = file_get_contents($path);
            $sql = str_replace("\r", "\n", $sql);
    
            // 替换数据库表前缀
            $sql = explode(";\n", $sql);
            $sql = str_replace(" `sa_", " `{$mysql['prefix']}", $sql);
            
            // 缓存任务总数
            cache('total',count($sql),3600);

            // 链接数据库
            $connect = @mysqli_connect($mysql['hostname'].':'.$mysql['hostport'], $mysql['username'], $mysql['password']);
            mysqli_select_db($connect, $mysql['database']);
            mysqli_query($connect, "set names utf8mb4");
    
            $logs = [];
            $nums = 0;
            try {
                // 写入数据库
                foreach ($sql as $key => $value) {
    
                    cache('progress',$key,3600);
                    $value = trim($value);
                    if (empty($value)) {
                        continue;
                    }
    
                    if (substr($value, 0, 12) == 'CREATE TABLE') {
                        $name = preg_replace("/^CREATE TABLE `(\w+)` .*/s", "\\1", $value);
                        $msg  = "创建数据表 {$name}...";
    
                        if (false !== mysqli_query($connect,$value)) {
                            $msg .= '成功！';
                            $logs[$nums] = [
                                'id'=>$nums,
                                'msg'=>$msg,
                            ];
                            $nums++;
                            cache('tasks',$logs,3600);
                        }
                    } else {
                        mysqli_query($connect,$value);
                    }
                }
    
            } catch (\Throwable $th) { // 异常信息
                cache('error',$th->getMessage(),7200);
                exit();
            }
    
            // 修改初始化密码
            $pwd = hasha($mysql['pwd']);
            mysqli_query($connect,"UPDATE {$mysql['prefix']}admin SET pwd={$pwd} where id = 1");
            write_file(root_path().'extend/conf/install.lock',true);
        }
    }

    /**
     * 获取安装进度
     */
    public function progress() 
    {
        if (request()->isAjax()) {

            // 查询错误
            $error = cache('error');
            if (!empty($error)) {
                return json(['code'=>101,'msg'=>$error]);
            }

            // 获取任务信息
            $tasks = cache('tasks') ?? [
                'id'=>9999,
                'msg'=>'获取任务信息失败！',
            ];
            $progress = round(cache('progress')/cache('total')*100).'%';
   
            $result = [
                'code'=> 200,
                'msg'=> $tasks,  
                'progress'=> $progress,
            ];

            return json($result);
        }
    }

    /**
     * 清理安装文件包
     */
    public function celar() 
    {
        if (request()->isAjax() 
            && is_file('../extend/conf/install.lock')) {

            try {

                // 删除垃圾数据
                $install = public_path().'install.php';
                $content = file_get_contents($install);
                $content = str_replace('install','index',$content);
                if (file_put_contents(public_path().'index.php',$content)) {
                    // unlink($install);
                }

                // 清理安装包
                recursiveDelete(app_path());
            } 
            catch (\Throwable $th) {
                echo $th->getMessage();
            }
        }
    }
}
