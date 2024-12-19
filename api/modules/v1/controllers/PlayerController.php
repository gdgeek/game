<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;
class PlayerController extends ActiveController
{
  public $modelClass = 'app\modules\v1\models\Player';
}