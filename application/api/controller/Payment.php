<?php
/**
 * Created by PhpStorm.
 * User: wzx
 * Date: 2017/12/24
 * Time: 20:35
 */

namespace app\api\controller;


use think\Config;

class Payment extends Base
{
    /**
     * 会员立即购买获取数据接口
     */
    public function buy_now(){
        $uid = input('uid');
        if (!$uid) {
            return json(array('status'=>0,'err'=>'系统错误.'));
        }
        //单件商品结算
        //地址管理
        $address = db("address");
        $city = db("china_city");
        $add = $address->where('uid='.$uid)->select();
        $citys = $city->where('tid=0')->field('id,name')->select();
        $shopping = db('shopping_char');
        $product = db("product");
        //运费
        $post = db('post');

        //立即购买数量
        $num = input('num');
        if (!$num) {
            $num=1;
        }

        //购物车id
        $cart_id = input('cart_id');
        //检测购物车是否有对应数据
        $check_cart = $shopping->where('id='.$cart_id.' AND num>='.$num)->value('pid');
        if (!$check_cart) {
            return json(array('status'=>0,'err'=>'购物车信息错误.'));

        }
        //判断基本库存
        $pro_num = $product->where('id='.$check_cart)->value('num');
        if ($num>intval($pro_num)) {
            return json(array('status'=>0,'err'=>'库存不足.'));

        }

        $qz = Config::get('DB_PREFIX');//前缀

        $pro=$shopping->where(''.$qz.'shopping_char.uid='.intval($uid).' and '.$qz.'shopping_char.id='.intval($cart_id))->join('LEFT JOIN __PRODUCT__ ON __PRODUCT__.id=__SHOPPING_CHAR__.pid')->join('LEFT JOIN __SHANGCHANG__ ON __SHANGCHANG__.id=__SHOPPING_CHAR__.shop_id')->field(''.$qz.'product.num as pnum,'.$qz.'shopping_char.id,'.$qz.'shopping_char.pid,'.$qz.'shangchang.name as sname,'.$qz.'product.name,'.$qz.'product.shop_id,'.$qz.'product.photo_x,'.$qz.'product.price_yh,'.$qz.'shopping_char.num,'.$qz.'shopping_char.buff,'.$qz.'shopping_char.price,'.$qz.'shangchang.alipay,'.$qz.'shangchang.alipay_pid,'.$qz.'shangchang.alipay_key')->find();
        //获取运费
        $yunfei = $post->where('pid='.intval($pro['shop_id']))->find();

        if($pro['buff']!=''){
            $pro['zprice']=$pro['price']*$num;
        }else{
            $pro['price']=$pro['price_yh'];
            $pro['zprice']=$pro['price']*$num;
        }

        //如果需要运费
        if ($yunfei) {
            if ($yunfei['price_max']>0 && $yunfei['price_max']<=$pro['zprice']) {
                $yunfei['price']=0;
            }
        }

        $buff_text='';
        if($pro['buff']){
            //获取属性名称
            $buff = explode(',',$pro['buff']);
            if(is_array($buff)){
                foreach($buff as $keys => $val){
                    $ggid=db("guige")->where('id='.intval($val))->value('name');
                    //$buff_text .= select('name','aaa_cpy_category','id='.$val['id']).':'.select('name','aaa_cpy_category','id='.$val['val']).' ';
                    $buff_text .=' '.$ggid.' ';
                }
            }
        }
        $pro['buff']=$buff_text;
        $pro['photo_x']='http://'.$_SERVER['SERVER_NAME'].__UPLOAD__.'/'.$pro['photo_x'];

        return json(array('status'=>1,'citys'=>$citys,'yun'=>$yunfei,'adds'=>$add,'pro'=>$pro,'num'=>$num,'buff'=>$buff_text));

        //$this->assign('citys',$citys);
    }

