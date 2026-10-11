<?php

namespace app\modules\v2\helper;

use app\modules\v2\models\Device;
use app\modules\v2\models\Setup;
use app\modules\v2\models\User;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Query;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;
use yii\web\UnprocessableEntityHttpException;

/** Every WeChat identity, including legacy root accounts, is control-scoped. */
class DeviceAccess
{
    public static function scope(ActiveQuery $query, string $deviceColumn): ActiveQuery
    {
        $identity = Yii::$app->user->identity;
        if (!$identity instanceof User) { throw new UnauthorizedHttpException('请重新登录微信'); }
        $deviceIds = (new Query())->select('device_id')->from('control')->where(['user_id' => $identity->id]);
        return $query->andWhere([$deviceColumn => $deviceIds]);
    }

    public static function device($id): Device
    {
        $device = self::scope(Device::find(), 'device.id')->andWhere(['device.id' => $id])->one();
        if ($device === null) { throw new NotFoundHttpException('设备不存在或未关联'); }
        return $device;
    }

    public static function setup($id): Setup
    {
        $setup = self::scope(Setup::find(), 'setup.device_id')->andWhere(['setup.id' => $id])->one();
        if ($setup === null) { throw new NotFoundHttpException('设备配置不存在或未关联'); }
        return $setup;
    }

    public static function bodyDeviceId($value): int
    {
        if (!(is_int($value) && $value > 0) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw new UnprocessableEntityHttpException('device_id 必须为有效设备编号');
        }
        return (int) $value;
    }

    public static function denyAdministration(): void
    {
        throw new ForbiddenHttpException('微信登录仅可配置关联设备，不可创建、删除或分配设备');
    }
}
