<?php
namespace app\index\controller;
use think\Db;
/**
 * 微信到银行
 */
class Wxcashtobank extends Home{
  private $appid;      //公众号APPID
  private $appsecret;    //公众号appsecret
  private $mchid;      //商户号
  private $key;       //支付密钥
  private $sslcert;     //证书保存的绝对路径
  private $sslkey;     //证书保存的绝对路径
  private $order_no;
  protected $drawal_charge;
  public function __construct()
  {
    parent::__construct();
    $this->appid = config('appId');
    $this->appsecret = config('appSecret');
    $this->mchid = config('mch_id');
    $this->key = config('api_key');
    $this->sslcert = APP_ROOT.'/.././cert/apiclient_cert.pem';
    $this->sslkey =  APP_ROOT.'/.././cert/apiclient_key.pem';
  }
  public function index(){
  	$uid =1 ;
    
    $money = 0.01;
    $bank_no = '6222022314010043035';
    $card_holder = '王昔东';
    $bank_code = '1005';
    $desc = '企业付款到银行卡测试';
    $this->drawal_charge = 0.01;
    
   $payment_no = $this->HandleTransferAccounts($uid,$money,$bank_code,$bank_no,$card_holder,$desc);
    var_dump($payment_no);
  }
  /**
   * [HandleTransferAccounts 生成订单和银行转账]
   * @param [type] $uid         [description]
   * @param [type] $money       [description]
   * @param [type] $bank_code   [description]
   * @param [type] $bank_no     [description]
   * @param [type] $card_holder [description]
   * @param [type] $desc        [description]
   */
  protected function  HandleTransferAccounts($uid,$money,$bank_code,$bank_no,$card_holder,$desc){
    if(!$this->JudgeCashCharge($money,$uid)){
            return ['code'=>1,'msg'=>'账号余额不足','error'=>'error'];
       }
      $this->drawal_charge = $this->getCashCharge($money);//手续费
      $this->order_no= generateChargeOrderNo("BK");//订单号码
      $order_id = $this->generateCashToBankList($uid, $this->order_no,$money,$bank_code,$bank_no,$card_holder);
      if(!$order_id){
          return ['code'=>0,'msg'=>'订单生成失败','error'=>'error'];
      }
      $res =$this->payForBank($uid,$this->order_no,$money,$bank_no,$card_holder,$bank_code,$desc);
      if($res){
        return ['code'=>1,'msg'=>'提现成功！','error'=>'OK'];
      }else{
        return ['code'=>0,'msg'=>'提现失败！','error'=>'OK'];
      }
  }
  