    /**
     * 会员立即购买下单接口
     */
    public function pay_now(){
        $product = db("product");
        //运费
        $post = db('post');
        $order = db("order");
        $order_pro = db("order_product");

        $uid = input('uid');
        if (!$uid) {
            return json(array('status'=>0,'err'=>'登录状态异常.'));

        }

        //下单
        try {
            $data = array();
            $data['shop_id']= input('sid');
            $data['uid']=intval($uid);
            $data['addtime']=time();
            $data['del']=0;
            $data['type']=trim(input('paytype'));
            //订单状态 10未付款20代发货30确认收货（待收货）40交易关闭50交易完成
            $data['status']=10;//未付款

            //dump($_POST);exit;
            $_POST['yunfei'] ? $yunPrice = $post->where('id='.intval($_POST['yunfei']))->find() : NULL;
            //dump($yunPrice);exit;
            if(!empty($yunPrice)){
                $data['post'] = $yunPrice['id'];
                $data['price'] = input('price') + $yunPrice['price'];
            }else{
                $data['post'] = 0;
                $data['price'] = input('price');
            }

            $adds_id = intval(input('aid'));
            if (!$adds_id) {
                return json(array('status'=>0,'err'=>'请选择收货地址.'.__LINE__));

            }

            $adds_info = db('address')->where('id='.intval($adds_id))->find();
            $data['receiver']=$adds_info['name'];
            $data['tel']=$adds_info['tel'];
            $data['address_xq']=$adds_info['address_xq'];
            $data['code']=$adds_info['code'];
            $data['product_num']=intval(input('num'));
            $data['remark']=input('remark');
            /*******解决屠涂同一订单重复支付问题 lisa**********/
            $data['order_sn']=$this->build_order_no();//生成唯一订单号

            if (!$data['product_num'] || !$data['price']) {
                throw new \Exception("System Error !");
            }

            /**************************************************/
            //dump($data);exit;
            $result = $order->insert($data);
            if($result){
                $date =array();
                $date['pid']=intval(input('pid'));//商品id
                $date['order_id']=$result;//订单id
                $date['name']=$product->where('id='.intval($date['pid']))->value('name');//商品名字
                $date['price']=$product->where('id='.intval($date['pid']))->value('price_yh');
                $date['pro_buff']=input('buff');
                $date['photo_x']=$product->where('id='.intval($date['pid']))->value('photo_x');
                $date['pro_buff']=input('buff');
                $date['addtime']=time();
                $date['num']=intval(input('num'));
                //$date['pro_guige']=$_REQUEST['guige'];
                $res = $order_pro->insert($date);
                if(!$res){
                    throw new \Exception("下单 失败！".__LINE__);
                }

                //检查产品是否存在，并修改库存
                $check_pro = $product->where('id='.intval($date['pid']).' AND del=0 AND is_down=0')->field('num,shiyong')->find();
                if (!$check_pro) {
                    throw new \Exception("商品不存在或已下架！");
                }
                $up = array();
                $up['num'] = intval($check_pro['num'])-intval($date['num']);
                $up['shiyong'] = intval($check_pro['shiyong'])+intval($date['num']);
                $product->where('id='.intval($date['pid']))->update($up);

            }else{
                throw new \Exception("下单 失败！");
            }
        } catch (Exception $e) {
            return json(array('status'=>0,'err'=>$e->getMessage()));

        }
        //把需要的数据返回
        $arr = array();
        $arr['order_id'] = $result;
        $arr['order_sn'] = $data['order_sn'];
        $arr['pay_type'] = input('paytype');
        return json(array('status'=>1,'arr'=>$arr));

    }

    /**
     * 购物车结算 获取数据
     */
    public function buy_cart(){
        $uid = input('uid');
        if (!$uid) {
            return json(array('status'=>0,'err'=>'登录状态异常.'));

        }

        $address=db("address");
        //运费
        $post = db('post');
        $qz= Config::get('DB_PREFIX');
        $add=$address->where('uid='.$uid)->order('is_default desc,id desc')->limit(1)->find();
        $product=db("product");
        $shopping=db('shopping_char');
        $cart_id = trim(input('cart_id'),',');
        $id=explode(',', $cart_id);
        if (!$cart_id) {
            return json(array('status'=>0,'err'=>'网络异常.'.__LINE__));
        }
        //计算总价
        $price = 0;
        foreach($id as $k => $v){
            //检测购物车是否有对应数据
            $cartInfo = $shopping->where('id='.$v)->find();
            if (!$cartInfo) {
                return json(array('status'=>0,'err'=>'非法操作.'.__LINE__));
            }
            $pro[$k] = $product->where('id='.$cartInfo['pid'])->find();
            $pro[$k]['photo_x'] = __DATAURL__.$pro[$k]['photo_x'];
            $pro[$k]['num'] = $cartInfo['num'];
            $pro[$k]['price'] = $cartInfo['price'] * $cartInfo['num'];
            $price += $pro[$k]['price'];
        }
        //获取运费
        //如果需要运费
        if ($add) {
            $addemt = 1;
        }else{
            $addemt = 0;
        }

        return json(array('status'=>1,'price'=>floatval($price),'pro'=>$pro,'adds'=>$add,'addemt'=>$addemt));

    }

