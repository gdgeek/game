<?php

namespace app\components;

use app\modules\v2\models\Device;
use app\modules\v2\models\Setup;
use yii\base\Component;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\ConflictHttpException;
use yii\web\HttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\db\Query;

class AdminDeviceService extends Component
{
    public $catalog;
    public $auth;

    public function init(): void
    {
        parent::init();
        $this->catalog = $this->catalog ?? new AdminGameCatalog();
        $this->auth = $this->auth ?? new AdminAuthService();
    }

    public function getDevices(): array
    {
        $userId = $this->auth->requireDeviceUserId();
        try {
            // Never call Device::toArray with extraFields: its setup expansion writes on GET.
            return array_map([$this, 'normalize'], $this->scopedDevices($userId)->with('setup')->orderBy(['device.id' => SORT_ASC])->all());
        } catch (\Throwable $e) {
            \Yii::warning('Administrator device list is unavailable.', __METHOD__);
            throw new HttpException(503, '设备数据暂时不可用，请稍后重试');
        }
    }

    /** Read stored image sources without Device::toArray(), which can create setup. */
    public function getImageSources(int $deviceId): array
    {
        $userId = $this->auth->requireDeviceUserId();
        try {
            $device = $this->scopedDevices($userId)->andWhere(['device.id' => $deviceId])->with('setup')->one();
            if ($device === null) {
                throw new NotFoundHttpException('设备不存在');
            }
            $setup = $device->setup;
            return $setup === null ? [] : array_values(array_unique(array_merge(
                $this->normalizeContent($setup->pictures),
                $this->normalizeContent($setup->thumbs)
            )));
        } catch (\yii\web\HttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Yii::warning('Administrator device images are unavailable.', __METHOD__);
            throw new HttpException(503, '设备数据暂时不可用，请稍后重试');
        }
    }

    public function assignGame(int $deviceId, int $gameId): array
    {
        return $this->updateConfiguration($deviceId, ['gameId' => $gameId]);
    }

    public function deleteDevice(int $deviceId): array
    {
        if ($this->auth->requireDeviceUserId() !== null) {
            throw new ForbiddenHttpException('仅管理员可以删除设备');
        }

        $db = Device::getDb();
        try {
            return $db->transaction(function () use ($db, $deviceId) {
                $query = Device::find()->where(['id' => $deviceId]);
                $device = $db->driverName === 'mysql'
                    ? Device::findBySql($query->createCommand()->rawSql . ' FOR UPDATE')->one()
                    : $query->one();
                if ($device === null) {
                    throw new NotFoundHttpException('设备不存在');
                }
                // Older deployments cascade device deletion into historical game
                // records. Require the preservation migration before any deletion.
                $recordSchema = $db->getTableSchema('record');
                if ($recordSchema === null || !isset($recordSchema->columns['device_id']) || !$recordSchema->columns['device_id']->allowNull) {
                    throw new \RuntimeException('Record preservation migration is required.');
                }
                // A game still in progress needs its device/shop relationship
                // for settlement. Lock records before checking their status.
                $records = (new Query())->select(['id', 'status'])->from('record')->where(['device_id' => $deviceId]);
                $related = $db->driverName === 'mysql'
                    ? $db->createCommand($records->createCommand($db)->rawSql . ' FOR UPDATE')->queryAll()
                    : $records->all($db);
                foreach ($related as $record) {
                    if ($record['status'] !== 'finish') {
                        throw new ConflictHttpException('设备仍有未结束的游戏，请先结束游戏后再删除');
                    }
                }
                // Detach history explicitly so even an old CASCADE foreign key
                // cannot remove records. No timestamps or business fields change.
                $db->createCommand()->update('record', ['device_id' => null], ['device_id' => $deviceId])->execute();
                Setup::deleteAll(['device_id' => $deviceId]);
                $db->createCommand()->delete('control', ['device_id' => $deviceId])->execute();
                $db->createCommand()->delete('manager', ['device_id' => $deviceId])->execute();
                if ($device->delete() !== 1) {
                    throw new \RuntimeException('Device deletion did not remove its record.');
                }
                return ['id' => $deviceId, 'deleted' => true];
            });
        } catch (\yii\web\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Yii::warning('Administrator device deletion failed.', __METHOD__);
            throw new HttpException(503, '设备暂时无法删除，请稍后重试');
        }
    }

