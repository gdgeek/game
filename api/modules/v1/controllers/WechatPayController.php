<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use yii\web\Response;
use EasyWeChat\MiniApp;
use app\modules\v1\models\Player;
use app\modules\v1\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class WechatPayController extends Controller
{

  public function behaviors()
  {

    $behaviors = parent::behaviors();

    return $behaviors;
  }


  public function actionInfo()
  {

    $wechat = Yii::$app->wechat;
    $app = $wechat->payApp();

    return $app;
  }


  /**
   * 小程序支付下单接口
   */
  public function actionWxpayOrder()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $openid = $request->post('openid'); // 从小程序端获取的openid
    $orderNo = $request->post('order_no'); // 商户订单号
    $amount = $request->post('amount'); // 金额(单位:分)
    $description = $request->post('description', '商品支付'); // 商品描述

    if (empty($openid)) {
      return ['code' => 400, 'message' => '缺少openid参数'];
    }

    try {

      $wechat = Yii::$app->wechat;
      $app = $wechat->payApp();

      // 调用统一下单接口
      $response = $app->getClient()->post('v3/pay/transactions/jsapi', [
        'json' => [
          'appid' => $app->getConfig()['app_id'],
          'mchid' => $app->getConfig()['mch_id'],
          'description' => $description,
          'out_trade_no' => $orderNo,
          'notify_url' => Yii::$app->urlManager->createAbsoluteUrl(['wechat-pay/notify']),
          'amount' => [
            'total' => (int) $amount,
            'currency' => 'CNY',
          ],
          'payer' => [
            'openid' => $openid,
          ],
        ],
      ]);

      $result = json_decode($response->getBody()->getContents(), true);

      if (!isset($result['prepay_id'])) {
        throw new \Exception('获取prepay_id失败: ' . json_encode($result));

      }

      // 生成小程序支付参数
      $prepayId = $result['prepay_id'];
      $config = $app->getJsapiConfig($prepayId);

      return [
        'code' => 0,
        'data' => $config,
      ];
    } catch (\Exception $e) {
      Yii::error('小程序支付下单失败: ' . $e->getMessage(), 'wechat-pay');
      return [
        'code' => 500,
        'message' => '支付下单失败: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * 支付通知回调
   */
  public function actionNotify()
  {
    $wechat = Yii::$app->wechat;
    $app = $wechat->payApp();

    try {
      $server = $app->getServer();
      $response = $server->handlePaid(function ($message, $fail) {
        // 1. 检查订单是否存在
        // $order = Order::findOne(['out_trade_no' => $message['out_trade_no']]);
        // if (!$order) {
        //     return $fail('订单不存在');
        // }

        // 2. 检查订单是否已支付
        // if ($order->status === 'paid') {
        //     return true;
        // }

        // 3. 检查金额是否匹配
        // if ($order->total_fee !== $message['amount']['total']) {
        //     return $fail('金额不一致');
        // }

        // 4. 标记订单为已支付
        // $order->status = 'paid';
        // $order->transaction_id = $message['transaction_id'];
        // $order->paid_at = time();
        // $order->save();

        return true; // 返回处理完成
      });

      return $response;
    } catch (\Exception $e) {
      Yii::error('支付通知处理异常: ' . $e->getMessage(), 'wechat-pay');
      throw new \yii\web\HttpException(500, '支付通知处理异常: ' . $e->getMessage());
    }
  }

  /**
   * 根据商户订单号查询微信支付订单
   */
  public function actionWxpayQueryOrderByOutTradeNo()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $outTradeNo = $request->get('out_trade_no'); // 商户订单号

    if (empty($outTradeNo)) {
      return ['code' => 400, 'message' => '缺少商户订单号参数'];
    }

    try {
      $wechat = Yii::$app->wechat;
      $app = $wechat->payApp();

      // 调试信息记录
      Yii::info('查询订单请求参数: mchid=' . $app->getConfig()['mch_id'] . ', outTradeNo=' . $outTradeNo, 'wechat-pay');

      // 查询订单API
      $response = $app->getClient()->get('v3/pay/transactions/out-trade-no/' . $outTradeNo, [
        'query' => [
          'mchid' => $app->getConfig()['mch_id'],
        ],
      ]);

      $result = $response->toArray(false);
      //$result = json_decode($response->getBody()->getContents(), true);

      // 处理查询结果
      return [
        'code' => 0,
        'message' => '查询成功',
        'data' => [
          'order_info' => $result,
          'trade_state' => $result['trade_state'] ?? '',
          'trade_state_desc' => $result['trade_state_desc'] ?? '',
        ]
      ];
    } catch (\Exception $e) {
      Yii::error('查询订单失败: ' . $e->getMessage() . ', 订单号: ' . $outTradeNo, 'wechat-pay');
      return [
        'code' => 500,
        'message' => '查询订单失败: ' . $e->getMessage(),
      ];
    }
  }
}