    /**
     * 购物车结算 下订单
     */
    public function payment(){
        $product=db("product");
        //运费
        $post = db('post');
        $order=db("order");
        $order_pro=db("order_product");
        $shopping=db('shopping_char');

        $uid = input('uid');
        if (!$uid) {
            return json(array('status'=>0,'err'=>'登录状态异常.'));

        }

        $cart_id = trim(input('cart_id'),',');
        if (!$cart_id) {
            return json(array('status'=>0,'err'=>'数据异常.'));

        }

        //生成订单
        $num = NULL;
        $price = NULL;
        try {
            $qz= Config::get('DB_PREFIX');//前缀
            $cart_id = explode(',', $cart_id);
            $shop=array();
            foreach($cart_id as $k => $v){
                //检测购物车是否有对应数据
                $cartInfo = $shopping->where('id='.$v)->find();
                if (!$cartInfo) {
                    return json(array('status'=>0,'err'=>'非法操作.'.__LINE__));
                }
                $pro[$k] = $product->where('id='.$cartInfo['pid'])->find();
                $pro[$k]['photo_x'] = __DATAURL__.$pro[$k]['photo_x'];
                $pro[$k]['num'] = $cartInfo['num'];
                $pro[$k]['price'] = $cartInfo['price'] * $cartInfo['num'];
                $price += $pro[$k]['price'];
            }

            $yunPrice = array();
            if ($_POST['yunfei']) {
                $yunPrice = $post->where('id='.input('yunfei'))->find();
            }

            $data['shop_id']=$shop[$k]['shop_id'];
            $data['uid']=intval($uid);

            if(!empty($yunPrice)){
                $data['post'] = $yunPrice['id'];
                $data['price']=floatval($price)+$yunPrice['price'];
            }else{
                $data['post'] = 0;
                $data['price']=floatval($price);
            }

            $data['amount'] = $data['price'];
            $vid = input('vid');
            if ($vid) {
                $vouinfo = db('user_voucher')->where('status=1 AND uid='.intval($uid).' AND vid='.intval($vid))->find();
                $chk = db('order')->where('uid='.intval($uid).' AND vid='.intval($vid).' AND status>0')->find();
                if (!$vouinfo || $chk) {
                    //throw new \Exception("此优惠券不可用，请选择其他.".__LINE__);
                    return json(array('status'=>0,'err'=>'此优惠券不可用，请选择其他.'));

                }
                if ($vouinfo['end_time']<time()) {
                    //throw new \Exception("优惠券已过期了.".__LINE__);
                    return json(array('status'=>0,'err'=>"优惠券已过期了.".__LINE__));

                }
                if ($vouinfo['start_time']>time()) {
                    //throw new \Exception("优惠券还未生效.".__LINE__);
                    return json(array('status'=>0,'err'=>"优惠券还未生效.".__LINE__));

                }
                $data['vid'] = intval($vid);
                $data['amount'] = floatval($data['price'])-floatval($vouinfo['amount']);
            }

            $data['addtime'] = time();
            $data['del']     = 0;
            $data['type']    = input('type');
            $data['status']  = 10;

            $adds_id = input('aid');
            if (!$adds_id) {
                throw new \Exception("请选择收货地址.".__LINE__);
            }
            $adds_info = db('address')->where('id='.$adds_id)->find();
            $data['receiver'] = $adds_info['name'];
            $data['tel'] = $adds_info['tel'];
            $data['address_xq'] = $adds_info['address_xq'];
            $data['code'] = $adds_info['code'];
            $data['product_num'] = $num;
            $data['remark'] = input('remark');
            $data['order_sn'] = $this->build_order_no();//生成唯一订单号

            $result = $order->insert($data);
            if($result){
                foreach($cart_id as $k => $v){
                    $cartInfo = $shopping->where('id='.$v)->find();

                    $shops[$k]= $product->where('id='.$cartInfo['pid'])->find();

                    $date = [
                        'pid' => $shop[$k]['pid'],
                        'name' => $shops[$k]['name'],
                        'order_id' => $result,
                        'price' => $shops[$k]['price'],
                        'photo_x' => $shops[$k]['photo_x'],
                        'addtime' => time(),
                        'num' => $cartInfo[$k]['num'],
                        'pro_guige' => ''
                    ];
                    $res = $order_pro->update($date);
                    if (!$res) {
                        throw new \Exception("下单 失败！".__LINE__);
                    }
                    //检查产品是否存在，并修改库存
                    $check_pro = $product->where('id='.$date['pid'].' AND del=0 AND is_down=0')->field('num,shiyong')->find();
                    $up = [
                        'num' => $check_pro['num'] - $data['num'],
                        'shiyong' => $check_pro['shiyong'] + $data['num']
                    ];
                    $product->where('id='.$date['pid'])->update($up);
                    //删除购物车数据
                    $shopping->where('uid='.$uid.' AND id='.$v)->delete();

                }
            }else{
                throw new \Exception("下单 失败！");
            }
        } catch (Exception $e) {
            return json(array('status'=>0,'err'=>$e->getMessage()));
        }

        //把需要的数据返回
        $arr = [
            'order_id' => $result,
            'order_sn' => $data['order_sn'],
            'pay_type' => input('type')
        ];
        return json(array('status'=>1,'arr'=>$arr));

    }

