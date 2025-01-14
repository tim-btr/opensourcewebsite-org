<?php

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\Controller;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\components\actions\privates\wordlist\WordlistComponent;
use app\modules\bot\models\Chat;
use app\modules\bot\models\ChatSetting;
use app\modules\bot\models\BotChatFaqQuestion;
use yii\data\Pagination;
use app\modules\bot\components\helpers\Emoji;

/**
 * Class GroupGuestFaqController
 *
 * @package app\modules\bot\controllers\privates
 */
class GroupGuestFaqController extends Controller
{
    public function actions()
    {
        return array_merge(
            parent::actions(),
            Yii::createObject([
                'class' => WordlistComponent::class,
                'wordModelClass' => BotChatFaqQuestion::class,
                'options' => [
                    'actions' => [
                        'insert' => false,
                        'update' => false,
                        'delete' => false,
                    ],
                    'listBackRoute' => [
                        'controller' => 'app\modules\bot\controllers\privates\GroupGuestController',
                        'action' => 'view',
                    ],
                ]
            ])->actions()
        );
    }

    /**
     * @return array
     */
    public function actionIndex($chatId = null)
    {
        $chat = Chat::findOne($chatId);

        if (!isset($chat)) {
            return [];
        }

        return $this->run('group-guest/view', [
            'chatId' => $chat->id,
        ]);
    }
}
