<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "device".
 *
 * @property int $id
 * @property int $shop_id
 * @property int|null $status
 *
 * @property Record[] $records
 * @property Shop $shop
 */
class Award 
{
    public int $points = 100;
    public int $s = 0;
    public int $m = 0;
    public int $l = 0;
    public int $xl = 0;
    
}