  /*
  * 企业付款到银行卡接口
   * @params string $out_trade_no : 商户订单号
   * @params int $amount : 付款金额，单位分
   * @params string $enc_bank_no : 收款方银行卡号
   * @params string $enc_true_name : 收款方用户名
   * @params string $bank_name : 收款方开户行,根据银行名称获取银行编号bank_code
   * @params string $desc : 付款备注
   * return string $payment_no ：支付成功的订单号
  */
  protected function payForBank($uid,$out_trade_no,$amount,$enc_bank_no,$enc_true_name,$bank_code,$desc='企业付款到银行卡')
  {
    $data['amount'] = $amount*100;
    //$data['bank_code'] = $this->getBankCode($bank_name);
    $data['bank_code'] = $bank_code;
    $data['desc'] = $desc;
    $data['enc_bank_no'] = $this->publicEncrypt($enc_bank_no);
    $data['enc_true_name'] = $this->publicEncrypt($enc_true_name);
    $data['mch_id'] = $this->mchid;
    $data['nonce_str'] = $this->random(12);
    $data['partner_trade_no'] = $out_trade_no;
    $sign = $this->getParam($data);
 
    $dataXML="<xml>
    <amount>".$data['amount']."</amount>
    <bank_code>".$data['bank_code']."</bank_code>
    <desc>".$data['desc']."</desc>
    <enc_bank_no>".$data['enc_bank_no']."</enc_bank_no>
    <enc_true_name>".$data['enc_true_name']."</enc_true_name>
    <mch_id>".$data['mch_id']."</mch_id>
    <nonce_str>".$data['nonce_str']."</nonce_str>
    <partner_trade_no>".$data['partner_trade_no']."</partner_trade_no>
    <sign>".$sign."</sign>
    </xml>";
 
    $url = 'https://api.mch.weixin.qq.com/mmpaysptrans/pay_bank';

    $ret = $this->httpsPost($url,$dataXML,true);
    
    if($ret['return_code'] == 'SUCCESS' && $ret['result_code'] == 'SUCCESS' && $ret['err_code'] == 'SUCCESS'){
        $this->HandleWxCashNotifyBack($uid,$this->order_no,$amount);
        $this->nomalLog('微信付款到银行卡成功，订单号码：'.$out_trade_no,$ret);
      return true;
    }else{
      $this->errorLog('微信付款到银行卡失败，订单号码：'.$out_trade_no,$ret);
      return false;
    }
  }
  /**
     * [HandleWxCashNotifyBack 提现回调处理]
     * @param [type] $uid      [description]
     * @param [type] $order_no [description]
     */
    protected function HandleWxCashNotifyBack($uid,$order_no,$money){
        //修改订单信息
        $res = Db::name('home_cash_withdrawal_tobank')
        ->where('order_no',$order_no)
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
        $drawal_charge = $this->drawal_charge ;
        if($drawal_charge!=0){//如果存在手续费或者手续费不为0 
            $rst = $this->ReduceUserAccount($this->drawal_charge,$uid,'提现手续费');
            if(!$rst){
                $this->WithdrawalErr($order_no,'提现手续费扣减失败');
            }
        }
        
    }
  // array(11) { ["return_code"]=> string(7) "SUCCESS" ["return_msg"]=> string(12) "支付成功" ["result_code"]=> string(7) "SUCCESS" ["err_code"]=> string(7) "SUCCESS" ["err_code_des"]=> string(21) "微信侧受理成功" ["nonce_str"]=> string(12) "NknnZWOJrDQy" ["mch_id"]=> string(10) "1480689022" ["partner_trade_no"]=> string(18) "DR1565249971844118" ["amount"]=> string(1) "1" ["payment_no"]=> string(35) "10000285175642019080800000128402374" ["cmms_amt"]=> string(3) "100" } string(35) "10000285175642019080800000128402374"
  protected function check(){
    $data['mch_id']=$this->mchid;
    $data['nonce_str']=$this->random(12);
    $data['partner_trade_no']="DR1565249971844118";
    $sign = $this->getParam($data);
    
    $dataXML="<xml>
      <mch_id>".$data['mch_id']."</mch_id>
      <nonce_str>".$data['nonce_str']."</nonce_str>
      <partner_trade_no>".$data['partner_trade_no']."</partner_trade_no>
      <sign>".$sign."</sign>
      </xml>";
    $url = 'https://api.mch.weixin.qq.com/mmpaysptrans/query_bank';

    $ret = $this->httpsPost($url,$dataXML,true);
    return $ret ; 
  }
  /*
   * 查询付款到银行卡状态
   * @params string $out_trade_no : 商户订单号
   * return array $ret：查询状态
   * */
  protected function queryBank($out_trade_no)
  {
    $data['mch_id'] = $this->mchid;
    $data['nonce_str'] = $this->random(12);
    $data['partner_trade_no'] = $out_trade_no;
    $sign = $this->getParam($data);
    $dataXML="<xml>
    <mch_id>".$data['mch_id']."</mch_id>
    <nonce_str>".$data['nonce_str']."</nonce_str>
    <partner_trade_no>".$data['partner_trade_no']."</partner_trade_no>
    <sign>".$sign."</sign>
    </xml>";
    $url = 'https://api.mch.weixin.qq.com/mmpaysptrans/query_bank';
    $ret = $this->httpsPost($url,$dataXML,true);

    if($ret['return_code'] == 'SUCCESS' && $ret['result_code'] == 'SUCCESS' && $ret['err_code'] == 'SUCCESS'){
      return $ret;
    }else{
      $this->errorLog('查询微信付款到银行卡失败，appid：'.$this->appid.'，订单号：'.$out_trade_no,$ret);
      return false;
    }
  }
  
