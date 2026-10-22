<?php

namespace app\components;

use app\modules\v2\models\File;
use app\modules\v2\models\User;
use yii\db\ActiveQuery;
use yii\db\IntegrityException;
use yii\db\Query;
use yii\web\HttpException;

/** File ownership and payment checks shared by file reads and WeChat orders. */
class MiniProgramFileService
{
    public function ownedQuery(User $user): ActiveQuery
    {
        $owner = ['user_id' => (int) $user->id];
        if (is_string($user->unionid) && $user->unionid !== '') {
            $owner = ['or', $owner, ['and', ['user_id' => null], ['unionid' => $user->unionid]]];
        }
        return File::find()->where($owner);
    }

    public function findOwned($id, User $user): File
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new HttpException(400, '文件编号无效');
        }
        $file = $this->ownedQuery($user)->andWhere(['id' => $id])->one();
        if (!$file) {
            throw new HttpException(404, '文件不存在或不属于当前账号');
        }
        return $file;
    }

    public static function priceInCents($value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $price = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        return $price === false ? null : $price;
    }

    /** Yii reads setup.money's FLOAT column as a PHP float, including whole cents. */
    public static function recordingPriceInCents($value): ?int
    {
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && $value <= 2147483647 && floor($value) === $value
                ? (int) $value : null;
        }
        return self::priceInCents($value);
    }

    public function prepareOrder(File $file, User $user, $requestedAmount = null, $requestedOpenid = null): array
    {
        $price = self::priceInCents($file->price);
        if ($price === null) {
            throw new HttpException(422, '该历史文件尚未确认收费金额，请联系管理员');
        }
        if ($price === 0 || $file->unlocked) {
            throw new HttpException(409, '该文件无需重复支付，请刷新文件状态');
        }
        if ($requestedAmount !== null && self::priceInCents($requestedAmount) !== $price) {
            throw new HttpException(400, '文件金额已更新，请刷新后重试');
        }
        if (!is_string($user->openid) || $user->openid === '' ||
            ($requestedOpenid !== null && $requestedOpenid !== $user->openid)) {
            throw new HttpException(400, '付款账号与当前登录账号不一致');
        }
        return [
            'amount' => $price, 'openid' => $user->openid,
            'attach' => json_encode(['v' => 1, 'file_id' => (int) $file->id, 'user_id' => (int) $user->id, 'amount' => $price], JSON_THROW_ON_ERROR),
        ];
    }

    /** Reserve the immutable order binding before making any WeChat request. */
    public function reserveOrder(string $orderNo, File $file, User $user, $requestedAmount = null, $requestedOpenid = null): array
    {
        $this->validateOrderNumber($orderNo);
        $prepared = $this->prepareOrder($file, $user, $requestedAmount, $requestedOpenid);
        // Recheck the owner here as well so callers cannot reserve another user's file.
        $this->findOwned($file->id, $user);
        try {
            File::getDb()->createCommand()->insert('{{%file_payment_order}}', [
                'out_trade_no' => $orderNo,
                'user_id' => (int) $user->id,
                'file_id' => (int) $file->id,
                'amount' => $prepared['amount'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ])->execute();
        } catch (IntegrityException $exception) {
            // A unique primary key arbitrates concurrent reservations; never overwrite it.
            $existing = $this->findPaymentOrder($orderNo);
            if ($existing === null) {
                throw $exception;
            }
            if ((int) $existing['user_id'] !== (int) $user->id ||
                (int) $existing['file_id'] !== (int) $file->id ||
                (int) $existing['amount'] !== $prepared['amount']) {
                throw new HttpException(409, '订单编号已绑定其他文件、账号或金额');
            }
        }
        return $prepared;
    }

    public function assertOrderOwner(string $orderNo, User $user): array
    {
        $order = $this->findPaymentOrder($orderNo);
        if ($order === null || (int) $order['user_id'] !== (int) $user->id) {
            throw new HttpException(403, '订单不存在或不属于当前账号');
        }
        return $order;
    }

    /** Callbacks resolve the account from our reservation, never the callback attachment. */
    public function findOrderUser(string $orderNo): User
    {
        $order = $this->findPaymentOrder($orderNo);
        $user = $order === null ? null : User::findOne(['id' => $order['user_id']]);
        if (!$user) {
            throw new HttpException(403, '订单账号不存在');
        }
        return $user;
    }

    private function findPaymentOrder(string $orderNo): ?array
    {
        $this->validateOrderNumber($orderNo);
        $order = (new Query())->from('{{%file_payment_order}}')->where(['out_trade_no' => $orderNo])->one(File::getDb());
        return $order === false ? null : $order;
    }

    private function validateOrderNumber(string $orderNo): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/', $orderNo)) {
            throw new HttpException(400, '订单编号无效');
        }
    }

    /** Only call with a transaction returned by WeChat's authenticated API. */
    public function confirmPayment(array $order, User $user, string $appId, string $merchantId): bool
    {
        $orderNo = $order['out_trade_no'] ?? null;
        if (!is_string($orderNo)) {
            throw new HttpException(400, '订单编号无效');
        }
        $binding = $this->assertOrderOwner($orderNo, $user);
        if ($appId === '' || $merchantId === '' ||
            ($order['appid'] ?? null) !== $appId || ($order['mchid'] ?? null) !== $merchantId) {
            throw new HttpException(403, '订单收款方校验失败');
        }
        // WeChat may omit attach, payer and amount until payment succeeds.
        if (in_array($order['trade_state'] ?? null, ['NOTPAY', 'CLOSED', 'REVOKED', 'USERPAYING', 'PAYERROR', 'REFUND'], true)) {
            return false;
        }
        if (($order['trade_state'] ?? null) !== 'SUCCESS') {
            throw new HttpException(403, '订单支付状态无效');
        }
        $attachment = json_decode($order['attach'] ?? '', true);
        if (!is_array($attachment) || ($attachment['v'] ?? null) !== 1 ||
            ($attachment['user_id'] ?? null) !== (int) $user->id ||
            ($attachment['file_id'] ?? null) !== (int) $binding['file_id'] ||
            ($attachment['amount'] ?? null) !== (int) $binding['amount']) {
            throw new HttpException(403, '订单不属于当前文件账号');
        }
        $file = $this->findOwned($binding['file_id'], $user);
        $price = self::priceInCents($file->price);
        if (($order['payer']['openid'] ?? null) !== $user->openid || $price === null || $price === 0 ||
            ($attachment['amount'] ?? null) !== $price || ($order['amount']['total'] ?? null) !== $price ||
            ($order['amount']['currency'] ?? null) !== 'CNY') {
            throw new HttpException(403, '订单金额、收款方或付款账号校验失败');
        }
        File::updateAll(['unlocked' => 1], ['id' => $file->id]);
        return true;
    }
}
