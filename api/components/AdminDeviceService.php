<?php

namespace app\components;

use app\modules\v2\models\Device;
use app\modules\v2\models\Setup;
use yii\base\Component;
use yii\web\NotFoundHttpException;
use yii\web\HttpException;
use yii\web\UnprocessableEntityHttpException;

class AdminDeviceService extends Component
{
    public $catalog;

    public function init(): void
    {
        parent::init();
        $this->catalog = $this->catalog ?? new AdminGameCatalog();
    }

    public function getDevices(): array
    {
        try {
            // Never call Device::toArray with extraFields: its setup expansion writes on GET.
            return array_map([$this, 'normalize'], Device::find()->with('setup')->orderBy(['id' => SORT_ASC])->all());
        } catch (\Throwable $e) {
            \Yii::warning('Administrator device list is unavailable.', __METHOD__);
            throw new HttpException(503, '设备数据暂时不可用，请稍后重试');
        }
    }

    public function assignGame(int $deviceId, int $gameId): array
    {
        $knownIds = array_column($this->catalog->getGames(), 'id');
        if (!in_array($gameId, $knownIds, true)) {
            throw new UnprocessableEntityHttpException('请选择游戏列表中的游戏');
        }

        $db = Device::getDb();
        try {
            return $db->transaction(function () use ($db, $deviceId, $gameId) {
                // Lock the device row while creating/updating its setup to serialize administrators.
                $query = Device::find()->where(['id' => $deviceId]);
                if ($db->driverName === 'mysql') {
                    $device = Device::findBySql($query->createCommand()->rawSql . ' FOR UPDATE')->one();
                } else {
                    $device = $query->one();
                }
                if ($device === null) {
                    throw new NotFoundHttpException('设备不存在');
                }
                $setup = Setup::findOne(['device_id' => $deviceId]);
                if ($setup === null) {
                    $setup = new Setup();
                    $defaults = array_merge(Setup::DefaultInfo(), Setup::DefaultData());
                    // Keep the existing device protocol defaults on first assignment.
                    // TimestampBehavior supplies the database update time.
                    unset($defaults['updated_at']);
                    $setup->setAttributes($defaults);
                    $setup->device_id = $deviceId;
                }
                $setup->scene_id = $gameId;
                if (!$setup->save()) {
                    throw new UnprocessableEntityHttpException('设备游戏设置保存失败');
                }
                // TimestampBehavior leaves a NOW() Expression on the saved model.
                // Reload the persisted row before returning configuration metadata.
                if (!$setup->refresh()) {
                    throw new \RuntimeException('Saved device configuration is unavailable.');
                }
                $device->populateRelation('setup', $setup);
                return $this->normalize($device);
            });
        } catch (\yii\web\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Yii::warning('Administrator device game update failed.', __METHOD__);
            throw new HttpException(503, '设备游戏设置暂时无法保存，请稍后重试');
        }
    }

    public function normalize(Device $device): array
    {
        $setup = $device->setup;
        return [
            'id' => (int) $device->id,
            'uuid' => (string) $device->uuid,
            'name' => (string) ($device->tag ?: ($device->uuid ?: '设备 ' . $device->id)),
            'ip' => (string) $device->ip,
            'status' => 'unknown',
            'updatedAt' => (string) (($setup !== null && $setup->updated_at) ? $setup->updated_at : $device->updated_at),
            'gameId' => $setup !== null && $setup->scene_id !== null ? (int) $setup->scene_id : null,
        ];
    }
}