  /*
   * 银行编号列表，详情参考：https://pay.weixin.qq.com/wiki/doc/api/tools/mch_pay.php?chapter=24_4
   * @params string $bank_name : 银行名称，4个汉字
   * return int $bank_code : 银行编码
   * */
  private function getBankCode($bank_name)
  {
    $bank_code = 0;
    switch ($bank_name){
      case '工商银行':  $bank_code = 1002; break;
      case '农业银行':  $bank_code = 1005; break;
      case '中国银行':  $bank_code = 1026; break;
      case '建设银行':  $bank_code = 1003; break;
      case '招商银行':  $bank_code = 1001; break;
      case '邮储银行':  $bank_code = 1066; break;
      case '交通银行':  $bank_code = 1020; break;
      case '浦发银行':  $bank_code = 1004; break;
      case '民生银行':  $bank_code = 1006; break;
      case '兴业银行':  $bank_code = 1009; break;
      case '平安银行':  $bank_code = 1010; break;
      case '中信银行':  $bank_code = 1021; break;
      case '华夏银行':  $bank_code = 1025; break;
      case '广发银行':  $bank_code = 1027; break;
      case '光大银行':  $bank_code = 1022; break;
      case '北京银行':  $bank_code = 1032; break;
      case '宁波银行':  $bank_code = 1056; break;
    }
    return $bank_code;
  }
  /**
   * 公钥加密，银行卡号和姓名需要RSA算法加密
   * @param string $data  需要加密的字符串，银行卡/姓名
   * @return null|string  加密后的字符串
   */
  private function publicEncrypt($data)
  {
    // 进行加密
    $pubkey = openssl_pkey_get_public(file_get_contents(APP_ROOT.'/.././cert/pkcs8.pem'));
    // $pubkey = openssl_pkey_get_public(file_get_contents(ROOT_PATH.'data/cert/public_pkcs8.pem'));
    $encrypt_data = '';
    $encrypted = '';
    $r = openssl_public_encrypt($data,$encrypt_data,$pubkey,OPENSSL_PKCS1_OAEP_PADDING);
    if($r){//加密成功，返回base64编码的字符串
      return base64_encode($encrypted.$encrypt_data);
    }else{
      return false;
    }
  }
  /*
   * 获取公钥,格式为PKCS#1 转PKCS#8
   * openssl rsa -RSAPublicKey_in -in  <filename> -out <out_put_filename>
   * */
  private function get_pub_key()
  {
    $rsafile = APP_ROOT.'/.././cert/'.$this->appid.'_publicrsa.pem';
    if(!is_file($rsafile)){
      $data['mch_id'] = $this->mchid;
      $data['nonce_str'] = $this->random(12);
      $sign = $this->getParam($data);
      $dataXML="<xml>
      <mch_id>".$data['mch_id']."</mch_id>
      <nonce_str>".$data['nonce_str']."</nonce_str>
      <sign>".$sign."</sign>
      </xml>";
      $url = 'https://fraud.mch.weixin.qq.com/risk/getpublickey';
      $ret = $this->httpsPost($url,$dataXML,true);
      if($ret['return_code'] == 'SUCCESS' && isset($ret['pub_key'])){
        file_put_contents($rsafile,$ret['pub_key']);
        return $ret['pub_key'];
      }else{
        return null;
      }
    }else{
      return file_get_contents($rsafile);
    }
  }
  /*
  * 发起POST网络请求
  * @params string $url : 请求的url链接地址
  * @params string $data : 数据包
  * @params bool $ssl : 是否加载证书
  * return array $result : 返回的数据结果 
  */
  private function httpsPost($url,$data,$ssl = false)
  {
    $ch = curl_init ();
    curl_setopt ( $ch, CURLOPT_URL, $url );
    curl_setopt ( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
    curl_setopt ( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
    curl_setopt ( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
    if($ssl) {
      curl_setopt ( $ch,CURLOPT_SSLCERT,$this->sslcert);
      curl_setopt ( $ch,CURLOPT_SSLKEY,$this->sslkey);
    }
    curl_setopt ( $ch, CURLOPT_FOLLOWLOCATION, 1 );
    curl_setopt ( $ch, CURLOPT_AUTOREFERER, 1 );
    curl_setopt ( $ch, CURLOPT_POSTFIELDS, $data );
    curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, true );
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
      return 'Errno: '.curl_error($ch);
    }
    curl_close($ch);
    return $this->xmlToArray($result);
  }
  //对参数排序，生成MD5加密签名
  private function getParam($paramArray, $isencode=false)
  {
    $paramStr = '';
    ksort($paramArray);
    $i = 0;
    foreach ($paramArray as $key => $value)
    {
      if ($key == 'Signature'){
        continue;
      }
      if ($i == 0){
        $paramStr .= '';
      }else{
        $paramStr .= '&';
      }
      $paramStr .= $key . '=' . ($isencode?urlencode($value):$value);
      ++$i;
    }
    $stringSignTemp=$paramStr."&key=".$this->key;
    $sign=strtoupper(md5($stringSignTemp));
    return $sign;
  }
  /*
  * 将xml转换成数组
  * @params xml $xml : xml数据
  * return array $data : 返回数组
  */
  private function xmlToArray($xml)
  {
    //禁止引用外部xml实体
    libxml_disable_entity_loader(true);
    $xmlstring = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    $val = json_decode(json_encode($xmlstring),true);
    return $val;
  }
  /*
  *  随机字符串
   * @param int $length 长度
   * @param string $type 类型
   * @param int $convert 转换大小写 1大写 0小写
   * @return string
  */
  private function random($length=10,$type='letter',$convert=false)
  {
    $config = array(
      'number'=>'1234567890',
      'letter'=>'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
      'string'=>'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789',
      'all'=>'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'
    );
   
    if(!isset($config[$type])) $type = 'letter';
    $string = $config[$type];
   
    $code = '';
    $strlen = strlen($string) -1;
    for($i = 0; $i < $length; $i++){
      $code .= $string{mt_rand(0, $strlen)};
    }
    if(!empty($convert)){
      $code = ($convert > 0)? strtoupper($code) : strtolower($code);
    }
    return $code;
  }
  /*
  * 日志记录
  * @params string $msg : 文字描述
  * @params array $ret : 调用接口返回的数组
  */
  private function errorLog($msg,$ret)
  {
    $path = $path = APP_ROOT.'/.././cash_log/cashtobank/cash_err/'.date("Y-m").'/';
    if(!is_dir($path)) mkdir($path,0777);
    file_put_contents($path.'cashtobank'.date('Y-m').'.txt', "[" . date('Y-m-d H:i:s') . "] ".$msg."," .json_encode($ret,JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
  }
  /**
   * [nomalLog description]
   * @return [type] [description]
   */
  private function nomalLog($msg,$ret){
    $path = $path = APP_ROOT.'/.././cash_log/cashtobank/cash_nomal/'.date("Y-m").'/';
    if(!is_dir($path)) mkdir($path,0777);
    file_put_contents($path.'cashtobank'.date('Y-m').'.txt', "[" . date('Y-m-d H:i:s') . "] ".$msg."," .json_encode($ret,JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
  }
  
}