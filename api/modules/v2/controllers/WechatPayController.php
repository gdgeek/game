<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\User;
use yii\web\Response;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="微信支付",
 *     description="微信支付相关接口"
 * )
 */
class WechatPayController extends Controller
{

  public function behaviors()
  {

    $behaviors = parent::behaviors();
    $behaviors['authenticator'] = [
      'class' => CompositeAuth::class,
      'authMethods' => [
        JwtHttpBearerAuth::class,
      ],
      'except' => ['options'],
    ];

    return $behaviors;
  }


  /**
   * 小程序支付下单接口
   * @OA\Post(
   *     path="/v2/wechat-pay/wxpay-order",
   *     tags={"微信支付"},
   *     summary="小程序支付下单",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             @OA\Property(property="openid", type="string", description="用户 OpenID"),
   *             @OA\Property(property="out_trade_no", type="string", description="商户订单号"),
   *             @OA\Property(property="amount", type="integer", description="金额(分)"),
   *             @OA\Property(property="description", type="string", description="商品描述")
   *         )
   *     ),
   *     @OA\Response(response=200, description="下单成功")
   * )
   */
  public function actionWxpayOrder()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $openid = $request->post('openid');
    $orderNo = $request->post('out_trade_no');
    $amount = $request->post('amount');
    $description = $request->post('description', '商品支付');

    if (empty($openid)) {
      return ['code' => 400, 'message' => '缺少openid参数'];
    }


    if (empty($orderNo)) {
      return ['code' => 400, 'message' => '缺少商户订单号参数'];
    }

    if (empty($amount) || !is_numeric($amount)) {
      return ['code' => 400, 'message' => '金额参数不正确'];
    }

