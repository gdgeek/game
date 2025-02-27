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
    // public AwardType $award ;
   // public int $status;
    public $secodes = 60;
    public $points = 0;
    public function __construct($points, $secodes)
    {
       // $this->award = new AwardType();
        //$this->status = $status;
        $this->secodes = $secodes;
        $this->points = $points;
    }

}