    public function updateConfiguration(int $deviceId, array $patch): array
    {
        $userId = $this->auth->requireDeviceUserId();
        $patch = $this->validateConfiguration($patch);
        // Content-only edits must also work for unassigned or retired scenes.
        if (array_key_exists('gameId', $patch)) {
            $knownIds = array_column($this->catalog->getGames(), 'id');
            if (!in_array($patch['gameId'], $knownIds, true)) {
                throw new UnprocessableEntityHttpException('请选择游戏列表中的游戏');
            }
        }

        $db = Device::getDb();
        try {
            return $db->transaction(function () use ($db, $deviceId, $patch, $userId) {
                // Lock the device row while creating/updating its setup to serialize administrators.
                $query = $this->scopedDevices($userId)->andWhere(['device.id' => $deviceId]);
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
                    $defaults = array_merge(Setup::DefaultInfo($patch['gameId'] ?? null), Setup::DefaultData());
                    // Preserve protocol defaults; TimestampBehavior supplies updated_at.
                    foreach (['title', 'pictures', 'scene_id', 'money', 'slogans', 'thumbs', 'shots'] as $field) {
                        $setup->$field = $defaults[$field];
                    }
                    $setup->device_id = $deviceId;
                }
                if (array_key_exists('gameId', $patch)) {
                    $setup->scene_id = $patch['gameId'];
                }
                foreach (['title', 'slogans', 'pictures', 'thumbs'] as $field) {
                    if (array_key_exists($field, $patch)) {
                        $setup->$field = $patch[$field];
                    }
                }
                if (!$setup->save()) {
                    throw new UnprocessableEntityHttpException('设备配置保存失败');
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
            \Yii::warning('Administrator device configuration update failed.', __METHOD__);
            throw new HttpException(503, '设备配置暂时无法保存，请稍后重试');
        }
    }

    private function validateConfiguration(array $patch): array
    {
        if ($patch === [] || array_diff(array_keys($patch), ['gameId', 'title', 'slogans', 'pictures', 'thumbs']) !== []) {
            throw new UnprocessableEntityHttpException('请仅提交需要修改的设备名称、游戏、口号、图片或缩略图');
        }
        if (array_key_exists('gameId', $patch) && (!is_int($patch['gameId']) || $patch['gameId'] <= 0)) {
            throw new UnprocessableEntityHttpException('gameId 必须为有效的游戏编号');
        }
        if (array_key_exists('title', $patch)) {
            if (!is_string($patch['title'])) {
                throw new UnprocessableEntityHttpException('title 必须为不超过 255 个字符的设备名称');
            }
            $patch['title'] = trim($patch['title']);
            if (mb_strlen($patch['title'], \Yii::$app->charset) > 255) {
                throw new UnprocessableEntityHttpException('title 必须为不超过 255 个字符的设备名称');
            }
            $db = Device::getDb();
            if ($db->driverName === 'mysql' && in_array(strtolower((string) $db->charset), ['utf8', 'utf8mb3'], true)
                && preg_match('/[\x{10000}-\x{10FFFF}]/u', $patch['title']) === 1) {
                throw new UnprocessableEntityHttpException('设备名称暂不支持表情符号，请使用文字或数字');
            }
        }
        foreach (['slogans', 'pictures', 'thumbs'] as $field) {
            if (!array_key_exists($field, $patch)) {
                continue;
            }
            $values = $patch[$field];
            if (!is_array($values) || array_values($values) !== $values) {
                throw new UnprocessableEntityHttpException($field . ' 必须为字符串列表');
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new UnprocessableEntityHttpException($field . ' 必须为字符串列表');
                }
            }
            $patch[$field] = $this->normalizeContent($values);
        }
        return $patch;
    }

    private function normalizeContent($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $result[] = trim($value);
            }
        }
        return $result;
    }

    private function scopedDevices(?int $userId): \yii\db\ActiveQuery
    {
        $query = Device::find();
        if ($userId !== null) {
            $ownedIds = (new Query())->select('device_id')->from('control')->where(['user_id' => $userId]);
            $query->andWhere(['device.id' => $ownedIds]);
        }
        return $query;
    }

    public function normalize(Device $device): array
    {
        $setup = $device->setup;
        $title = $setup !== null ? (string) $setup->title : '';
        $displayTitle = trim($title);
        return [
            'id' => (int) $device->id,
            'version' => (int) $device->version,
            'uuid' => (string) $device->uuid,
            'name' => $displayTitle !== '' && $displayTitle !== '未知'
                ? $displayTitle : (string) ($device->tag ?: ($device->uuid ?: '设备 ' . $device->id)),
            'title' => $title,
            'ip' => (string) $device->ip,
            'status' => 'unknown',
            'createdAt' => (string) $device->created_at,
            'updatedAt' => (string) (($setup !== null && $setup->updated_at) ? $setup->updated_at : $device->updated_at),
            'gameId' => $setup !== null && $setup->scene_id !== null ? (int) $setup->scene_id : null,
            'slogans' => $this->normalizeContent($setup !== null ? $setup->slogans : null),
            'pictures' => $this->normalizeContent($setup !== null ? $setup->pictures : null),
            'thumbs' => $this->normalizeContent($setup !== null ? $setup->thumbs : null),
        ];
    }
}