    if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/', $orderNo)) {
      return ['code' => 400, 'message' => '商户订单号格式不正确，要求6-32位字母数字或下划线'];
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
          'notify_url' => Yii::$app->urlManager->createAbsoluteUrl(['v1/wechat-pay/notify']),
          'amount' => [
            'total' => (int) $amount,
            'currency' => 'CNY',
          ],
          'payer' => [
            'openid' => $openid,
          ],
        ],
      ]);

      // 使用 V6 方式获取响应内容
      $result = $response->toArray(false);

      if (!isset($result['prepay_id'])) {
        throw new \Exception('获取prepay_id失败: ' . json_encode($result));
      }

      // 使用 V6 方式生成小程序支付参数
      $prepayId = $result['prepay_id'];
      $appId = $app->getConfig()['app_id'];
      $config = $app->getUtils()->buildMiniAppConfig($prepayId, $appId);
      // 如果是公众号JSAPI支付，使用下面这个
      // $config = $app->getUtils()->buildJsApiConfig($prepayId);

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
   * @OA\Post(
   *     path="/v2/wechat-pay/notify",
   *     tags={"微信支付"},
   *     summary="支付结果通知",
   *     @OA\Response(response=200, description="成功")
   * )
   * @OA\Get(
   *     path="/v2/wechat-pay/notify",
   *     tags={"微信支付"},
   *     summary="支付结果通知(GET)",
   *     @OA\Response(response=200, description="成功")
   * )
   */
  public function actionNotify()
  {
    $wechat = Yii::$app->wechat;
    $app = $wechat->payApp();

    try {
      $server = $app->getServer();
      $response = $server->handlePaid(function ($message, $fail) {
        // 实现订单查询和状态更新逻辑
        /*$order = YourOrderModel::findOne(['out_trade_no' => $message['out_trade_no']]);
        if (!$order) {
          return $fail('订单不存在');
        }
        // 更新订单状态逻辑
        $order->status = 'paid';
        $order->save();*/
        return true;
      });

      return $response;
    } catch (\Exception $e) {
      Yii::error('支付通知处理异常: ' . $e->getMessage(), 'wechat-pay');
      throw new \yii\web\HttpException(500, '支付通知处理异常: ' . $e->getMessage());
    }
  }

  /**
   * 根据商户订单号查询微信支付订单
   * @OA\Get(
   *     path="/v2/wechat-pay/wxpay-query-order-by-out-trade-no",
   *     tags={"微信支付"},
   *     summary="查询订单(按商户单号)",
   *     @OA\Parameter(
   *         name="out_trade_no",
   *         in="query",
   *         required=true,
   *         description="商户订单号",
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\Response(response=200, description="查询结果")
   * )
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

  /**
   * 申请退款
   * @OA\Post(
   *     path="/v2/wechat-pay/wxpay-refund",
   *     tags={"微信支付"},
   *     summary="申请退款",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             @OA\Property(property="out_trade_no", type="string", description="商户订单号"),
   *             @OA\Property(property="out_refund_no", type="string", description="商户退款单号"),
   *             @OA\Property(property="refund_amount", type="integer", description="退款金额(分)"),
   *             @OA\Property(property="total_amount", type="integer", description="订单总金额(分)"),
   *             @OA\Property(property="reason", type="string", description="退款原因")
   *         )
   *     ),
   *     @OA\Response(response=200, description="申请成功")
   * )
   */
  public function actionWxpayRefund()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $outTradeNo = $request->post('out_trade_no'); // 原商户订单号
    $outRefundNo = $request->post('out_refund_no'); // 商户退款单号
    $reason = $request->post('reason', ''); // 退款原因
    $refundAmount = $request->post('refund_amount'); // 退款金额
    $totalAmount = $request->post('total_amount'); // 订单总金额

    // 参数验证
    if (empty($outTradeNo)) {
      return ['code' => 400, 'message' => '缺少商户订单号参数'];
    }

    if (empty($outRefundNo)) {
      return ['code' => 400, 'message' => '缺少商户退款单号参数'];
    }

    if (empty($refundAmount) || !is_numeric($refundAmount) || $refundAmount <= 0) {
      return ['code' => 400, 'message' => '退款金额参数不正确'];
    }

    if (empty($totalAmount) || !is_numeric($totalAmount) || $totalAmount <= 0) {
      return ['code' => 400, 'message' => '订单总金额参数不正确'];
    }

    // 确保金额为整数类型（单位：分）
    $refundAmount = (int) $refundAmount;
    $totalAmount = (int) $totalAmount;

    // 验证退款金额不能大于订单金额
    if ($refundAmount > $totalAmount) {
      return ['code' => 400, 'message' => '退款金额不能大于订单总金额'];
    }

    try {
      $wechat = Yii::$app->wechat;
      $app = $wechat->payApp();

      // 调试信息
      Yii::info('申请退款请求参数: out_trade_no=' . $outTradeNo . ', out_refund_no=' . $outRefundNo
        . ', refund_amount=' . $refundAmount . ', total_amount=' . $totalAmount, 'wechat-pay');

      // 调用微信支付退款接口
      $response = $app->getClient()->post('v3/refund/domestic/refunds', [
        'json' => [
          'out_trade_no' => $outTradeNo, // 原支付交易对应的商户订单号
          'out_refund_no' => $outRefundNo, // 商户系统内部的退款单号
          'reason' => $reason, // 退款原因
          'amount' => [
            'refund' => $refundAmount, // 退款金额
            'total' => $totalAmount, // 原订单金额
            'currency' => 'CNY',
          ],
        ],
      ]);

      // 使用 V6 方式获取响应内容
      $result = $response->toArray(false);

      // 处理退款结果
      return [
        'code' => 0,
        'message' => '退款申请成功',
        'data' => [
          'refund_id' => $result['refund_id'] ?? '', // 微信支付退款单号
          'out_refund_no' => $result['out_refund_no'] ?? '', // 商户退款单号
          'status' => $result['status'] ?? '', // 退款状态
          'refund_amount' => $result['amount']['refund'] ?? 0, // 退款金额
          'success_time' => $result['success_time'] ?? '', // 退款成功时间
        ]
      ];
    } catch (\Exception $e) {
      Yii::error('申请退款失败: ' . $e->getMessage() . ', 订单号: ' . $outTradeNo, 'wechat-pay');
      return [
        'code' => 500,
        'message' => '申请退款失败: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * 查询退款
   * @OA\Get(
   *     path="/v2/wechat-pay/wxpay-query-refund",
   *     tags={"微信支付"},
   *     summary="查询退款",
   *     @OA\Parameter(
   *         name="out_refund_no",
   *         in="query",
   *         required=true,
   *         description="商户退款单号",
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\Response(response=200, description="查询结果")
   * )
   */
  public function actionWxpayQueryRefund()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $outRefundNo = $request->get('out_refund_no'); // 商户退款单号

    if (empty($outRefundNo)) {
      return ['code' => 400, 'message' => '缺少商户退款单号参数'];
    }

    try {
      $wechat = Yii::$app->wechat;
      $app = $wechat->payApp();

      // 调试信息记录
      Yii::info('查询退款请求参数: out_refund_no=' . $outRefundNo, 'wechat-pay');

      // 查询退款API
      $response = $app->getClient()->get('v3/refund/domestic/refunds/' . $outRefundNo);

      // 使用 V6 方式获取响应内容
      $result = $response->toArray(false);

      // 处理查询结果
      return [
        'code' => 0,
        'message' => '查询成功',
        'data' => [
          'refund_info' => $result,
          'refund_status' => $result['status'] ?? '',
          'refund_amount' => $result['amount']['refund'] ?? 0,
          'success_time' => $result['success_time'] ?? '',
        ]
      ];
    } catch (\Exception $e) {
      Yii::error('查询退款失败: ' . $e->getMessage() . ', 退款单号: ' . $outRefundNo, 'wechat-pay');
      return [
        'code' => 500,
        'message' => '查询退款失败: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * 退款回调通知
   * @OA\Post(
   *     path="/v2/wechat-pay/refund-notify",
   *     tags={"微信支付"},
   *     summary="退款结果通知",
   *     @OA\Response(response=200, description="成功")
   * )
   */
  public function actionRefundNotify()
  {
    $wechat = Yii::$app->wechat;
    $app = $wechat->payApp();

    try {
      $server = $app->getServer();
      $response = $server->handleRefunded(function ($message, $fail) {
        // 退款通知处理逻辑
        Yii::info('收到退款回调: ' . json_encode($message), 'wechat-pay');

        // 商户退款单号
        $outRefundNo = $message['out_refund_no'];
        // 退款状态
        $refundStatus = $message['refund_status'];

        // TODO: 在这里实现处理退款结果的业务逻辑
        // 例如更新订单状态、发送通知等

        return true; // 告知微信服务器已成功处理
      });

      return $response;
    } catch (\Exception $e) {
      Yii::error('退款通知处理异常: ' . $e->getMessage(), 'wechat-pay');
      throw new \yii\web\HttpException(500, '退款通知处理异常: ' . $e->getMessage());
    }
  }


  /**
   * 根据微信支付订单号查询订单
   * @OA\Get(
   *     path="/v2/wechat-pay/wxpay-query-order-by-transaction-id",
   *     tags={"微信支付"},
   *     summary="查询订单(按微信单号)",
   *     @OA\Parameter(
   *         name="transaction_id",
   *         in="query",
   *         required=true,
   *         description="微信支付订单号",
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\Response(response=200, description="查询结果")
   * )
   */
  public function actionWxpayQueryOrderByTransactionId()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $request = Yii::$app->request;
    $transactionId = $request->get('transaction_id'); // 微信支付订单号

    if (empty($transactionId)) {
      return ['code' => 400, 'message' => '缺少微信支付订单号参数'];
    }

    // 验证微信支付订单号格式（通常是28位数字）
    if (!preg_match('/^\d{28}$/', $transactionId)) {
      return ['code' => 400, 'message' => '微信支付订单号格式不正确，应为28位数字'];
    }

    try {
      $wechat = Yii::$app->wechat;
      $app = $wechat->payApp();

      // 调试信息记录
      Yii::info('查询订单请求参数: mchid=' . $app->getConfig()['mch_id'] . ', transaction_id=' . $transactionId, 'wechat-pay');

      // 查询订单API
      $response = $app->getClient()->get('v3/pay/transactions/id/' . $transactionId, [
        'query' => [
          'mchid' => $app->getConfig()['mch_id'],
        ],
      ]);

      // 使用 V6 方式获取响应内容
      $result = $response->toArray(false);

      // 处理查询结果
      return [
        'code' => 0,
        'message' => '查询成功',
        'data' => [
          'order_info' => $result,
          'trade_state' => $result['trade_state'] ?? '',
          'trade_state_desc' => $result['trade_state_desc'] ?? '',
          'out_trade_no' => $result['out_trade_no'] ?? '',
          'transaction_id' => $result['transaction_id'] ?? '',
          'amount' => $result['amount']['total'] ?? 0,
          'payer' => $result['payer'] ?? [],
          'success_time' => $result['success_time'] ?? '',
        ]
      ];
    } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
      // 处理客户端异常，如404表示订单不存在
      if ($e->getCode() === 404) {
        return [
          'code' => 404,
          'message' => '订单不存在',
          'data' => null
        ];
      }
      Yii::error('查询订单失败: ' . $e->getMessage() . ', 微信支付订单号: ' . $transactionId, 'wechat-pay');
      return [
        'code' => 500,
        'message' => '查询订单失败: ' . $e->getMessage(),
      ];
    } catch (\Exception $e) {
      // 处理其他异常
      Yii::error('查询订单失败: ' . $e->getMessage() . ', 微信支付订单号: ' . $transactionId, 'wechat-pay');
      return [
        'code' => 500,
        'message' => '查询订单失败: ' . $e->getMessage(),
      ];
    }
  }


}