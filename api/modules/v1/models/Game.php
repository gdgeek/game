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
class Game 
{
    public Award $award ;
    public int $status ;
    public $secodes = 60;
    public function __construct()
    {
        $this->award = new Award();
    }

}
