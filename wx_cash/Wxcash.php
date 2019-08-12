<?php
namespace app\index\controller;
use think\Db;
class Wxcash extends Home{
	public function index(){
        $money = 1;
        $uid = 1 ;
        // $data = Db::name('home_cash_withdrawal')->select();
        // var_dump($data);die;
       if(!$this->JudgeCashCharge($money,$uid)){
            return $this->error("账户余额不足！");
       }

       $this->drawal_charge = $this->getCashCharge($money);//手续费
        $this->partner_trade_no = generateChargeOrderNo("SC");
        //添加账户
        $order_id = $this->generateSmallChargeList($uid, $this->partner_trade_no,$money);
        if(!$order_id){
            return $this->error("提现失败！");
        }
        
		$this->wxcash($money,$uid);
	}

    //OPENIDid
    protected $openId ;
    //订单号码
    protected $partner_trade_no;
    protected $drawal_charge;//手续费
	protected function wxcash($money,$uid)
    {
        $appid = config('appId');//商户账号appid
        $mch_id = config('mch_id');//商户号
        $key = config('api_key');
        $openid = 'owcHBwfFbHxaoG0NEE6RUzgLLvs8';//授权用户openid

        $arr = array();
        $arr['mch_appid'] = $appid;
        $arr['mchid'] = $mch_id;
        $arr['nonce_str'] = md5(uniqid(microtime(true),true));//随机字符串，不长于32位
        $arr['partner_trade_no'] = $this->partner_trade_no;//商户订单号
        $arr['openid'] = $openid;
        $arr['check_name'] = 'NO_CHECK';//是否验证用户真实姓名，这里不验证
        $arr['amount'] = $money * 100;//付款金额，单位为分
        $arr['desc'] = "零钱提现";//描述信息
        $arr['spbill_create_ip'] = $this->get_client_ip();//获取服务器的ip
        //封装的关于签名的算法
        $arr['sign'] = $this->makeSign($arr,$key);//签名
        $var = $this->arrayToXml($arr);
        // dump($arr['sign'] );exit;
        $xml = $this->curl_post_ssl('https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers',$var,30);
        echo json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA),JSON_UNESCAPED_UNICODE);
        libxml_disable_entity_loader(true);
        $rdata = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)),true);
        //记录日志
        $path = APP_ROOT.'/.././cash_log/smallcharge/'.date("Y-m").'/';
        if(!is_dir($path)) mkdir($path,0777);

        file_put_contents($path."ToSmall_change".date("Y-m-d").".txt",date('Y-m-d:h:i:s').PHP_EOL.json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA),JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
        
        $return_code = trim(strtoupper($rdata['return_code']));
        $result_code = trim(strtoupper($rdata['result_code']));
        if ($return_code == 'SUCCESS' && $result_code == 'SUCCESS') {

            $this->HandleWxCashNotifyBack($uid,$rdata,$money);//修改订单信息和账户余额
            $isrr = array(
                'code'=>1,
                'msg' => '提现成功',
            );
        } else {
            // $returnmsg = $rdata['return_msg'];
            $err_code_des = $rdata['err_code_des'];
            $isrr = array(
                'status' => 0,
                'msg' => $err_code_des,
            );
        }
        
        return $isrr;
    }
    /**
     * [HandleWxCashNotifyBack 提现回调处理]
     * @param [type] $uid      [description]
     * @param [type] $order_no [description]
     */
    protected function HandleWxCashNotifyBack($uid,$data,$money){
        //修改订单信息
        $res = Db::name('home_cash_withdrawal_smallcharge')
        ->where('order_no',$data['partner_trade_no'])
        ->update([
            'drawal_money'  =>$money,
            'dr_status'=>1 
            ]);
        
        if(!$res){
            $this->WithdrawalErr($order_no,'订单已付金额添加失败');
        }
        //扣除金额
        $rs = $this->ReduceUserAccount($money,$uid,'提现');
        if(!$rs){
            $this->WithdrawalErr($order_no,'账户余额扣减失败');
        }
        if($this->drawal_charge){//如果存在手续费或者手续费不为0 
            $rst = $this->ReduceUserAccount($this->drawal_charge,$uid,'提现手续费');
            if(!$rs){
                $this->WithdrawalErr($order_no,'提现手续费扣减失败');
            }
        }
        
    }
    /**
     * [makesign ]
     * @param  [type] $data [description]
     * @param  [type] $key  [description]
     * @return [type]       [description]
     */
	protected function makesign($data,$key)
    {
        //获取微信支付秘钥
        $data = array_filter($data);
        //签名步骤一：按字典序排序参数
        ksort($data);
        $string_a = http_build_query($data);
        $string_a = urldecode($string_a);
        //签名步骤二：在string后加入KEY
        //$config=$this->config;
        $string_sign_temp = $string_a."&key=".$key;
        //签名步骤三：MD5加密
        $sign = md5($string_sign_temp);
        // 签名步骤四：所有字符转为大写
        $result = strtoupper($sign);
        // $result = strtoupper(hash_hmac("sha256",$string_sign_temp,$key));
        return $result;
    }
    protected function arraytoxml($data){
        $str='<xml>';
        foreach($data as $k=>$v) {
            $str.='<'.$k.'>'.$v.'</'.$k.'>';
        }
        $str.='</xml>';
        return $str;
    }

    protected function curl_post_ssl($url, $vars, $second = 30, $aHeader = array())
    {
        $isdir =APP_ROOT."/.././cert/";//证书位置
        $ch = curl_init();//初始化curl
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);//设置执行最长秒数
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_URL, $url);//抓取指定网页
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// 终止从服务端进行验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');//证书类型
        curl_setopt($ch, CURLOPT_SSLCERT, $isdir . 'apiclient_cert.pem');//证书位置
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');//CURLOPT_SSLKEY中规定的私钥的加密类型
        curl_setopt($ch, CURLOPT_SSLKEY, $isdir . 'apiclient_key.pem');//证书位置
        curl_setopt($ch, CURLOPT_CAINFO, 'PEM');
        curl_setopt($ch, CURLOPT_CAINFO, $isdir . 'rootca.pem');
        if (count($aHeader) >= 1) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);//设置头部
        }
        curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);//全部数据使用HTTP协议中的"POST"操作来发送
        $data = curl_exec($ch);//执行回话
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            echo "call faild, errorCode:$error\n";
            curl_close($ch);
            return false;
        }
    }

    /*  
    * @ApiInternal
    * 获取当前服务器的IP
    */
    public function get_client_ip()
    {
        if ($_SERVER['REMOTE_ADDR']) {
            $cip = $_SERVER['REMOTE_ADDR'];
        } elseif (getenv("REMOTE_ADDR")) {
            $cip = getenv("REMOTE_ADDR");
        } elseif (getenv("HTTP_CLIENT_IP")) {
            $cip = getenv("HTTP_CLIENT_IP");
        } else {
            $cip = "unknown";
        }
        return $cip;
    }



}