    /**
     * 获取可用优惠券
     * @param $uid
     * @param $pid
     * @param $cart_id
     * @return array
     */
    public function get_voucher($uid,$pid,$cart_id){
        $qz=Config::get('DB_PREFIX');
        //计算总价
        $prices = 0;
        foreach($cart_id as $ks => $vs){
            $pros=db('shopping_char')->where(''.$qz.'shopping_char.uid='.intval($uid).' AND '.$qz.'shopping_char.id='.$vs)->join('LEFT JOIN __PRODUCT__ ON __PRODUCT__.id=__SHOPPING_CHAR__.pid')->join('LEFT JOIN __SHANGCHANG__ ON __SHANGCHANG__.id=__SHOPPING_CHAR__.shop_id')->field(''.$qz.'shopping_char.num,'.$qz.'shopping_char.price,'.$qz.'shopping_char.type')->find();
            $zprice=$pros['price']*$pros['num'];
            $prices+=$zprice;
        }

        $condition = array();
        $condition['uid'] = intval($uid);
        $condition['status'] = array('eq',1);
        $condition['start_time'] = array('lt',time());
        $condition['end_time'] = array('gt',time());
        $condition['full_money'] = array('elt',floatval($prices));

        $vou = db('user_voucher')->where($condition)->order('addtime desc')->select();
        $vouarr = array();
        foreach ($vou as $k => $v) {
            $chk_order = db('order')->where('uid='.intval($uid).' AND vid='.intval($v['vid']).' AND status>0')->find();
            $vou_info = db('voucher')->where('id='.intval($v['vid']))->find();
            $proid = explode(',', trim($vou_info['proid'],','));
            if (($vou_info['proid']=='all' || $vou_info['proid']=='' || in_array($pid, $proid)) && !$chk_order) {
                $arr = array();
                $arr['vid'] = intval($v['vid']);
                $arr['full_money'] = floatval($v['full_money']);
                $arr['amount'] = floatval($v['amount']);
                $vouarr[] = $arr;
            }
        }

        return $vouarr;
    }

    /**针对涂屠生成唯一订单号
     *@return int 返回16位的唯一订单号
     */
    public function build_order_no(){
        return date('Ymd').substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
    }
}